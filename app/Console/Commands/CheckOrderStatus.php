<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ApiProvider;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckOrderStatus extends Command
{
    protected $signature = 'orders:check-status {--limit=100 : Number of orders to check}';
    protected $description = 'Check and update order status from provider API';

    public function handle()
    {
        $this->info('🔍 Checking order statuses...');
        
        $limit = (int) $this->option('limit');
        
        // Get all pending/in-progress/partial orders with API order IDs
        $orders = Order::whereIn('status', ['pending', 'processing', 'in-progress', 'partial'])
            ->whereNotNull('api_order_id')
            ->with(['service.provider'])
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders to check.');
            return 0;
        }

        $this->info("📦 Found {$orders->count()} orders to check");

        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $status = $this->checkProviderStatus($order);
                
                if ($status) {
                    $oldStatus = $order->status;
                    
                    $order->status = $status['status'] ?? $order->status;
                    $order->remains = $status['remains'] ?? $order->remains;
                    $order->start_counter = $status['start_count'] ?? $order->start_counter;
                    
                    if (isset($status['status_description'])) {
                        $order->status_description = $status['status_description'];
                    }
                    
                    // If completed, set remains to 0
                    if ($order->status === 'completed') {
                        $order->remains = 0;
                    }

                    // Refund user when provider marks order as cancelled or failed
                    $refundStatuses = ['cancelled', 'failed'];
                    if (
                        in_array($order->status, $refundStatuses) &&
                        !in_array($oldStatus, $refundStatuses) &&
                        $oldStatus !== 'refunded' &&
                        $order->price > 0 &&
                        $order->user_id
                    ) {
                        $order->load('user');
                        if ($order->user) {
                            $order->user->increment('balance', $order->price);

                            Transaction::create([
                                'user_id'          => $order->user_id,
                                'transaction_id'   => 'REFUND_' . time() . '_' . $order->id,
                                'transaction_type' => 'Credit',
                                'amount'           => $order->price,
                                'charge'           => 0,
                                'description'      => "Refund for {$order->status} Order #{$order->id}",
                                'status'           => 'completed',
                                'meta'             => json_encode([
                                    'order_id' => $order->id,
                                    'reason'   => 'Provider marked order as ' . $order->status,
                                ]),
                            ]);

                            $order->status = 'refunded';
                            $this->line("  💰 Order #{$order->id}: refunded {$order->price} to user #{$order->user_id}");
                        }
                    }

                    $order->save();
                    
                    if ($oldStatus !== $order->status) {
                        $updated++;
                        $this->line("  ✓ Order #{$order->id}: {$oldStatus} → {$order->status}");
                        
                        // Create notification for user
                        if ($order->user_id && $order->status === 'completed') {
                            $this->createNotification($order, 'Order completed', "Your order #{$order->id} has been completed successfully!");
                        }
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Error checking order #{$order->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->error("  ✗ Failed to check order #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Updated: {$updated} orders | Failed: {$failed}");
        return 0;
    }

    private function checkProviderStatus(Order $order)
    {
        $provider = ApiProvider::where('id', optional($order->service)->api_provider_id)
            ->where('status', 1)
            ->first();

        if (!$provider) {
            // Fallback: try any active provider
            $provider = ApiProvider::where('status', 1)->first();
        }

        if (!$provider) {
            $this->warn('  ⚠ No active provider found');
            return null;
        }

        try {
            // Standard SMM panel API format (same as order creation)
            $response = Http::timeout(30)
                ->asForm()
                ->post($provider->url, [
                    'key'    => $provider->api_key,
                    'action' => 'status',
                    'order'  => $order->api_order_id,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Provider returned an error field
                if (isset($data['error'])) {
                    Log::warning("Provider returned error for order #{$order->id}", [
                        'error' => $data['error']
                    ]);
                    return null;
                }

                // Map provider response to our status format
                $status = $this->mapProviderStatus($data['status'] ?? 'processing');

                return [
                    'status'             => $status,
                    'remains'            => $data['remains'] ?? $order->remains,
                    'start_count'        => $data['start_count'] ?? $data['start_counter'] ?? $order->start_counter,
                    'status_description' => $data['status_description'] ?? $data['status'] ?? null,
                ];
            }

            Log::warning("Provider API returned HTTP error for order #{$order->id}", [
                'http_status' => $response->status(),
                'response'    => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Provider API exception for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function mapProviderStatus($providerStatus)
    {
        $statusMap = [
            'pending'     => 'pending',
            'processing'  => 'processing',
            'in_progress' => 'in-progress',
            'in-progress' => 'in-progress',
            'in progress' => 'in-progress',
            'inprogress'  => 'in-progress',
            'partial'     => 'partial',
            'completed'   => 'completed',
            'canceled'    => 'cancelled',
            'cancelled'   => 'cancelled',
            'refunded'    => 'refunded',
            'failed'      => 'failed',
        ];

        $status = strtolower($providerStatus);
        return $statusMap[$status] ?? 'processing';
    }

    private function createNotification(Order $order, $title, $message)
    {
        try {
            \App\Models\GeneralNotification::create([
                'user_id' => $order->user_id,
                'type' => 'order',
                'title' => $title,
                'message' => $message,
                'is_read' => false
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create notification for order #{$order->id}", [
                'error' => $e->getMessage()
            ]);
        }
    }
}


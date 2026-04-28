<?php

namespace App\Console\Commands;

use App\Mail\WalletFundedMail;
use App\Models\Transaction;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RecoverPendingPayments extends Command
{
    protected $signature = 'payments:recover-pending
                            {--minutes=30 : Only check transactions older than this many minutes}
                            {--dry-run    : Run without making changes}';

    protected $description = 'Re-verify pending Kora/Flutterwave payments and credit users if confirmed';

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $minutes = (int) $this->option('minutes');

        $this->info("🔍 Recovering pending payments older than {$minutes} minutes…");

        if ($dryRun) {
            $this->warn('⚠  DRY RUN — no changes will be saved');
        }

        $pending = Transaction::where('status', 'pending')
            ->whereIn('payment_method', ['kora', 'flutterwave', 'paystack'])
            ->where('transaction_type', 'deposit')
            ->where('created_at', '<=', Carbon::now()->subMinutes($minutes))
            ->with('user')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('✅ No pending transactions to check.');
            return 0;
        }

        $this->info("📦 Found {$pending->count()} pending transaction(s)");

        $recovered = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ($pending as $transaction) {
            $this->line("  🔄 Checking #{$transaction->id} ({$transaction->payment_method}) — {$transaction->transaction_id}");

            try {
                $credited = match ($transaction->payment_method) {
                    'kora'         => $this->recoverKora($transaction, $dryRun),
                    'flutterwave'  => $this->recoverFlutterwave($transaction, $dryRun),
                    'paystack'     => $this->recoverPaystack($transaction, $dryRun),
                    default        => false,
                };

                if ($credited === true) {
                    $recovered++;
                    $this->line("    ✅ Credited {$transaction->amount} to user #{$transaction->user_id}");
                } elseif ($credited === false) {
                    $failed++;
                    $this->line("    ❌ Payment not confirmed by gateway — marked failed");
                } else {
                    $skipped++;
                    $this->line("    ⏭  Skipped (already processed or gateway error)");
                }
            } catch (\Exception $e) {
                $skipped++;
                Log::warning("payments:recover-pending — error on #{$transaction->id}: " . $e->getMessage());
                $this->warn("    ⚠  Error: " . $e->getMessage());
            }
        }

        $this->info("Done — recovered: {$recovered}, failed: {$failed}, skipped: {$skipped}");

        return 0;
    }

    // -------------------------------------------------------------------------

    private function recoverKora(Transaction $transaction, bool $dryRun): ?bool
    {
        $secretKey = config('services.korapay.secret_key');
        if (empty($secretKey)) {
            return null;
        }

        $client   = new Client();
        $response = $client->get(
            "https://api.korapay.com/merchant/api/v1/charges/{$transaction->transaction_id}",
            ['headers' => ['Authorization' => 'Bearer ' . $secretKey]]
        );

        $body       = json_decode((string) $response->getBody(), true);
        $koraStatus = strtolower($body['data']['status'] ?? '');
        $amountPaid = $body['data']['amount'] ?? 0;

        if ($koraStatus !== 'success') {
            if (!$dryRun) {
                $transaction->update(['status' => 'failed']);
            }
            return false;
        }

        if (abs($amountPaid - $transaction->amount) > 0.01) {
            Log::warning("payments:recover-pending — Kora amount mismatch on #{$transaction->id}", [
                'paid'     => $amountPaid,
                'expected' => $transaction->amount,
            ]);
            if (!$dryRun) {
                $transaction->update(['status' => 'failed']);
            }
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $affected = Transaction::where('id', $transaction->id)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'completed',
                'meta'   => json_encode($body['data']),
            ]);

        if ($affected === 0) {
            return null; // race condition — already completed
        }

        $this->creditUser($transaction);

        return true;
    }

    private function recoverFlutterwave(Transaction $transaction, bool $dryRun): ?bool
    {
        $secretKey = config('services.flutterwave.secret_key');
        if (empty($secretKey)) {
            return null;
        }

        $client   = new Client();
        $response = $client->get(
            "https://api.flutterwave.com/v3/transactions/{$transaction->transaction_id}/verify",
            ['headers' => ['Authorization' => 'Bearer ' . $secretKey]]
        );

        $body   = json_decode((string) $response->getBody(), true);
        $status = strtolower($body['data']['status'] ?? '');

        if ($status !== 'successful') {
            if (!$dryRun) {
                $transaction->update(['status' => 'failed']);
            }
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $affected = Transaction::where('id', $transaction->id)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'completed',
                'meta'   => json_encode($body['data']),
            ]);

        if ($affected === 0) {
            return null;
        }

        $this->creditUser($transaction);

        return true;
    }

    private function recoverPaystack(Transaction $transaction, bool $dryRun): ?bool
    {
        $secretKey = config('services.paystack.secret_key');
        if (empty($secretKey)) {
            return null;
        }

        $client   = new Client();
        $response = $client->get(
            "https://api.paystack.co/transaction/verify/{$transaction->transaction_id}",
            ['headers' => ['Authorization' => 'Bearer ' . $secretKey]]
        );

        $body   = json_decode((string) $response->getBody(), true);
        $status = strtolower($body['data']['status'] ?? '');

        if ($status !== 'success') {
            if (!$dryRun) {
                $transaction->update(['status' => 'failed']);
            }
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $affected = Transaction::where('id', $transaction->id)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'completed',
                'meta'   => json_encode($body['data']),
            ]);

        if ($affected === 0) {
            return null;
        }

        $this->creditUser($transaction);

        return true;
    }

    private function creditUser(Transaction $transaction): void
    {
        $transaction->refresh();
        $user = $transaction->user;

        if (!$user) {
            return;
        }

        $user->increment('balance', $transaction->amount);

        Log::info('💰 payments:recover-pending — balance credited', [
            'user_id'    => $user->id,
            'amount'     => $transaction->amount,
            'tx_id'      => $transaction->id,
        ]);

        // Notify user
        \App\Models\GeneralNotification::create([
            'user_id'  => $user->id,
            'type'     => 'payment',
            'title'    => 'Wallet Funded',
            'message'  => "Your payment of {$transaction->amount} {$transaction->currency} has been confirmed and credited to your wallet.",
            'is_read'  => false,
        ]);

        try {
            Mail::to($user->email)->send(new WalletFundedMail($transaction, $user));
        } catch (\Exception $e) {
            Log::warning('payments:recover-pending — email failed: ' . $e->getMessage());
        }
    }
}

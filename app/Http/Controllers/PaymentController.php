<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Models\AffiliateProgram;
use App\Models\AffiliateReferral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\SiteSetting;

class PaymentController extends Controller
{
    /**
     * Initiate a payment (API endpoint).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiatePayment(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:flutterwave,paystack,kora',
        ]);

        // Check if this payment method is enabled by admin
        $enabledKey = 'payment_' . $validated['payment_method'] . '_enabled';
        if (SiteSetting::get($enabledKey, '1') !== '1') {
            return response()->json([
                'success' => false,
                'message' => 'This payment method is currently unavailable.',
            ], 400);
        }

        // Validate payment method configuration
        if ($validated['payment_method'] === 'flutterwave' && empty(config('services.flutterwave.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Flutterwave payment is not properly configured',
            ], 500);
        }

        if ($validated['payment_method'] === 'paystack' && empty(config('services.paystack.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Paystack payment is not properly configured',
            ], 500);
        }

        if ($validated['payment_method'] === 'kora' && empty(config('services.korapay.secret_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Korapay payment is not properly configured',
            ], 500);
        }

        try {
            $transactionRef = 'TX_' . uniqid();

            $payment = Transaction::create([
                'user_id' => Auth::id(),
                'transaction_id' => $transactionRef,
                'amount' => $validated['amount'],
                'currency' => Auth::user()->currency ?? 'NGN',
                'charge' => 0.00,
                'transaction_type' => 'deposit',
                'description' => 'Payment via ' . ucfirst($validated['payment_method']),
                'status' => 'pending',
                'payment_method' => $validated['payment_method'],
            ]);

            $paymentData = [
                'tx_ref' => $transactionRef,
                'amount' => $validated['amount'],
                'currency' => Auth::user()->currency ?? 'NGN',
                'redirect_url' => rtrim(config('app.frontend_url'), '/') . '/payment/callback',
                'customer' => [
                    'email' => Auth::user()->email,
                    'name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                ],
                'payment_options' => 'card',
                'meta' => [
                    'user_id' => Auth::id(),
                    'transaction_id' => $payment->id,
                ],
            ];

            $paymentURL = $this->createPaymentLink($validated['payment_method'], $paymentData);

            if (!$paymentURL) {
                throw new \Exception('Failed to generate payment link');
            }

            return response()->json([
                'success' => true,
                'payment_url' => $paymentURL,
                'transaction_id' => $transactionRef,
                'message' => 'Payment initiated successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Payment initiation failed: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment callback from payment gateway (Webhook).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleCallback(Request $request)
    {
        try {
            Log::info('🔄 Payment callback received', ['request' => $request->all()]);

            $transactionId = $request->input('transaction_id');
            $txRef = $request->input('tx_ref');
            $status = $request->input('status');

            // Use either transaction_id or tx_ref
            $reference = $transactionId ?? $txRef;

            if (!$reference) {
                throw new \Exception('Missing transaction reference');
            }

            // Find the transaction by either field
            $payment = Transaction::where('transaction_id', $reference)
                ->orWhere('meta->tx_ref', $reference)
                ->first();

            if (!$payment) {
                Log::error('❌ Transaction not found for reference: ' . $reference);
                throw new \Exception('Transaction not found for reference: ' . $reference);
            }

            // If already completed, return success
            if ($payment->status === 'completed') {
                Log::info('✅ Payment already completed', ['transaction_id' => $payment->id]);
                return response()->json([
                    'success' => true,
                    'payment' => $payment,
                    'message' => 'Payment already completed',
                ]);
            }

            $normalizedStatus = strtolower($status);

            // Handle Flutterwave payment verification
            if ($payment->payment_method === 'flutterwave') {
                if ($normalizedStatus === 'successful' || $normalizedStatus === 'completed') {
                    $verification = $this->verifyFlutterwavePayment($reference);

                    if (!$verification || $verification['status'] !== 'success') {
                        Log::error('❌ Flutterwave verification failed', ['response' => $verification]);
                        throw new \Exception('Payment verification failed');
                    }

                    // Check if payment is actually successful in Flutterwave
                    $flutterwaveStatus = strtolower($verification['data']['status'] ?? '');
                    $amountPaid = $verification['data']['amount'] ?? 0;
                    $expectedAmount = $payment->amount;

                    Log::info('🔍 Flutterwave verification result', [
                        'flutterwave_status' => $flutterwaveStatus,
                        'amount_paid' => $amountPaid,
                        'expected_amount' => $expectedAmount
                    ]);

                    if ($flutterwaveStatus !== 'successful') {
                        throw new \Exception('Payment not confirmed by Flutterwave. Status: ' . $flutterwaveStatus);
                    }

                    // Verify amount matches (with small tolerance for floating point)
                    if (abs($amountPaid - $expectedAmount) > 0.01) {
                        throw new \Exception('Payment amount mismatch. Paid: ' . $amountPaid . ', Expected: ' . $expectedAmount);
                    }

                    // Update transaction
                    $payment->update([
                        'transaction_id' => $verification['data']['id'] ?? $reference,
                        'status' => 'completed',
                        'payment_method' => $verification['data']['payment_type'] ?? $payment->payment_method,
                        'meta' => json_encode($verification['data'] ?? []),
                    ]);

                    // Credit user's balance
                    $user = $payment->user;
                    $user->balance += $payment->amount;
                    $user->save();

                    Log::info('💰 Payment completed successfully', [
                        'transaction_id' => $payment->id,
                        'user_id' => $user->id,
                        'amount' => $payment->amount,
                        'new_balance' => $user->balance
                    ]);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Payment failed', ['transaction_id' => $payment->id]);
                } else {
                    $payment->update(['status' => 'pending']);
                    Log::info('⏳ Payment pending', ['transaction_id' => $payment->id, 'status' => $normalizedStatus]);
                }
            } elseif ($payment->payment_method === 'kora') {
                // Handle Korapay redirect callback — verify server-side
                if (in_array($normalizedStatus, ['success', 'successful', 'completed'])) {
                    $verification = $this->verifyKoraPayment($reference);
                    $koraStatus   = strtolower($verification['data']['status'] ?? '');
                    $amountPaid   = $verification['data']['amount'] ?? 0;

                    if ($koraStatus !== 'success') {
                        throw new \Exception('Payment not confirmed by Korapay. Status: ' . $koraStatus);
                    }

                    if (abs($amountPaid - $payment->amount) > 0.01) {
                        throw new \Exception('Korapay amount mismatch. Paid: ' . $amountPaid . ', Expected: ' . $payment->amount);
                    }

                    $payment->update([
                        'status' => 'completed',
                        'meta'   => json_encode($verification['data']),
                    ]);

                    $user = $payment->user;
                    $user->increment('balance', $payment->amount);
                    $this->calculateAffiliateCommission($user, $payment->amount);

                    Log::info('💰 Korapay payment completed via callback', [
                        'transaction_id' => $payment->id,
                        'user_id'        => $user->id,
                        'amount'         => $payment->amount,
                    ]);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                } else {
                    $payment->update(['status' => 'pending']);
                }
            } else {
                // For Paystack and other payment methods
                if ($normalizedStatus === 'successful' || $normalizedStatus === 'completed') {
                    $payment->update(['status' => 'completed']);

                    // Credit user's balance
                    $user = $payment->user;
                    $user->balance += $payment->amount;
                    $user->save();

                    Log::info('💰 Payment completed successfully', [
                        'transaction_id' => $payment->id,
                        'user_id' => $user->id,
                        'amount' => $payment->amount,
                        'new_balance' => $user->balance
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($user, $payment->amount);
                } elseif (in_array($normalizedStatus, ['cancelled', 'failed'])) {
                    $payment->update(['status' => 'failed']);
                    Log::info('❌ Payment failed', ['transaction_id' => $payment->id]);
                } else {
                    $payment->update(['status' => 'pending']);
                    Log::info('⏳ Payment pending', ['transaction_id' => $payment->id, 'status' => $normalizedStatus]);
                }
            }

            return response()->json([
                'success' => true,
                'payment' => $payment,
                'message' => 'Payment status updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Payment callback failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment from frontend (API endpoint).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request)
    {
        try {
            Log::info('🔍 Frontend payment verification request', ['request' => $request->all()]);

            $validated = $request->validate([
                'transaction_id' => 'required|string',
                'status' => 'required|string|in:successful,completed,success,failed,cancelled,pending',
            ]);

            // 🔎 Flexible transaction lookup
            $transaction = Transaction::where('transaction_id', $validated['transaction_id'])
                ->orWhere('meta->transaction_id', $validated['transaction_id'])
                ->orWhere('meta->id', $validated['transaction_id'])
                ->orWhere('meta->tx_ref', $validated['transaction_id'])
                ->first();

            if (!$transaction) {
                Log::error('❌ Transaction not found', [
                    'transaction_id' => $validated['transaction_id'],
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found',
                ], 404);
            }

            // ✅ If already completed, return existing data
            if ($transaction->status === 'completed') {
                Log::info('✅ Payment already verified', ['transaction_id' => $transaction->id]);
                return response()->json([
                    'success' => true,
                    'data' => $transaction,
                    'message' => 'Payment already verified',
                ]);
            }

            Log::info('🔄 Processing payment verification', [
                'transaction_id' => $transaction->id,
                'current_status' => $transaction->status,
                'requested_status' => $validated['status']
            ]);

            // 🔍 Verify via Flutterwave if applicable
            if (
                $transaction->payment_method === 'flutterwave' &&
                in_array($validated['status'], ['successful', 'completed'])
            ) {
                $verification = $this->verifyFlutterwavePayment($validated['transaction_id']);

                if ($verification && $verification['status'] === 'success') {
                    $flutterwaveStatus = strtolower($verification['data']['status'] ?? '');

                    Log::info('🔍 Flutterwave verification result', [
                        'flutterwave_status' => $flutterwaveStatus,
                        'transaction_id' => $validated['transaction_id']
                    ]);

                    if ($flutterwaveStatus === 'successful') {
                        $transaction->update([
                            'status' => 'completed',
                            'meta' => json_encode($verification['data']),
                        ]);

                        // 🏦 Safely credit user balance
                        if ($transaction->user) {
                            $transaction->user->increment('balance', $transaction->amount);
                            Log::info('💰 User balance credited', [
                                'user_id' => $transaction->user_id,
                                'amount' => $transaction->amount,
                                'new_balance' => $transaction->user->balance
                            ]);

                            // 💰 Calculate and credit affiliate commission
                            $this->calculateAffiliateCommission($transaction->user, $transaction->amount);
                        }
                    } else {
                        $transaction->update(['status' => 'failed']);
                        Log::info('❌ Flutterwave payment failed', [
                            'transaction_id' => $transaction->id,
                            'flutterwave_status' => $flutterwaveStatus
                        ]);
                    }
                } else {
                    $transaction->update(['status' => 'failed']);
                    Log::error('❌ Flutterwave verification failed', [
                        'transaction_id' => $transaction->id,
                        'verification_response' => $verification
                    ]);
                }
            } elseif (
                $transaction->payment_method === 'kora' &&
                in_array($validated['status'], ['successful', 'completed', 'success'])
            ) {
                // Verify with Korapay API
                $verification = $this->verifyKoraPayment($transaction->transaction_id);
                $koraStatus   = strtolower($verification['data']['status'] ?? '');

                if ($koraStatus === 'success') {
                    $transaction->update([
                        'status' => 'completed',
                        'meta'   => json_encode($verification['data']),
                    ]);

                    if ($transaction->user) {
                        $transaction->user->increment('balance', $transaction->amount);
                        $this->calculateAffiliateCommission($transaction->user, $transaction->amount);

                        Log::info('💰 Korapay balance credited (verify endpoint)', [
                            'user_id' => $transaction->user_id,
                            'amount'  => $transaction->amount,
                        ]);
                    }
                } else {
                    $transaction->update(['status' => 'failed']);
                    Log::info('❌ Korapay payment not confirmed', ['kora_status' => $koraStatus]);
                }
            } else {
                // Other payment methods
                $newStatus = in_array($validated['status'], ['successful', 'completed'])
                    ? 'completed' : 'failed';

                $transaction->update(['status' => $newStatus]);

                if ($newStatus === 'completed' && $transaction->user) {
                    $transaction->user->increment('balance', $transaction->amount);
                    Log::info('💰 Balance credited (direct update)', [
                        'transaction_id' => $transaction->id,
                        'user_id' => $transaction->user_id,
                        'amount' => $transaction->amount
                    ]);

                    // 💰 Calculate and credit affiliate commission
                    $this->calculateAffiliateCommission($transaction->user, $transaction->amount);
                }
            }

            $transaction->refresh();

            return response()->json([
                'success' => true,
                'data' => $transaction,
                'message' => 'Payment verified successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Payment verification failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Create payment link based on payment method.
     *
     * @param string $method
     * @param array $data
     * @return string|null
     */
    private function createPaymentLink(string $method, array $data): ?string
    {
        switch ($method) {
            case 'flutterwave':
                return $this->createFlutterwavePaymentLink($data);
            case 'paystack':
                return $this->createPaystackPaymentLink($data);
            case 'kora':
                return $this->createKoraPaymentLink($data);
            default:
                throw new \InvalidArgumentException("Unsupported payment method: {$method}");
        }
    }

    /**
     * Create a Flutterwave payment link.
     *
     * @param array $data
     * @return string|null
     */
    private function createFlutterwavePaymentLink(array $data): ?string
    {
        try {
            $flutterwaveKey = config('services.flutterwave.secret_key');

            if (empty($flutterwaveKey)) {
                throw new \RuntimeException('Flutterwave secret key is not configured');
            }

            Log::debug('🔗 Creating Flutterwave payment link', ['data' => $data]);

            $client = new Client();
            $response = $client->post('https://api.flutterwave.com/v3/payments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $flutterwaveKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            $body = json_decode((string)$response->getBody(), true);

            if (!isset($body['status']) || $body['status'] !== 'success') {
                Log::error('❌ Flutterwave payment failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Flutterwave payment failed');
            }

            Log::info('✅ Flutterwave payment link created', [
                'transaction_ref' => $data['tx_ref'],
                'payment_url' => $body['data']['link'] ?? null
            ]);

            return $body['data']['link'] ?? null;
        } catch (\Exception $e) {
            Log::error('💥 Flutterwave payment error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a Paystack payment link.
     *
     * @param array $data
     * @return string|null
     */
    private function createPaystackPaymentLink(array $data): ?string
    {
        try {
            $paystackKey = config('services.paystack.secret_key');

            if (empty($paystackKey)) {
                throw new \RuntimeException('Paystack secret key is not configured');
            }

            $client = new Client();
            $response = $client->post('https://api.paystack.co/transaction/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $paystackKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => $data['customer']['email'],
                    'amount' => $data['amount'] * 100, // Paystack uses kobo
                    'reference' => $data['tx_ref'],
                    'callback_url' => $data['redirect_url'],
                    'metadata' => $data['meta'],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!$body['status']) {
                Log::error('❌ Paystack payment failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Paystack payment failed');
            }

            Log::info('✅ Paystack payment link created', [
                'transaction_ref' => $data['tx_ref'],
                'payment_url' => $body['data']['authorization_url'] ?? null
            ]);

            return $body['data']['authorization_url'] ?? null;
        } catch (\Exception $e) {
            Log::error('💥 Paystack payment error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle Korapay webhook events.
     * Called directly by Korapay — no auth middleware, signature verified below.
     */
    public function handleKoraWebhook(Request $request)
    {
        try {
            $encryptionKey = config('services.korapay.encryption_key');
            $signature     = $request->header('x-korapay-signature');
            $rawBody       = $request->getContent();

            // Verify HMAC-SHA256 signature
            if ($encryptionKey && $signature) {
                $expected = hash_hmac('sha256', $rawBody, $encryptionKey);
                if (!hash_equals($expected, $signature)) {
                    Log::warning('❌ Korapay webhook signature mismatch');
                    return response()->json(['message' => 'Invalid signature'], 401);
                }
            }

            $payload = json_decode($rawBody, true);
            $event   = $payload['event'] ?? null;
            $data    = $payload['data']  ?? [];

            Log::info('🔔 Korapay webhook received', ['event' => $event, 'reference' => $data['reference'] ?? null]);

            if ($event !== 'charge.success') {
                return response()->json(['message' => 'Event ignored'], 200);
            }

            $reference = $data['reference'] ?? null;
            if (!$reference) {
                return response()->json(['message' => 'Missing reference'], 400);
            }

            $transaction = Transaction::where('transaction_id', $reference)->first();

            if (!$transaction) {
                Log::error('❌ Korapay webhook: transaction not found', ['reference' => $reference]);
                return response()->json(['message' => 'Transaction not found'], 404);
            }

            if ($transaction->status === 'completed') {
                return response()->json(['message' => 'Already processed'], 200);
            }

            // Verify the charge server-side before crediting
            $verification = $this->verifyKoraPayment($reference);

            if (!$verification || ($verification['data']['status'] ?? '') !== 'success') {
                Log::error('❌ Korapay webhook: verification failed', ['reference' => $reference]);
                $transaction->update(['status' => 'failed']);
                return response()->json(['message' => 'Verification failed'], 200);
            }

            $amountPaid    = $verification['data']['amount'] ?? 0;
            $expectedAmount = $transaction->amount;

            if (abs($amountPaid - $expectedAmount) > 0.01) {
                Log::error('❌ Korapay amount mismatch', [
                    'paid'     => $amountPaid,
                    'expected' => $expectedAmount,
                ]);
                $transaction->update(['status' => 'failed']);
                return response()->json(['message' => 'Amount mismatch'], 200);
            }

            $transaction->update([
                'status' => 'completed',
                'meta'   => json_encode($verification['data']),
            ]);

            $user = $transaction->user;
            if ($user) {
                $user->increment('balance', $transaction->amount);
                $this->calculateAffiliateCommission($user, $transaction->amount);

                Log::info('💰 Korapay payment credited via webhook', [
                    'user_id'     => $user->id,
                    'amount'      => $transaction->amount,
                    'new_balance' => $user->fresh()->balance,
                ]);
            }

            return response()->json(['message' => 'Webhook processed'], 200);

        } catch (\Exception $e) {
            Log::error('💥 Korapay webhook error: ' . $e->getMessage());
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    /**
     * Create a Korapay checkout link.
     */
    private function createKoraPaymentLink(array $data): ?string
    {
        try {
            $secretKey = config('services.korapay.secret_key');

            if (empty($secretKey)) {
                throw new \RuntimeException('Korapay secret key is not configured');
            }

            $backendUrl  = rtrim(config('app.url'), '/');
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            $client   = new Client();
            $response = $client->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'amount'           => $data['amount'],
                    'currency'         => $data['currency'] ?? 'NGN',
                    'reference'        => $data['tx_ref'],
                    'customer'         => [
                        'name'  => $data['customer']['name'],
                        'email' => $data['customer']['email'],
                    ],
                    'notification_url' => $backendUrl . '/api/payment/kora/webhook',
                    'redirect_url'     => $frontendUrl . '/payment/callback',
                    'channels'         => ['card', 'bank_transfer'],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (!($body['status'] ?? false)) {
                throw new \RuntimeException($body['message'] ?? 'Korapay payment initialization failed');
            }

            Log::info('✅ Korapay checkout link created', ['reference' => $data['tx_ref']]);

            return $body['data']['checkout_url'] ?? null;

        } catch (\Exception $e) {
            Log::error('💥 Korapay payment link error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify a Korapay charge by reference.
     */
    private function verifyKoraPayment(string $reference): ?array
    {
        try {
            $secretKey = config('services.korapay.secret_key');

            if (empty($secretKey)) {
                throw new \RuntimeException('Korapay secret key is not configured');
            }

            $client   = new Client();
            $response = $client->get("https://api.korapay.com/merchant/api/v1/charges/{$reference}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (!($body['status'] ?? false)) {
                throw new \RuntimeException($body['message'] ?? 'Korapay verification failed');
            }

            Log::info('✅ Korapay payment verified', [
                'reference' => $reference,
                'status'    => $body['data']['status'] ?? 'unknown',
            ]);

            return $body;

        } catch (\Exception $e) {
            Log::error('💥 Korapay verification error: ' . $e->getMessage(), ['reference' => $reference]);
            throw $e;
        }
    }

    /**
     * Verify Flutterwave payment.
     *
     * @param string $transactionId
     * @return array|null
     */
    private function verifyFlutterwavePayment(string $transactionId): ?array
    {
        try {
            $flutterwaveKey = config('services.flutterwave.secret_key');

            if (empty($flutterwaveKey)) {
                throw new \RuntimeException('Flutterwave secret key is not configured');
            }

            Log::debug('🔍 Verifying Flutterwave payment', ['transaction_id' => $transactionId]);

            $client = new Client();
            $response = $client->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $flutterwaveKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!isset($body['status']) || $body['status'] !== 'success') {
                Log::error('❌ Flutterwave verification failed', ['response' => $body]);
                throw new \RuntimeException($body['message'] ?? 'Payment verification failed');
            }

            Log::info('✅ Flutterwave payment verified successfully', [
                'transaction_id' => $transactionId,
                'status' => $body['data']['status'] ?? 'unknown'
            ]);

            return $body;
        } catch (\Exception $e) {
            Log::error('💥 Flutterwave verification error: ' . $e->getMessage(), [
                'transaction_id' => $transactionId
            ]);
            throw $e;
        }
    }

    /**
     * Get payment history for authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function paymentHistory()
    {
        try {
            $transactions = Transaction::where('user_id', Auth::id())
                ->latest()
                ->get();

            Log::info('📊 Fetched payment history', [
                'user_id' => Auth::id(),
                'transaction_count' => $transactions->count()
            ]);

            return response()->json($transactions);
        } catch (\Exception $e) {
            Log::error('💥 Payment history fetch failed: ' . $e->getMessage(), [
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate and credit affiliate commission.
     *
     * @param User $user
     * @param float $amount
     * @return void
     */
    private function calculateAffiliateCommission(User $user, float $amount): void
    {
        try {
            // Check if user was referred by an affiliate
            $referral = AffiliateReferral::where('referred_user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (!$referral) {
                Log::debug('No active affiliate referral found for user', ['user_id' => $user->id]);
                return;
            }

            // Get the affiliate program
            $affiliateProgram = AffiliateProgram::where('user_id', $referral->referrer_id)
                ->where('is_active', true)
                ->first();

            if (!$affiliateProgram) {
                Log::debug('No active affiliate program found for referrer', [
                    'referrer_id' => $referral->referrer_id
                ]);
                return;
            }

            // Calculate commission (default 5% if not specified)
            $commissionRate = $affiliateProgram->commission_rate ?? 5.0;
            $commissionAmount = ($amount * $commissionRate) / 100;

            // Credit the referrer's affiliate balance
            $referrer = User::find($referral->referrer_id);
            if ($referrer) {
                // Update affiliate program earnings
                $affiliateProgram->total_earnings += $commissionAmount;
                $affiliateProgram->available_balance += $commissionAmount;
                $affiliateProgram->save();

                // Update referral record
                $referral->total_commission += $commissionAmount;
                $referral->save();

                // Create a transaction record for the commission
                Transaction::create([
                    'user_id' => $referrer->id,
                    'transaction_id' => 'COMM_' . uniqid(),
                    'amount' => $commissionAmount,
                    'currency' => $user->currency ?? 'NGN',
                    'charge' => 0.00,
                    'transaction_type' => 'affiliate_commission',
                    'description' => "Affiliate commission from {$user->first_name} {$user->last_name}'s deposit",
                    'status' => 'completed',
                    'payment_method' => 'affiliate',
                    'meta' => json_encode([
                        'referred_user_id' => $user->id,
                        'deposit_amount' => $amount,
                        'commission_rate' => $commissionRate,
                    ]),
                ]);

                Log::info('💰 Affiliate commission credited', [
                    'referrer_id' => $referrer->id,
                    'referred_user_id' => $user->id,
                    'deposit_amount' => $amount,
                    'commission_amount' => $commissionAmount,
                    'commission_rate' => $commissionRate,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('💥 Affiliate commission calculation failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'amount' => $amount,
                'exception' => $e
            ]);
            // Don't throw the exception - we don't want to fail the payment if commission fails
        }
    }
}

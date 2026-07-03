<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

// === SET CORRECT BALANCE HERE ===
$corrections = [
    // 'email@example.com' => 0.00,  // set to desired correct balance
];

foreach ($corrections as $email => $correctBalance) {
    $user = User::where('email', $email)->first();
    if (!$user) {
        echo "User not found: {$email}\n";
        continue;
    }
    $old = $user->balance;
    $user->balance = $correctBalance;
    $user->save();

    // Log the correction as a transaction
    DB::table('transactions')->insert([
        'user_id'          => $user->id,
        'transaction_id'   => 'BALANCE_CORRECTION_' . time(),
        'transaction_type' => $correctBalance < $old ? 'Debit' : 'Credit',
        'amount'           => abs($correctBalance - $old),
        'charge'           => 0,
        'description'      => "Manual balance correction (was {$old})",
        'status'           => 'completed',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    echo "User {$email}: balance changed from {$old} → {$correctBalance}\n";
}

// Show current state
echo "\n=== Current Balances ===\n";
User::select('id','email','balance')->get()->each(function($u) {
    echo "User #{$u->id} {$u->email}: {$u->balance}\n";
});

// - total admin credits
// - total order spend (all orders regardless of status)
// - total refunded orders (orders with status=refunded, their prices)
// - expected balance vs actual balance

$users = User::select('id','email','balance')->get();

foreach ($users as $user) {
    $adminCredits = DB::table('transactions')
        ->where('user_id', $user->id)
        ->where('transaction_type', 'Credit')
        ->sum('amount');

    $totalOrders = DB::table('orders')
        ->where('user_id', $user->id)
        ->whereNotIn('status', ['pending']) // only placed orders
        ->sum('price');

    $refundedOrders = DB::table('orders')
        ->where('user_id', $user->id)
        ->where('status', 'refunded')
        ->get(['id', 'price', 'status', 'created_at']);

    $refundedTotal = $refundedOrders->sum('price');

    // Expected = admin credits - (all order prices) + refunded order prices
    // = admin credits - non-refunded order prices
    $nonRefundedSpend = $totalOrders - $refundedTotal;
    $expectedBalance = $adminCredits - $nonRefundedSpend;

    echo "=== User #{$user->id} {$user->email} ===\n";
    echo "  Current balance:   {$user->balance}\n";
    echo "  Admin credits:     {$adminCredits}\n";
    echo "  Total order spend: {$totalOrders}\n";
    echo "  Refunded orders:   {$refundedTotal} (" . count($refundedOrders) . " orders)\n";
    echo "  Expected balance:  {$expectedBalance}\n";
    echo "  Difference (over): " . ($user->balance - $expectedBalance) . "\n";

    if (!empty($refundedOrders)) {
        echo "  Refunded order list:\n";
        foreach ($refundedOrders as $o) {
            echo "    Order #{$o->id}: price={$o->price}\n";
        }
    }
    echo "\n";
}

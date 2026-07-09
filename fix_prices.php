<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Step 1: Fix convention_rate
$provider = \App\Models\ApiProvider::find(1);
$provider->convention_rate = 1400;
$provider->save();
echo "convention_rate updated to 1400\n";

// Step 2: Fetch latest rates from provider
echo "Fetching services from bulkfollows...\n";
$response = \Illuminate\Support\Facades\Http::timeout(60)->asForm()->post($provider->url, [
    'key'    => $provider->api_key,
    'action' => 'services',
]);
$services = $response->json();
echo "Fetched: " . count($services) . " services\n";

$rateMap = [];
foreach ($services as $svc) {
    if (isset($svc['service'], $svc['rate'])) {
        $rateMap[(string)$svc['service']] = (float)$svc['rate'];
    }
}

// Step 3: Recalculate all service prices
$dbServices = \App\Models\Service::where('api_provider_id', 1)
    ->whereNotNull('api_service_id')
    ->get();

$updated = 0;
$skipped = 0;
$conventionRate = 1400.0;

foreach ($dbServices as $s) {
    $newRate = $rateMap[(string)$s->api_service_id] ?? null;
    if ($newRate === null) { $skipped++; continue; }

    $markup = (float)($s->markup_percentage ?? 75);
    $factor = 1 + ($markup / 100);

    $s->api_provider_price = $newRate;
    $s->rate_per_1000      = round($newRate * $factor * $conventionRate, 4);
    $s->price              = round($newRate / 1000 * $factor * $conventionRate, 8);
    $s->save();
    $updated++;
}

echo "Updated: {$updated} services\n";
echo "Skipped: {$skipped} services\n";
echo "Done.\n";

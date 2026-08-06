<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$provider = \App\Models\ApiProvider::find(1);
$conventionRate = 1400.0;

echo "Fetching live rates from bulkfollows...\n";
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

$dbServices = \App\Models\Service::where('api_provider_id', 1)
    ->whereNotNull('api_service_id')
    ->get();

$lines = [];
$lines[] = "-- Live price update generated on " . date('Y-m-d H:i:s');
$lines[] = "UPDATE api_providers SET convention_rate = 1400 WHERE id = 1;";
$lines[] = "";

$count = 0;
foreach ($dbServices as $s) {
    $newRate = $rateMap[(string)$s->api_service_id] ?? null;
    if ($newRate === null) continue;

    $markup  = (float)($s->markup_percentage ?? 75);
    $factor  = 1 + ($markup / 100);
    $r1000   = round($newRate * $factor * $conventionRate, 4);
    $price   = round($newRate / 1000 * $factor * $conventionRate, 8);

    $lines[] = "UPDATE services SET api_provider_price = {$newRate}, rate_per_1000 = {$r1000}, price = {$price} WHERE api_service_id = '{$s->api_service_id}' AND api_provider_id = 1;";
    $count++;
}

file_put_contents(__DIR__ . '/live_price_update.sql', implode("\n", $lines));
echo "Generated {$count} UPDATE statements\n";
echo "File saved: live_price_update.sql — import it in phpMyAdmin BEFORE closing this window.\n";

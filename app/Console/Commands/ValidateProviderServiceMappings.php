<?php

namespace App\Console\Commands;

use App\Models\ApiProvider;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ValidateProviderServiceMappings extends Command
{
    protected $signature = 'services:validate-provider-mappings
        {--provider_id= : Restrict validation to one provider ID}
        {--deactivate : Deactivate invalid local services}';

    protected $description = 'Validate local service api_service_id mappings against provider catalogs';

    public function handle(): int
    {
        $providerId = $this->option('provider_id');
        $shouldDeactivate = (bool) $this->option('deactivate');

        $providersQuery = ApiProvider::query()
            ->whereHas('services', function ($query) {
                $query->whereNotNull('api_service_id');
            });

        if (!empty($providerId)) {
            $providersQuery->where('id', (int) $providerId);
        }

        $providers = $providersQuery->get();

        if ($providers->isEmpty()) {
            $this->warn('No providers found with mapped services.');
            return self::SUCCESS;
        }

        $totalProviders = 0;
        $totalMapped = 0;
        $totalInvalid = 0;
        $totalActiveInvalid = 0;
        $totalDeactivated = 0;

        foreach ($providers as $provider) {
            $totalProviders++;

            $remoteIds = $this->fetchProviderServiceIds($provider);
            if ($remoteIds === null) {
                $this->error("Provider {$provider->id} ({$provider->api_name}): failed to fetch remote catalog.");
                continue;
            }

            $localServices = Service::where('api_provider_id', $provider->id)
                ->whereNotNull('api_service_id')
                ->get(['id', 'service_title', 'api_service_id', 'service_status', 'category_id']);

            $mappedCount = $localServices->count();
            $totalMapped += $mappedCount;

            $invalidServices = $localServices->filter(function ($service) use ($remoteIds) {
                return !isset($remoteIds[(string) $service->api_service_id]);
            })->values();

            $activeInvalidServices = $invalidServices->where('service_status', '!=', 0)->values();

            $invalidCount = $invalidServices->count();
            $activeInvalidCount = $activeInvalidServices->count();
            $totalInvalid += $invalidCount;
            $totalActiveInvalid += $activeInvalidCount;

            $this->line("Provider {$provider->id} ({$provider->api_name}): mapped={$mappedCount}, invalid_total={$invalidCount}, invalid_active={$activeInvalidCount}");

            if ($invalidCount > 0) {
                foreach ($invalidServices->take(10) as $service) {
                    $name = preg_replace('/\s+/', ' ', (string) $service->service_title);
                    $this->line("  - service_id={$service->id}, api_service_id={$service->api_service_id}, title={$name}");
                }

                if ($shouldDeactivate) {
                    $idsToDeactivate = $activeInvalidServices
                        ->pluck('id')
                        ->all();

                    if (!empty($idsToDeactivate)) {
                        Service::whereIn('id', $idsToDeactivate)->update(['service_status' => 0]);
                        $totalDeactivated += count($idsToDeactivate);
                    }

                    foreach ($invalidServices as $service) {
                        Cache::forget('services_category_' . $service->category_id);
                    }

                    Cache::forget('all_services_essential');
                    Cache::forget('services_category_all');
                    Cache::forget('provider_service_ids_' . $provider->id);

                    Log::warning('Bulk deactivated invalid provider service mappings.', [
                        'provider_id' => $provider->id,
                        'invalid_count' => $invalidCount,
                        'deactivated_count' => count($idsToDeactivate ?? []),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("Summary: providers={$totalProviders}, mapped={$totalMapped}, invalid_total={$totalInvalid}, invalid_active={$totalActiveInvalid}, deactivated={$totalDeactivated}");

        if (!$shouldDeactivate && $totalActiveInvalid > 0) {
            $this->warn('Run again with --deactivate to disable invalid mappings.');
        }

        return self::SUCCESS;
    }

    private function fetchProviderServiceIds(ApiProvider $provider): ?array
    {
        try {
            $response = Http::timeout(60)->asForm()->post($provider->url, [
                'key' => $provider->api_key,
                'action' => 'services',
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Unexpected HTTP status: ' . $response->status());
            }

            $services = $response->json();
            if (!is_array($services)) {
                throw new \RuntimeException('Invalid provider catalog format.');
            }

            $ids = [];
            foreach ($services as $item) {
                if (isset($item['service'])) {
                    $ids[(string) $item['service']] = true;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch provider services for mapping validation.', [
                'provider_id' => $provider->id,
                'provider_url' => $provider->url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

<?php

use App\Models\ApiProvider;
use App\Models\Category;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('available for ordering scope excludes misconfigured provider backed services', function () {
    $category = Category::create([
        'category_title' => 'Instagram',
        'status' => '1',
    ]);

    $activeProvider = ApiProvider::create([
        'api_name' => 'Active Provider',
        'url' => 'https://provider.test/api',
        'api_key' => 'key',
        'status' => 1,
    ]);

    $inactiveProvider = ApiProvider::create([
        'api_name' => 'Inactive Provider',
        'url' => 'https://inactive-provider.test/api',
        'api_key' => 'key',
        'status' => 0,
    ]);

    $manualService = Service::create([
        'service_title' => 'Manual Service',
        'category_id' => $category->id,
        'min_amount' => 100,
        'max_amount' => 1000,
        'price' => 0.25,
        'service_status' => 1,
    ]);

    $validProviderService = Service::create([
        'service_title' => 'Valid Provider Service',
        'category_id' => $category->id,
        'min_amount' => 100,
        'max_amount' => 1000,
        'price' => 0.30,
        'service_status' => 1,
        'api_provider_id' => $activeProvider->id,
        'api_service_id' => 123,
    ]);

    Service::create([
        'service_title' => 'Missing Provider Service ID',
        'category_id' => $category->id,
        'min_amount' => 100,
        'max_amount' => 1000,
        'price' => 0.35,
        'service_status' => 1,
        'api_provider_id' => $activeProvider->id,
        'api_service_id' => null,
    ]);

    Service::create([
        'service_title' => 'Inactive Provider Service',
        'category_id' => $category->id,
        'min_amount' => 100,
        'max_amount' => 1000,
        'price' => 0.40,
        'service_status' => 1,
        'api_provider_id' => $inactiveProvider->id,
        'api_service_id' => 456,
    ]);

    $availableServiceIds = Service::availableForOrdering()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($availableServiceIds)->toBe([
        $manualService->id,
        $validProviderService->id,
    ]);
});
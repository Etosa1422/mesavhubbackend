<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\Category;
use App\Models\ApiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ManageServicePriceController extends Controller
{
    public function getPriceIncreaseStats(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'category_id' => 'sometimes|exists:categories,id',
                'provider_id' => 'sometimes|exists:api_providers,id',
            ]);

            $query = Service::query()->where('api_provider_price', '>', 0);

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }
            if ($request->filled('provider_id')) {
                $query->where('api_provider_id', $request->input('provider_id'));
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_services,
                AVG(rate_per_1000) as average_price,
                MIN(rate_per_1000) as min_price,
                MAX(rate_per_1000) as max_price,
                AVG(markup_percentage) as average_markup
            ')->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_services' => (int)   $stats->total_services,
                    'average_price'  => (float) $stats->average_price,
                    'min_price'      => (float) $stats->min_price,
                    'max_price'      => (float) $stats->max_price,
                    'average_markup' => round((float) $stats->average_markup, 2),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Price stats error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to fetch price statistics.'], 500);
        }
    }

    public function applyMarkup(Request $request): JsonResponse
    {
        $request->validate([
            'markup'      => 'required|numeric|min:0|max:10000',
            'scope'       => 'required|string|in:all,category,provider',
            'category_id' => 'required_if:scope,category|exists:categories,id',
            'provider_id' => 'required_if:scope,provider|exists:api_providers,id',
        ]);

        DB::beginTransaction();
        try {
            $markup     = (float) $request->input('markup');
            $scope      = $request->input('scope');
            $conditions = [];

            switch ($scope) {
                case 'all':
                    $affectedCount    = Service::applyMarkup($markup);
                    $scopeDescription = 'all services';
                    break;
                case 'category':
                    $category = Category::findOrFail($request->input('category_id'));
                    $conditions['category_id'] = $category->id;
                    $affectedCount    = Service::applyMarkup($markup, $conditions);
                    $scopeDescription = "category: {$category->category_title}";
                    break;
                case 'provider':
                    $provider = ApiProvider::findOrFail($request->input('provider_id'));
                    $conditions['provider_id'] = $provider->id;
                    $affectedCount    = Service::applyMarkup($markup, $conditions);
                    $scopeDescription = "provider: {$provider->api_name}";
                    break;
                default:
                    throw new \Exception('Invalid scope.');
            }

            DB::commit();

            // Clear all service-related caches (per-category keys + the global one)
            Cache::forget('all_services_essential');
            $categoryIds = \App\Models\Category::pluck('id');
            foreach ($categoryIds as $id) {
                Cache::forget("services_category_{$id}");
            }
            Cache::forget('services_category_all');

            Log::info('Markup applied', ['markup' => $markup, 'scope' => $scope, 'affected' => $affectedCount]);

            return response()->json([
                'status'  => 'success',
                'message' => "Markup of {$markup}% applied to {$scopeDescription}. {$affectedCount} services updated.",
                'data'    => ['affected_services' => $affectedCount, 'markup' => $markup, 'scope' => $scope]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Markup apply failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to apply markup: ' . $e->getMessage()], 500);
        }
    }

    // Old route alias � maps percentage => markup so existing calls still work
    public function increasePrices(Request $request): JsonResponse
    {
        $request->merge([
            'markup' => $request->input('markup', $request->input('percentage')),
            'scope'  => $request->input('scope', 'all'),
        ]);
        return $this->applyMarkup($request);
    }
}

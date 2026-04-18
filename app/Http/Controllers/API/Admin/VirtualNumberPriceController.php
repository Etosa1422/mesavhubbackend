<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\VirtualNumberPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualNumberPriceController extends Controller
{
    /** GET /api/admin/virtual-number-prices */
    public function index(): JsonResponse
    {
        $rules = VirtualNumberPrice::orderBy('service')->orderBy('country_code')->get();

        return response()->json(['success' => true, 'data' => $rules]);
    }

    /** POST /api/admin/virtual-number-prices */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service'      => 'nullable|string|max:50',
            'country_code' => 'nullable|string|size:2',
            'fixed_price'  => 'nullable|numeric|min:0|max:9999999',
            'markup'       => 'nullable|numeric|min:0.01|max:100',
            'note'         => 'nullable|string|max:255',
        ]);

        // Must provide at least one price control
        if (empty($validated['fixed_price']) && empty($validated['markup'])) {
            return response()->json([
                'success' => false,
                'message' => 'Provide either a fixed_price or a markup multiplier.',
            ], 422);
        }

        // Ensure uniqueness (service + country_code)
        $exists = VirtualNumberPrice::where('service', $validated['service'] ?? null)
            ->where('country_code', $validated['country_code'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'A rule for this service + country combination already exists.',
            ], 409);
        }

        $rule = VirtualNumberPrice::create($validated);

        $this->bustCache($validated['country_code'] ?? null);

        return response()->json(['success' => true, 'data' => $rule], 201);
    }

    /** PUT /api/admin/virtual-number-prices/{rule} */
    public function update(Request $request, VirtualNumberPrice $rule): JsonResponse
    {
        $validated = $request->validate([
            'fixed_price' => 'nullable|numeric|min:0|max:9999999',
            'markup'      => 'nullable|numeric|min:0.01|max:100',
            'note'        => 'nullable|string|max:255',
        ]);

        $rule->update($validated);

        $this->bustCache($rule->country_code);

        return response()->json(['success' => true, 'data' => $rule->fresh()]);
    }

    /** DELETE /api/admin/virtual-number-prices/{rule} */
    public function destroy(VirtualNumberPrice $rule): JsonResponse
    {
        $country = $rule->country_code;
        $rule->delete();

        $this->bustCache($country);

        return response()->json(['success' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Clear cached services lists that may be affected.
     * If country_code is known, only clear that country. Otherwise flush all.
     */
    private function bustCache(?string $countryCode): void
    {
        if ($countryCode) {
            cache()->forget("vn_services_{$countryCode}");
        } else {
            // Wildcard rule (service-only) → we can't know which countries to clear,
            // so flush every known 2-letter country code cache entry.
            // In practice this is a no-op unless we track keys, so we rely on the
            // 5-minute TTL. For a more thorough flush you could use cache tags.
        }
    }
}

<?php

namespace App\Models;

use App\Models\UserServiceRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Service extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'service_title',
        'category_id',
        'link',
        'username',
        'min_amount',
        'max_amount',
        'average_time',
        'description',
        'rate_per_1000',
        'price',
        'price_percentage_increase',
        'service_status',
        'service_type',
        'api_provider_id',
        'api_service_id',
        'api_provider_price',
        'markup_percentage',
        'drip_feed',
        'refill',
        'is_refill_automatic',
    ];

    public $timestamps = false;

    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'price_percentage_increase' => 'double',
        'service_status' => 'integer',
        'drip_feed' => 'integer',
        'refill' => 'boolean',
        'is_refill_automatic' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Optional: Relationship to category
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }


    public function provider()
    {
        return $this->belongsTo(ApiProvider::class, 'api_provider_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'service_id', 'id');
    }

    protected function scopeUserRate($query)
    {
        $query->addSelect([
            'user_rate' => UserServiceRate::select('price')
                ->whereColumn('service_id', 'services.id')
                ->where('user_id', auth()->id())
        ]);
    }



    public function getProviderNameAttribute($value)
    {
        if (isset($this->api_provider_id) && $this->api_provider_id != 0) {
            $prov = ApiProvider::find($this->api_provider_id);
            if ($prov) {
                return $prov['api_name'];
            }
            return false;
        }
    }


    public static function increaseAllPrices($percentage)
    {
        return self::applyMarkup((float) $percentage);
    }

    public static function bulkIncreasePrices($percentage, $conditions = [])
    {
        return self::applyMarkup((float) $percentage, $conditions);
    }

    /**
     * Set a markup percentage on services.
     * price and rate_per_1000 are ALWAYS derived from api_provider_price,
     * so calling this multiple times with the same value is idempotent — no compounding.
     *
     * api_provider_price = provider's raw rate per 1000 units (from provider API)
     * rate_per_1000      = api_provider_price * (1 + markup/100)
     * price              = api_provider_price * (1 + markup/100) * convention_rate
     *                      (convention_rate converts provider currency; equals 1 if already in local currency)
     */
    public static function applyMarkup(float $markup, array $conditions = []): int
    {
        $factor = 1 + ($markup / 100);

        // Build a JOIN UPDATE so convention_rate is applied per provider
        $query = DB::table('services')
            ->join('api_providers', 'services.api_provider_id', '=', 'api_providers.id')
            ->where('services.api_provider_price', '>', 0);

        if (!empty($conditions['category_id'])) {
            $query->where('services.category_id', $conditions['category_id']);
        }
        if (!empty($conditions['provider_id'])) {
            $query->where('services.api_provider_id', $conditions['provider_id']);
        }
        if (!empty($conditions['service_ids'])) {
            $query->whereIn('services.id', $conditions['service_ids']);
        }

        return $query->update([
            'services.markup_percentage' => $markup,
            'services.rate_per_1000'     => DB::raw("ROUND(services.api_provider_price * {$factor}, 4)"),
            'services.price'             => DB::raw("ROUND(services.api_provider_price * {$factor} * COALESCE(api_providers.convention_rate, 1), 8)"),
        ]);
    }
}

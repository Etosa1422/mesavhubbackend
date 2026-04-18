<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualNumberRental extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_rental_id',
        'phone_number',
        'country_code',
        'country_name',
        'country_flag',
        'country_dial',
        'service',
        'service_label',
        'price',
        'otp_code',
        'otp_received_at',
        'expires_at',
        'status',
        'released_at',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'otp_received_at' => 'datetime',
        'expires_at'      => 'datetime',
        'released_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Accessors / Helpers ───────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    /** Mark the rental as expired (called by scheduled command) */
    public function expire(): void
    {
        $this->update(['status' => 'expired']);
    }
}

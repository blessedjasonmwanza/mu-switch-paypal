<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business an aggregator collects for: its customers pay with PayPal, and it
 * is settled to its own mobile-money wallet in its own currency.
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'slug', 'email', 'country', 'currency',
        'wallet_number', 'wallet_name', 'network', 'commission_percent', 'is_active',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The payments collected for this merchant, each settled to its wallet.
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(CrossBorderTransfer::class);
    }

    /**
     * The merchant's saved pay links, newest first.
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class)->latest('id');
    }

    /**
     * A unique, readable slug for the merchant's public pay link.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'merchant', 48, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (self::query()->where('slug', $slug)->exists());

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}

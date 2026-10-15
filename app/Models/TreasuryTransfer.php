<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money moved from one provider account to another, tracked from request to settlement.
 */
class TreasuryTransfer extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'from_provider_id', 'to_provider_id', 'currency', 'amount_minor', 'fee_minor',
        'received_minor', 'status', 'method', 'origin', 'external_reference', 'note', 'reasoning', 'failure_reason',
        'approved_at', 'sent_at', 'settled_at',
    ];

    public function fromProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'from_provider_id');
    }

    public function toProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'to_provider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TreasuryTransferEvent::class)->orderBy('id');
    }

    public function amount(): BigDecimal
    {
        return Currency::fromMinorUnits($this->amount_minor, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'received_minor' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}

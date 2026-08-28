<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    public const PROVIDER_EVPAY = 'evpay';

    public const METHOD_MOBILE_MONEY = 'mobile_money';

    public const OPERATORS = ['TigoPesa', 'AirtelMoney', 'HaloPesa', 'Mpesa'];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_SETTLED = 'SETTLED';

    public const STATUS_ON_HOLD = 'ON-HOLD';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_REFUNDED = 'REFUNDED';

    public const STATUS_REVERSED = 'REVERSED';

    public const FULFILLABLE_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_SETTLED,
    ];

    protected $fillable = [
        'request_id',
        'transaction_id',
        'user_id',
        'order_id',
        'provider',
        'payment_method',
        'operator',
        'phone_number',
        'amount',
        'currency',
        'product',
        'status',
        'provider_message',
        'provider_response',
        'request_payload',
        'request_body',
        'fsp_reference',
        'cbs_reference',
        'fulfilled_at',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'request_payload' => 'array',
        'fulfilled_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(EvPayWebhookDelivery::class);
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    public function qualifiesForFulfillment(): bool
    {
        return in_array($this->status, self::FULFILLABLE_STATUSES, true);
    }
}

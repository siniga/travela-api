<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvPayWebhookDelivery extends Model
{
    protected $table = 'evpay_webhook_deliveries';

    protected $fillable = [
        'delivery_id',
        'event',
        'payment_id',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}

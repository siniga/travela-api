<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EsimImportBatch extends Model
{
    public const SIM_TYPE_ESIM = 'esim';

    public const SIM_TYPE_PHYSICAL = 'physical';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name',
        'sim_type',
        'total_items',
        'processed_items',
        'failed_items',
        'status',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(EsimImportItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function esims(): HasMany
    {
        return $this->hasMany(Esim::class, 'import_batch_id');
    }

    public function markProcessing(): void
    {
        if ($this->status === self::STATUS_PENDING) {
            $this->update([
                'status' => self::STATUS_PROCESSING,
                'started_at' => $this->started_at ?? now(),
            ]);
        }
    }

    public function recordItemSuccess(): void
    {
        $this->markProcessing();
        $this->increment('processed_items');
    }

    public function recordItemFailure(): void
    {
        $this->markProcessing();
        $this->increment('failed_items');
    }

    public function reopenForProcessing(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            $this->update([
                'status' => self::STATUS_PROCESSING,
                'completed_at' => null,
            ]);
        }
    }

    public function attemptFinish(): void
    {
        $handled = $this->processed_items + $this->failed_items;

        if ($this->total_items > 0 && $handled >= $this->total_items) {
            $this->update([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }
    }

    public function isPhysical(): bool
    {
        return $this->sim_type === self::SIM_TYPE_PHYSICAL;
    }

    public function resolvedSimType(): string
    {
        return in_array($this->sim_type, [self::SIM_TYPE_ESIM, self::SIM_TYPE_PHYSICAL], true)
            ? $this->sim_type
            : self::SIM_TYPE_ESIM;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sim_type' => $this->resolvedSimType(),
            'total_items' => $this->total_items,
            'processed_items' => $this->processed_items,
            'failed_items' => $this->failed_items,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

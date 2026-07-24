<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EsimImportItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'esim_import_batch_id',
        'esim_id',
        'page_number',
        'phone_number',
        'iccid',
        'source_file_path',
        'qr_code_path',
        'status',
        'error_message',
    ];

    protected $casts = [
        'page_number' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EsimImportBatch::class, 'esim_import_batch_id');
    }

    public function esim(): BelongsTo
    {
        return $this->belongsTo(Esim::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        return [
            'id' => $this->id,
            'page_number' => $this->page_number,
            'phone_number' => $this->phone_number,
            'iccid' => $this->iccid,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'esim_id' => $this->esim_id,
        ];
    }
}

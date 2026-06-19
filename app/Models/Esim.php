<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Esim extends Model
{
    public const SIM_TYPE_ESIM = 'esim';

    public const SIM_TYPE_PHYSICAL = 'physical';

    public const PROVIDER_STATUS_ACTIVE = 'active';

    public const PROVIDER_STATUS_SUSPENDED = 'suspended';

    public const SALE_STATUS_AVAILABLE = 'available';

    public const SALE_STATUS_SOLD = 'sold';

    public const SALE_STATUS_USED = 'used';

    protected $fillable = [
        'sim_id',
        'msisdn',
        'network_id',
        'iccid',
        'imsi',
        'description',
        'status',
        'sale_status',
        'sim_type',
        'provider_status',
        'qr_code_path',
        'qr_code_data',
        'balances',
        'balance_fetched_at',
    ];

    protected $casts = [
        'network_id' => 'integer',
        'balances' => 'array',
        'balance_fetched_at' => 'datetime',
    ];

    public static function normalizeMsisdn(string $msisdn): string
    {
        return ltrim(preg_replace('/\s+/', '', trim($msisdn)), '+');
    }

    public static function findByMsisdn(string $msisdn): ?self
    {
        $normalized = self::normalizeMsisdn($msisdn);

        return static::query()
            ->whereIn('msisdn', [$normalized, '+'.$normalized])
            ->first();
    }
}


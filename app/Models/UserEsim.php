<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEsim extends Model
{
    protected $fillable = [
        'user_id',
        'esim_id',
        'balance',
        'balance_currency',
        'balance_fetched_at',
        'balances',
        'last_recharge_amount',
        'last_recharge_reference',
        'last_recharge_status',
        'last_recharged_at',
    ];

    protected $casts = [
        'balance'              => 'decimal:2',
        'balance_fetched_at'   => 'datetime',
        'balances'             => 'array',
        'last_recharge_amount' => 'decimal:2',
        'last_recharged_at'    => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function esim()
    {
        return $this->belongsTo(Esim::class);
    }
}


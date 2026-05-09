<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Esim extends Model
{
    protected $fillable = [
        'sim_id',
        'msisdn',
        'network_id',
        'iccid',
        'imsi',
        'description',
        'status',
    ];

    protected $casts = [
        'network_id' => 'integer',
    ];
}


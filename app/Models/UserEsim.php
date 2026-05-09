<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEsim extends Model
{
    protected $fillable = [
        'user_id',
        'esim_id',
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


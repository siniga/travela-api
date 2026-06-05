<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bundle extends Model {
    protected $fillable = [
      'external_id',
      'sim_bundle_id',
      'bundle_type_id',
      'country_provider_id',
      'network_id',
      'name',
      'alias',
      'validity_days',
      'data_mb',
      'voice_minutes',
      'sms',
      'price_usd',
      'price_tzs',
      'currency',
      'bundle_size',
      'bundle_size_in_mb',
      'unit',
      'product_code',
      'active',
      'metadata'
    ];
    protected $casts = [
        'metadata' => 'array',
        'active' => 'boolean',
        'price_usd' => 'decimal:2',
        'price_tzs' => 'integer',
    ];
    public function type(){ return $this->belongsTo(BundleType::class, 'bundle_type_id'); }
    public function countryProvider(){ return $this->belongsTo(CountryProvider::class); }

    public function userEsims()
    {
        return $this->hasMany(UserEsim::class);
    }
  }
  
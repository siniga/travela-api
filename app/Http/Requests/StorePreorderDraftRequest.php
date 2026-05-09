<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePreorderDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array {
        return [
          'trip.destination_country' => ['required','string','max:80'],
          'trip.arrival_date'        => ['required','date','after_or_equal:today'],
          'trip.departure_date'      => ['required','date','after:trip.arrival_date'],
          'trip.duration_days'       => ['required','integer','min:1'],
    
          'items'               => ['required','array','min:1'],
          // order_items.type enum is ['bundle','service'] in the database.
          'items.*.type'        => ['required','in:bundle,service'],
          'items.*.bundle_id'   => ['required_if:items.*.type,bundle','integer'],
          'items.*.bundle_name' => ['required','string','max:120'],
          'items.*.data_amount' => ['nullable','integer','min:0'],
          'items.*.validity_days'=>['nullable','integer','min:1'],
          'items.*.price'       => ['required','numeric','min:0'],
          'items.*.currency'    => ['required','string','size:3'],
    
          'pricing.subtotal'        => ['required','numeric','min:0'],
          'pricing.discount_amount' => ['required','numeric','min:0'],
          'pricing.discount_code'   => ['nullable','string','max:40'],
          'pricing.total_amount'    => ['required','numeric','min:0'],
          'pricing.currency'        => ['required','string','size:3'],
    
          'order_metadata.source'     => ['required','string','max:40'],
          'order_metadata.platform'   => ['required','string','max:40'],
          'order_metadata.created_at' => ['required','date'],
        ];
  }
}

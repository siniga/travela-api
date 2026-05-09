<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'draft_id' => ['required', 'string', 'max:80'],
            'user_id' => ['required', 'integer', 'exists:users,id'],

            'checkoutMode' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:10'],
            'countryName' => ['nullable', 'string', 'max:120'],
            'simType' => ['nullable', 'string', 'in:esim,physical'],

            'trip.destination_country' => ['required', 'string', 'max:80'],
            'trip.arrival_date' => ['required', 'date'],
            'trip.departure_date' => ['required', 'date', 'after:trip.arrival_date'],
            'trip.duration_days' => ['required', 'integer', 'min:1'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'in:bundle,service'],
            'items.*.bundle_id' => ['required_if:items.*.type,bundle', 'nullable', 'integer'],
            'items.*.bundle_name' => ['required', 'string', 'max:120'],
            'items.*.data_amount' => ['nullable', 'integer', 'min:0'],
            'items.*.validity_days' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.currency' => ['required', 'string', 'size:3'],

            'pricing.subtotal' => ['required', 'numeric', 'min:0'],
            'pricing.discount_amount' => ['required', 'numeric', 'min:0'],
            'pricing.discount_code' => ['nullable', 'string', 'max:40'],
            'pricing.total_amount' => ['required', 'numeric', 'min:0'],
            'pricing.currency' => ['required', 'string', 'size:3'],

            'kyc.passport_id' => ['required', 'string', 'max:50'],
            'kyc.passport_country' => ['required', 'string', 'max:10'],
            'kyc.nationality' => ['required', 'string', 'max:80'],
            'kyc.gender' => ['required', 'string', 'in:Male,Female,Other'],
            'kyc.reason_for_travel' => ['required', 'string', 'max:120'],

            'payment' => ['nullable', 'array'],
            'payment.status' => ['nullable', 'string', 'in:paid,pending'],
            'payment.reference' => ['nullable', 'string', 'max:120'],
            'payment.method' => ['nullable', 'string', 'max:50'],
            'payment.paid_at' => ['nullable', 'date'],

            'order_metadata.source' => ['required', 'string', 'max:40'],
            'order_metadata.platform' => ['required', 'string', 'max:40'],
            'order_metadata.created_at' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $kyc = $this->input('kyc', []);
        if (isset($kyc['gender']) && is_string($kyc['gender'])) {
            $g = strtolower(trim($kyc['gender']));
            $map = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
            $kyc['gender'] = $map[$g] ?? (strlen($kyc['gender']) ? ucfirst($g) : $kyc['gender']);
            $this->merge(['kyc' => $kyc]);
        }

        if ($this->filled('country') && is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        $trip = $this->input('trip', []);
        if (isset($trip['destination_country']) && is_string($trip['destination_country']) && strlen($trip['destination_country']) <= 3) {
            $trip['destination_country'] = strtoupper($trip['destination_country']);
            $this->merge(['trip' => $trip]);
        }
    }
}

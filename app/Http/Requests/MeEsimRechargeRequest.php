<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MeEsimRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'msisdn' => ['required', 'string'],
            'network_id' => ['required', 'integer'],
            'airtime_amount' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable'],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}


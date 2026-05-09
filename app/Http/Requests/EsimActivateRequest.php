<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EsimActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'msisdn' => ['nullable', 'string', 'required_without_all:iccid,imsi'],
            'iccid' => ['nullable', 'string', 'required_without_all:msisdn,imsi'],
            'imsi' => ['nullable', 'string', 'required_without_all:msisdn,iccid'],
        ];
    }
}


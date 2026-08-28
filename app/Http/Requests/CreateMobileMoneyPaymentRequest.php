<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMobileMoneyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $operator = $this->input('operator') ?? $this->input('fsOperator');
        $phone = $this->input('phone') ?? $this->input('payerAccountId');
        $payerName = $this->input('payer_name') ?? $this->input('payerName');
        $requestId = $this->input('request_id') ?? $this->input('requestId');

        $this->merge(array_filter([
            'operator' => $operator,
            'phone' => $phone,
            'payer_name' => $payerName,
            'request_id' => $requestId,
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            'operator' => ['required', 'string', Rule::in(Payment::OPERATORS)],
            'phone' => ['required', 'string', 'max:20'],
            'amount' => ['required_without:order_id', 'nullable', 'numeric', 'min:100'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'product' => ['nullable', 'string', 'max:255'],
            'payer_name' => ['nullable', 'string', 'max:120'],
            'narrative' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}

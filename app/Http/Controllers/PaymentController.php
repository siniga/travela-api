<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateMobileMoneyPaymentRequest;
use App\Models\Payment;
use App\Services\EvPay\EvPayException;
use App\Services\PaymentProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentProcessingService $payments,
    ) {
    }

    public function storeMobileMoney(CreateMobileMoneyPaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->payments->initiateMobileMoney(
                $request->user(),
                $request->validated(),
            );
        } catch (EvPayException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->httpStatus);
        }

        return response()->json([
            'success' => true,
            'message' => $payment->provider_message ?: 'Push request sent. Awaiting customer confirmation.',
            'data' => $this->payments->publicPaymentPayload($payment),
        ], 202);
    }

    public function status(Request $request, string $payment): JsonResponse
    {
        $record = Payment::query()
            ->where('id', $payment)
            ->orWhere('request_id', $payment)
            ->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
                'code' => 'not_found',
            ], 404);
        }

        $this->payments->assertAccessibleBy($record, $request->user());

        try {
            $record = $this->payments->refreshStatus($record);
        } catch (EvPayException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'data' => $this->payments->publicPaymentPayload($record),
            ], $e->httpStatus);
        }

        return response()->json([
            'success' => true,
            'data' => $this->payments->publicPaymentPayload($record),
        ]);
    }
}

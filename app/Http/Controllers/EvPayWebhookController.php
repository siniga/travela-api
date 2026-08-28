<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEvPayWebhookJob;
use App\Models\EvPayWebhookDelivery;
use App\Services\EvPay\EvPayException;
use App\Services\EvPay\EvPayWebhookVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EvPayWebhookController extends Controller
{
    public function __construct(
        private readonly EvPayWebhookVerifier $verifier,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $verified = $this->verifier->verify($request);
        } catch (EvPayException $e) {
            Log::warning('EVPay webhook rejected', [
                'code' => $e->errorCode,
                'delivery_id' => $request->header('X-EvPay-Delivery'),
                'event' => $request->header('X-EvPay-Event'),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->httpStatus);
        }

        $existing = EvPayWebhookDelivery::query()
            ->where('delivery_id', $verified['delivery_id'])
            ->first();

        if ($existing) {
            Log::info('EVPay webhook duplicate delivery acknowledged', [
                'delivery_id' => $existing->delivery_id,
                'event' => $existing->event,
                'payment_id' => $existing->payment_id,
            ]);

            return response()->json(['status' => 'OK']);
        }

        try {
            $delivery = EvPayWebhookDelivery::create([
                'delivery_id' => $verified['delivery_id'],
                'event' => $verified['event'],
                'payload' => $verified['payload'],
            ]);
        } catch (QueryException $e) {
            Log::info('EVPay webhook duplicate delivery on insert', [
                'delivery_id' => $verified['delivery_id'],
                'event' => $verified['event'],
            ]);

            return response()->json(['status' => 'OK']);
        }

        ProcessEvPayWebhookJob::dispatch($delivery->id);

        Log::info('EVPay webhook accepted', [
            'delivery_id' => $delivery->delivery_id,
            'event' => $delivery->event,
        ]);

        return response()->json(['status' => 'OK']);
    }
}

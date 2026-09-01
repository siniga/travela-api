<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\EvPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvPayController extends Controller
{
    public function __construct(private readonly EvPayService $evpay)
    {
    }

    public function preparePayment(Request $request, $orderId)
    {
        $order = Order::findOrFail($orderId);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'This order is already paid.'], 400);
        }

        $order = $this->evpay->prepare($order);

        return response()->json([
            'message' => 'Payment prepared successfully.',
            'order_id' => $order->id,
            'payment_reference' => $order->payment_reference,
            'payment_status' => $order->payment_status,
        ]);
    }

    public function createCheckoutUrl(Request $request, $orderId)
    {
        $order = Order::with('user')->findOrFail($orderId);

        if ($order->payment_status === 'paid') {
            return response()->json([
                'message' => 'This order is already paid.',
            ], 400);
        }
        try {
            return response()->json($this->evpay->createCheckoutUrl($order));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function callback(Request $request): JsonResponse
    {
        $result = $this->evpay->handleCallback($request);

        $status = match (true) {
            ! ($result['success'] ?? false) && str_contains($result['message'] ?? '', 'signature') => 403,
            ! ($result['success'] ?? false) && str_contains($result['message'] ?? '', 'not found') => 404,
            ! ($result['success'] ?? false) => 422,
            default => 200,
        };

        return response()->json($result, $status);
    }
}
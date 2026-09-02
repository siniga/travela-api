<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\EvPay\EvPayCheckoutService;
use App\Services\EvPay\EvPayWebhookException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EvPayController extends Controller
{
    public function __construct(private readonly EvPayCheckoutService $evpay) {}

    public function preparePayment(Request $request, $orderId)
    {
        $order = Order::findOrFail($orderId);

        if ($denied = $this->denyUnlessOrderOwner($request, $order)) {
            return $denied;
        }

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

        if ($denied = $this->denyUnlessOrderOwner($request, $order)) {
            return $denied;
        }

        if ($order->payment_status === 'paid') {
            return response()->json([
                'message' => 'This order is already paid.',
            ], 400);
        }

        try {
            return response()->json($this->evpay->startCardPayment($order));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function callback(Request $request): JsonResponse
    {
        try {
            return response()->json($this->evpay->handleWebhook($request));
        } catch (EvPayWebhookException $e) {
            return response()->json([
                'status' => 'ERROR',
                'message' => $e->getMessage(),
            ], $e->status);
        }
    }

    private function denyUnlessOrderOwner(Request $request, Order $order): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isAdmin() || $user->isAgent()) {
            return null;
        }

        if ((int) $order->user_id !== (int) $user->id) {
            return response()->json(['message' => 'This order does not belong to you.'], 403);
        }

        return null;
    }
}

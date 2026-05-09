<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\EvPayService;

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
}
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Http\Requests\Payment\VerifyPaymentRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * (Re)start an online payment for an order the customer owns — used when a
     * customer abandons the gateway and comes back to pay.
     */
    public function initialize(InitializePaymentRequest $request, PaymentService $payments)
    {
        $order = Order::where('reference', $request->validated()['order_reference'])->firstOrFail();
        $this->authorize('pay', $order);

        $payment = $order->payment()->firstOrFail();
        $init = $payments->initialize($payment);

        return response()->json([
            'payment' => new PaymentResource($payment->refresh()),
            'checkout' => [
                'requires_redirect' => $init['requires_redirect'] ?? false,
                'authorization_url' => $init['authorization_url'] ?? null,
                'is_mock' => $init['is_mock'] ?? false,
                'reference' => $payment->reference,
            ],
        ]);
    }

    /**
     * Verify a payment after the customer returns from the gateway (or from the
     * mock checkout screen). The provider is the source of truth.
     */
    public function verify(VerifyPaymentRequest $request, PaymentService $payments)
    {
        $data = $request->validated();

        $payment = Payment::where('reference', $data['reference'])->with('order')->firstOrFail();
        $this->authorize('pay', $payment->order);

        $verification = $payments->verify($payment, [
            'mock_outcome' => $data['mock_outcome'] ?? null,
        ]);

        $payment->refresh()->load('order');
        $paid = $verification['status'] === PaymentStatus::Paid;

        return response()->json([
            'status' => $verification['status']->value,
            'paid' => $paid,
            'payment' => new PaymentResource($payment),
            'order' => new OrderResource($payment->order->load(['items', 'delivery', 'payment', 'deliveryWindow'])),
        ]);
    }

    /**
     * Lightweight status poll used by the mock/redirect return screen.
     */
    public function show(Request $request, Payment $payment)
    {
        $payment->load('order');
        $this->authorize('pay', $payment->order);

        return new PaymentResource($payment);
    }
}

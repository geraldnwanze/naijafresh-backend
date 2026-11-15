<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->with(['items', 'delivery', 'payment'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function store(
        PlaceOrderRequest $request,
        OrderService $orders,
        PaymentService $payments,
    ) {
        $result = DB::transaction(function () use ($request, $orders, $payments): array {
            $order = $orders->place($request->user(), $request->validated());

            return ['order' => $order, 'payment' => $payments->createForOrder($order)];
        });

        // Starting the online payment happens after the order is saved: if the
        // provider is down the customer keeps their order and can retry from it.
        $init = null;
        $paymentError = null;

        try {
            $init = $payments->initialize($result['payment']);
        } catch (PaymentGatewayException $e) {
            report($e);
            $paymentError = $e->customerMessage();
        }

        $order = $result['order']->load(['items', 'delivery', 'payment', 'deliveryWindow']);

        return response()->json([
            'data' => new OrderResource($order),
            'payment' => new PaymentResource($result['payment']->refresh()),
            'checkout' => [
                'error' => $paymentError,
                'requires_redirect' => $init['requires_redirect'] ?? false,
                'authorization_url' => $init['authorization_url'] ?? null,
                'is_mock' => $init['is_mock'] ?? false,
                'reference' => $result['payment']->reference,
            ],
        ], 201);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        return new OrderResource(
            $order->load(['items.product:id,slug', 'delivery', 'payment', 'deliveryWindow'])
        );
    }
}

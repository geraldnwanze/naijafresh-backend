<?php

namespace App\Http\Controllers\Api\V1;

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
            $payment = $payments->createForOrder($order);
            $initialization = $payments->initialize($payment);

            return ['order' => $order, 'payment' => $payment->refresh(), 'init' => $initialization];
        });

        $order = $result['order']->load(['items', 'delivery', 'payment', 'deliveryWindow']);
        $init = $result['init'];

        return response()->json([
            'data' => new OrderResource($order),
            'payment' => new PaymentResource($result['payment']),
            'checkout' => [
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

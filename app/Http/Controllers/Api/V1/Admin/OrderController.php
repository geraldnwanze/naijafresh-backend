<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['items', 'payment', 'delivery', 'user:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->status($status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $q->whereHas('payment', fn ($p) => $p->where('status', $status)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order)
    {
        return new OrderResource(
            $order->load(['items.product:id,slug', 'payment', 'delivery', 'deliveryWindow', 'user:id,name,email,phone'])
        );
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order, OrderStatusService $statusService)
    {
        $data = $request->validated();

        $order = $statusService->transition(
            $order,
            OrderStatus::from($data['status']),
            [
                'reason' => $data['reason'] ?? null,
                'rider_name' => $data['rider_name'] ?? null,
                'rider_phone' => $data['rider_phone'] ?? null,
            ],
        );

        return new OrderResource(
            $order->load(['items', 'payment', 'delivery', 'deliveryWindow', 'user:id,name,email,phone'])
        );
    }
}

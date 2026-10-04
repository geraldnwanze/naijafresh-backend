<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Money;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->startOfDay();

        $revenueKobo = (int) Payment::where('status', PaymentStatus::Paid->value)->sum('amount_kobo');
        $revenueTodayKobo = (int) Payment::where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', $today)
            ->sum('amount_kobo');

        return response()->json([
            'data' => [
                'orders_total' => Order::count(),
                'orders_today' => Order::where('created_at', '>=', $today)->count(),
                'orders_pending' => Order::where('status', OrderStatus::Pending->value)->count(),
                'orders_awaiting_delivery' => Order::awaitingDelivery()->count(),
                'orders_delivered' => Order::where('status', OrderStatus::Delivered->value)->count(),
                'revenue_kobo' => $revenueKobo,
                'revenue' => Money::format($revenueKobo),
                'revenue_today_kobo' => $revenueTodayKobo,
                'revenue_today' => Money::format($revenueTodayKobo),
                'products_total' => Product::count(),
                'products_out_of_stock' => Product::where('stock_quantity', '<=', 0)->orWhere('is_available', false)->count(),
                'recent_orders' => OrderResource::collection(
                    Order::with(['items', 'payment', 'delivery'])
                        ->orderByDesc('created_at')
                        ->limit(8)
                        ->get()
                ),
            ],
        ]);
    }
}

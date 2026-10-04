<?php

namespace App\Providers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);
        // Singleton: holds the "auditing paused" flag that seeders toggle.
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
    }
}

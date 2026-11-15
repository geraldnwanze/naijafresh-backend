<?php

namespace App\Providers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Shared by every queued email (see Notifications\Concerns\QueuesEmail).
        RateLimiter::for('outgoing-mail', fn (): Limit => Limit::perSecond(max(1, (int) config('naijafresh.mail.limit')), max(1, (int) config('naijafresh.mail.per_seconds'))));
    }
}

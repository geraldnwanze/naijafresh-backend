<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Enums\ActivityEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\AuditLogResource;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Logs\ApplicationLogReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Super admin landing page: the last day at a glance.
 */
class OverviewController extends Controller
{
    public function __invoke(ApplicationLogReader $logs)
    {
        $since = now()->subDay();

        $failedLoginIps = ActivityLog::query()
            ->where('event', ActivityEvent::LoginFailed->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->selectRaw('ip_address, count(*) as attempts')
            ->groupBy('ip_address')
            ->havingRaw('count(*) >= 3')
            ->orderByDesc('attempts')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['ip_address' => $row->ip_address, 'attempts' => (int) $row->attempts]);

        return response()->json([
            'data' => [
                'stats' => [
                    'audit_changes_24h' => AuditLog::query()->where('created_at', '>=', $since)->count(),
                    'sign_ins_24h' => ActivityLog::query()->where('event', ActivityEvent::LoginSucceeded->value)->where('created_at', '>=', $since)->count(),
                    'failed_sign_ins_24h' => ActivityLog::query()->where('event', ActivityEvent::LoginFailed->value)->where('created_at', '>=', $since)->count(),
                    'orders_24h' => ActivityLog::query()->where('event', ActivityEvent::OrderPlaced->value)->where('created_at', '>=', $since)->count(),
                    'errors_24h' => $this->errorsSince($logs, $since),
                ],
                'users_by_role' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                    'role' => $role->value,
                    'label' => $role->label(),
                    'count' => User::query()->where('role', $role->value)->count(),
                ])->values(),
                'failed_sign_in_ips' => $failedLoginIps,
                'recent_audit' => AuditLogResource::collection(AuditLog::query()->latest('id')->limit(6)->get())->resolve(),
                'recent_activity' => ActivityLogResource::collection(ActivityLog::query()->latest('id')->limit(8)->get())->resolve(),
            ],
        ]);
    }

    /**
     * Error-level (or worse) entries in the newest log file since $since.
     */
    private function errorsSince(ApplicationLogReader $logs, CarbonImmutable|Carbon $since): int
    {
        $path = $logs->pathFor(null);

        if ($path === null) {
            return 0;
        }

        $serious = ['error', 'critical', 'alert', 'emergency'];

        return count(array_filter(
            $logs->read($path)['entries'],
            fn (array $e): bool => in_array($e['level'], $serious, true) && $e['time'] !== null && $e['time'] >= $since->toIso8601String(),
        ));
    }
}

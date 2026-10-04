<?php

namespace App\Services\Audit;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the activity log: what people did in the app (sign-ins, failed
 * sign-ins, orders, payments). Unlike the audit trail this covers customers
 * too, and it is pruned after the retention window.
 *
 * Never throws: losing a log line must not break a sign-in or an order.
 */
class ActivityLogger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        ActivityEvent $event,
        ?string $description = null,
        ?Model $subject = null,
        array $properties = [],
        ?User $user = null,
    ): void {
        if (! $this->audit->isEnabled()) {
            return;
        }

        $user ??= auth()->user();

        try {
            // Savepoint: a failed insert must not poison the surrounding transaction (Postgres).
            DB::transaction(fn () => ActivityLog::query()->create([
                'user_id' => $user?->getKey(),
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'event' => $event,
                'description' => mb_substr($description ?? $event->label(), 0, 255),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'properties' => $properties === [] ? null : $properties,
                'ip_address' => request()->ip(),
                'user_agent' => $this->userAgent(),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function userAgent(): ?string
    {
        $agent = request()->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}

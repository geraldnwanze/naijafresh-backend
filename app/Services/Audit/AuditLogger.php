<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the audit trail: who changed which record, and what it was before.
 *
 * What gets recorded:
 *  - changes made by staff (admins, super admins), and
 *  - changes with no signed-in user (payment webhooks, scheduled jobs, CLI),
 *    shown as "System".
 * Changes made by customers (checkout taking stock, paying an order) are side
 * effects of their own actions and belong in the activity log instead.
 *
 * A failure to write the audit row is reported, never thrown, so it can't break
 * the change being audited. Bulk loaders (seeders) wrap their work in
 * withoutAuditing(), which pauses the activity log too.
 */
class AuditLogger
{
    private bool $enabled = true;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutAuditing(Closure $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    /**
     * False while a seeder/bulk loader has paused recording (the activity log
     * honours the same switch).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function record(Model $model, string $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $actor = auth()->user();

        if ($actor !== null && ! $actor->isAdmin()) {
            return;
        }

        [$old, $new] = $this->diff($model, $event);

        if ($event === 'updated' && $new === []) {
            return;
        }

        // Deleting the actor's own account: the row can't point at a user that is gone.
        $actorId = $model instanceof User && $actor !== null && $model->is($actor) && $event === 'deleted' ? null : $actor?->getKey();

        try {
            // Savepoint: a failed insert must not poison the surrounding transaction (Postgres).
            DB::transaction(fn () => AuditLog::query()->create([
                'actor_id' => $actorId,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'event' => $event,
                'auditable_type' => $model->getMorphClass(),
                'auditable_id' => $model->getKey(),
                'auditable_label' => $model->auditLabel(),
                'old_values' => $old === [] ? null : $old,
                'new_values' => $new === [] ? null : $new,
                'ip_address' => request()->ip(),
                'user_agent' => $this->userAgent(),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(Model $model, string $event): array
    {
        $excluded = $model->auditExcludedAttributes();

        $keys = match ($event) {
            'updated' => array_keys($model->getChanges()),
            default => array_keys($model->getAttributes()),
        };

        $keys = array_values(array_diff($keys, $excluded));

        $old = [];
        $new = [];

        foreach ($keys as $key) {
            match ($event) {
                'created' => $new[$key] = $model->getAttribute($key),
                'deleted' => $old[$key] = $model->getAttribute($key),
                default => [$old[$key] = $model->getOriginal($key), $new[$key] = $model->getAttribute($key)],
            };
        }

        return [$old, $new];
    }

    private function userAgent(): ?string
    {
        $agent = request()->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}

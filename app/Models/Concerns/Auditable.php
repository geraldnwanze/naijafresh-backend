<?php

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records created/updated/deleted changes to the model in the audit trail
 * (who, what, before → after). See AuditLogger for what is and isn't recorded.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        foreach (static::auditedEvents() as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event): void {
                app(AuditLogger::class)->record($model, $event);
            });
        }
    }

    /**
     * Override to audit fewer events (e.g. only 'updated').
     *
     * @return list<string>
     */
    public static function auditedEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    /**
     * Attributes never written to the audit trail.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return ['created_at', 'updated_at', 'password', 'remember_token'];
    }

    /**
     * Human label shown next to the change ("Jollof Rice Kit", "NF-1A2B3C").
     */
    public function auditLabel(): string
    {
        foreach (['name', 'reference', 'key', 'description', 'label'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 190);
            }
        }

        return '#'.$this->getKey();
    }
}

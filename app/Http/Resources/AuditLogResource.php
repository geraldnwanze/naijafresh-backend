<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'model' => $this->modelName(),
            'model_id' => $this->auditable_id,
            'label' => $this->auditable_label,
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actor_name ?? 'System',
                'email' => $this->actor_email,
                'is_system' => $this->actor_id === null && $this->actor_name === null,
            ],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

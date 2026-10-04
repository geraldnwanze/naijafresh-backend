<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'description' => $this->description,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user_name,
                // Failed sign-ins for unknown accounts only have the typed email.
                'email' => $this->user_email ?? ($this->properties['email'] ?? null),
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => class_basename($this->subject_type),
                'id' => $this->subject_id,
            ],
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

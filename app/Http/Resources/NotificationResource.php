<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->data['kind'] ?? 'general',
            'title' => $this->data['title'] ?? '',
            'body' => $this->data['body'] ?? '',
            // Storefront or admin route the notification opens.
            'url' => $this->data['url'] ?? null,
            'order_id' => $this->data['order_id'] ?? null,
            'order_reference' => $this->data['order_reference'] ?? null,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

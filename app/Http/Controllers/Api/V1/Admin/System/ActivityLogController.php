<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Enums\ActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'event' => ['nullable', Rule::enum(ActivityEvent::class)],
            'user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $timezone = config('naijafresh.timezone');

        $logs = ActivityLog::query()
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['search'] ?? null, function ($q, string $term): void {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(properties::text) LIKE ?', [$like])
                    ->orWhere('ip_address', 'like', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ActivityLogResource::collection($logs)->additional([
            'filters' => ['events' => ActivityEvent::options()],
        ]);
    }
}

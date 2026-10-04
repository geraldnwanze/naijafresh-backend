<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    private const EVENTS = ['created', 'updated', 'deleted'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'event' => ['nullable', Rule::in(self::EVENTS)],
            'model' => ['nullable', 'string', 'max:60'],
            'actor_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $timezone = config('naijafresh.timezone');
        $modelTypes = AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type');

        $logs = AuditLog::query()
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['model'] ?? null, fn ($q, $name) => $q->whereIn('auditable_type', $modelTypes->filter(fn (string $type) => class_basename($type) === $name)->all()))
            ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters['search'] ?? null, function ($q, string $term): void {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(auditable_label) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(actor_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(actor_email) LIKE ?', [$like]));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($logs)->additional([
            'filters' => [
                'events' => self::EVENTS,
                'models' => $modelTypes->map(fn (string $type) => class_basename($type))->unique()->values(),
            ],
        ]);
    }
}

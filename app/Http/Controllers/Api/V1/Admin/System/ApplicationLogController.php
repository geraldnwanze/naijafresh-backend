<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Controller;
use App\Services\Logs\ApplicationLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Read-only viewer for the Laravel log files.
 */
class ApplicationLogController extends Controller
{
    public function index(Request $request, ApplicationLogReader $reader)
    {
        $filters = $request->validate([
            'file' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', Rule::in(ApplicationLogReader::LEVELS)],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $files = $reader->files();
        $path = $reader->pathFor($filters['file'] ?? null);

        if ($path === null) {
            return $this->noFile($files, $filters['file'] ?? null);
        }

        ['entries' => $entries, 'truncated' => $truncated] = $reader->read($path);

        $search = isset($filters['search']) ? mb_strtolower($filters['search']) : null;

        if ($search !== null) {
            $entries = array_values(array_filter($entries, fn (array $e): bool => str_contains(mb_strtolower($e['message'].' '.($e['details'] ?? '')), $search)));
        }

        $counts = array_fill_keys(ApplicationLogReader::LEVELS, 0);
        foreach ($entries as $entry) {
            $counts[$entry['level']] = ($counts[$entry['level']] ?? 0) + 1;
        }

        if (isset($filters['level'])) {
            $entries = array_values(array_filter($entries, fn (array $e): bool => $e['level'] === $filters['level']));
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        $paginator = new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * $perPage, $perPage),
            count($entries),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return JsonResource::collection($paginator)->additional([
            'file' => basename($path),
            'files' => $files,
            'levels' => ApplicationLogReader::LEVELS,
            'counts' => $counts,
            'truncated' => $truncated,
        ]);
    }

    /**
     * @param  list<array{name: string, size: int, modified_at: string}>  $files
     */
    private function noFile(array $files, ?string $requested): JsonResponse
    {
        // A requested name that isn't one of the log files is a bad request;
        // no log files at all is simply an empty (healthy) state.
        if ($requested !== null && $files !== []) {
            return response()->json(['message' => 'That log file does not exist.'], 404);
        }

        return response()->json([
            'data' => [],
            'file' => null,
            'files' => [],
            'levels' => ApplicationLogReader::LEVELS,
            'counts' => array_fill_keys(ApplicationLogReader::LEVELS, 0),
            'truncated' => false,
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0, 'from' => null, 'to' => null],
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
        ]);
    }
}

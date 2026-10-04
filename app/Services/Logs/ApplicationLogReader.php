<?php

namespace App\Services\Logs;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Read-only view of the Laravel log files (storage/logs) for the super admin
 * area. Only the newest `naijafresh.logs.max_read_bytes` of a file are read, so
 * a huge log can't exhaust memory; `truncated` says when older entries were left out.
 */
class ApplicationLogReader
{
    /** @var list<string> */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private const MESSAGE_LIMIT = 400;

    private const DETAILS_LIMIT = 8000;

    private const HEADER = '/^\[(?<time>[^\]]+)\] (?<env>[\w.-]+)\.(?<level>[A-Za-z]+): (?<rest>.*)$/s';

    /**
     * @return list<array{name: string, size: int, modified_at: string}>
     */
    public function files(): array
    {
        $files = [];

        foreach (glob($this->directory().'/*.log') ?: [] as $path) {
            $files[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'modified_at' => CarbonImmutable::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /**
     * Resolves a requested file name to a path inside the log directory, or
     * null. Only names that appear in files() are accepted (no path tricks).
     */
    public function pathFor(?string $name): ?string
    {
        $names = array_column($this->files(), 'name');

        if ($names === []) {
            return null;
        }

        $name ??= $names[0];

        return in_array($name, $names, true) ? $this->directory().'/'.$name : null;
    }

    /**
     * Entries newest first.
     *
     * @return array{entries: list<array{time: string|null, environment: string, level: string, message: string, details: string|null}>, truncated: bool}
     */
    public function read(string $path): array
    {
        $size = (int) filesize($path);
        $max = (int) config('naijafresh.logs.max_read_bytes');
        $start = max(0, $size - $max);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['entries' => [], 'truncated' => false];
        }

        fseek($handle, $start);
        $raw = (string) stream_get_contents($handle);
        fclose($handle);

        $chunks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*\] [\w.-]+\.[A-Za-z]+: )/m', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $entries = [];

        foreach ($chunks as $chunk) {
            $entry = $this->parse($chunk);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return ['entries' => array_reverse($entries), 'truncated' => $start > 0];
    }

    /**
     * @return array{time: string|null, environment: string, level: string, message: string, details: string|null}|null
     */
    private function parse(string $chunk): ?array
    {
        if (! preg_match(self::HEADER, rtrim($chunk), $m)) {
            return null;
        }

        $rest = $m['rest'];
        $message = strtok($rest, "\n") ?: '';
        $hasMore = mb_strlen($message) > self::MESSAGE_LIMIT || mb_strlen($rest) > mb_strlen($message);

        return [
            'time' => $this->isoTime($m['time']),
            'environment' => $m['env'],
            'level' => strtolower($m['level']),
            'message' => mb_substr($message, 0, self::MESSAGE_LIMIT),
            'details' => $hasMore ? mb_substr($rest, 0, self::DETAILS_LIMIT) : null,
        ];
    }

    private function isoTime(string $time): ?string
    {
        try {
            return CarbonImmutable::parse($time, config('app.timezone'))->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function directory(): string
    {
        return rtrim((string) config('naijafresh.logs.directory'), '/');
    }
}

<?php

use App\Models\User;
use App\Services\Logs\ApplicationLogReader;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/nf-logs-'.uniqid();
    mkdir($this->dir);
    config()->set('naijafresh.logs.directory', $this->dir);

    $this->super = User::factory()->superAdmin()->create();
    $this->actingAs($this->super, 'sanctum');
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

function writeLog(string $name, string $contents, ?int $modified = null): void
{
    file_put_contents(config('naijafresh.logs.directory').'/'.$name, $contents);

    if ($modified) {
        touch(config('naijafresh.logs.directory').'/'.$name, $modified);
    }
}

function sampleLog(): string
{
    $recent = now()->subHour()->format('Y-m-d H:i:s');
    $old = now()->subDays(3)->format('Y-m-d H:i:s');

    return <<<LOG
    [{$old}] local.INFO: Old thing happened
    [{$old}] local.ERROR: Ancient failure {"order":5}
    [{$recent}] local.WARNING: Stock is low {"product":"Palm Oil"}
    [{$recent}] local.ERROR: SQLSTATE[08006] connection refused {"exception":"[object] (PDOException(code: 7): connection refused at /app/Db.php:10)
    [stacktrace]
    #0 /app/Db.php(10): connect()
    #1 {main}
    "}
    [{$recent}] local.INFO: Order placed
    LOG;
}

it('reads entries newest first with level, message and stack trace details', function (): void {
    writeLog('laravel.log', sampleLog());

    $response = $this->getJson('/api/v1/admin/system/application-logs')->assertOk();

    expect($response->json('file'))->toBe('laravel.log')
        ->and(array_column($response->json('data'), 'message'))->toBe([
            'Order placed',
            'SQLSTATE[08006] connection refused {"exception":"[object] (PDOException(code: 7): connection refused at /app/Db.php:10)',
            'Stock is low {"product":"Palm Oil"}',
            'Ancient failure {"order":5}',
            'Old thing happened',
        ])
        ->and($response->json('data.1.level'))->toBe('error')
        ->and($response->json('data.1.details'))->toContain('[stacktrace]')->toContain('#1 {main}')
        ->and($response->json('data.0.details'))->toBeNull()
        ->and($response->json('data.0.environment'))->toBe('local')
        ->and($response->json('counts'))->toMatchArray(['info' => 2, 'warning' => 1, 'error' => 2, 'debug' => 0])
        ->and($response->json('truncated'))->toBeFalse();
});

it('filters by level and by text in the message or stack trace', function (): void {
    writeLog('laravel.log', sampleLog());

    expect($this->getJson('/api/v1/admin/system/application-logs?level=error')->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/admin/system/application-logs?search=palm')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/admin/system/application-logs?search=Db.php')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/admin/system/application-logs?level=error&search=ancient')->json('data'))->toHaveCount(1);

    // Level counts reflect the search, so the chips stay meaningful.
    expect($this->getJson('/api/v1/admin/system/application-logs?search=ancient')->json('counts.error'))->toBe(1);
});

it('paginates', function (): void {
    writeLog('laravel.log', collect(range(1, 30))->map(fn ($i) => '['.now()->format('Y-m-d H:i:s')."] local.INFO: entry {$i}")->implode("\n")."\n");

    $page = $this->getJson('/api/v1/admin/system/application-logs?per_page=10&page=2')->assertOk();

    expect($page->json('data'))->toHaveCount(10)
        ->and($page->json('data.0.message'))->toBe('entry 20')
        ->and($page->json('meta.total'))->toBe(30)
        ->and($page->json('meta.last_page'))->toBe(3);
});

it('lists the log files, newest first, and opens the one asked for', function (): void {
    writeLog('laravel-2026-10-01.log', '['.now()->format('Y-m-d H:i:s')."] local.INFO: from the old file\n", now()->subDays(2)->timestamp);
    writeLog('laravel-2026-10-03.log', '['.now()->format('Y-m-d H:i:s')."] local.INFO: from the new file\n", now()->subDay()->timestamp);

    $default = $this->getJson('/api/v1/admin/system/application-logs')->assertOk();
    expect(array_column($default->json('files'), 'name'))->toBe(['laravel-2026-10-03.log', 'laravel-2026-10-01.log'])
        ->and($default->json('file'))->toBe('laravel-2026-10-03.log')
        ->and($default->json('data.0.message'))->toBe('from the new file');

    $this->getJson('/api/v1/admin/system/application-logs?file=laravel-2026-10-01.log')
        ->assertJsonPath('data.0.message', 'from the old file');
});

it('only ever opens files in the log directory', function (string $file): void {
    writeLog('laravel.log', sampleLog());
    file_put_contents($this->dir.'/../secret.txt', 'top secret');

    $this->getJson('/api/v1/admin/system/application-logs?file='.urlencode($file))->assertNotFound();

    unlink($this->dir.'/../secret.txt');
})->with(['../secret.txt', '..%2Fsecret.txt', '/etc/passwd', 'missing.log', 'laravel.log/../../secret.txt']);

it('returns an empty list when there are no log files yet', function (): void {
    $this->getJson('/api/v1/admin/system/application-logs')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('file', null)
        ->assertJsonPath('meta.total', 0);
});

it('reads only the newest part of a huge file and says so', function (): void {
    config()->set('naijafresh.logs.max_read_bytes', 600);
    writeLog('laravel.log', collect(range(1, 100))->map(fn ($i) => '['.now()->format('Y-m-d H:i:s')."] local.INFO: entry number {$i}")->implode("\n")."\n");

    $response = $this->getJson('/api/v1/admin/system/application-logs?per_page=100')->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('data.0.message'))->toBe('entry number 100')
        ->and(count($response->json('data')))->toBeLessThan(20)
        // the entry cut in half by the read window is dropped, not shown garbled
        ->and(array_column($response->json('data'), 'message'))->each->toMatch('/^entry number \d+$/');
});

it('ignores lines that are not log entries', function (): void {
    writeLog('laravel.log', "garbage at the top\n[".now()->format('Y-m-d H:i:s')."] local.ERROR: real one\n");

    expect(app(ApplicationLogReader::class)->read($this->dir.'/laravel.log')['entries'])->toHaveCount(1);
});

it('counts recent errors on the overview, ignoring old ones and lower levels', function (): void {
    writeLog('laravel.log', sampleLog());

    $this->getJson('/api/v1/admin/system/overview')->assertOk()->assertJsonPath('data.stats.errors_24h', 1);
});

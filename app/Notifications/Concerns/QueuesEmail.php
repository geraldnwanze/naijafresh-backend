<?php

namespace App\Notifications\Concerns;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Delivery rules shared by every notification that sends email.
 *
 * The in-app (database) copy is written immediately so the bell updates at
 * once; the email is always handed to the queue (never sent while the web
 * request waits). Emails leave at no more than `naijafresh.mail.limit` per
 * `naijafresh.mail.per_seconds` seconds, because mail providers refuse bursts (one order can queue five
 * emails at once); a throttled email waits its turn and is not counted as a
 * failed attempt. A mail server that actually errors is retried twice more.
 * Notifications using this trait must also implement ShouldQueue.
 */
trait QueuesEmail
{
    use Queueable;

    /** Failed deliveries allowed before giving up; waiting for the rate limit is not a failure. */
    public int $maxExceptions = 3;

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => (string) config('queue.default'),
        ];
    }

    /**
     * Throttle the mail channel. Skipped when mail is sent inline (sync queue,
     * as in most tests) because a throttled job could not be released and
     * would be lost, and when the limit is switched off (0).
     *
     * @return list<object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        if ($channel !== 'mail' || config('queue.default') === 'sync' || (int) config('naijafresh.mail.limit') < 1) {
            return [];
        }

        // Check again when the window has passed (the default adds several seconds of padding).
        return [(new RateLimited('outgoing-mail'))->releaseAfter(max(1, (int) config('naijafresh.mail.per_seconds')))];
    }

    /**
     * How long a throttled email may keep waiting for its turn.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }
}

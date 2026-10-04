<?php

namespace App\Notifications\Concerns;

use Illuminate\Bus\Queueable;

/**
 * Delivery rules shared by every notification that sends email.
 *
 * The in-app (database) copy is written immediately so the bell updates at
 * once; the email is always handed to the queue (never sent while the web
 * request waits) and retried a couple of times if the mail server hiccups.
 * Notifications using this trait must also implement ShouldQueue.
 */
trait QueuesEmail
{
    use Queueable;

    public int $tries = 3;

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
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }
}

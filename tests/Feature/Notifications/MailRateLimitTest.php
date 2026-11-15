<?php

use App\Notifications\Concerns\QueuesEmail;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ThrottleProbeNotification;

function emailsSentSoFar(): int
{
    return count(Mail::mailer('array')->getSymfonyTransport()->messages());
}

function queueThreeProbeEmails(): void
{
    foreach (['a', 'b', 'c'] as $name) {
        Notification::route('mail', "{$name}@example.com")->notify(new ThrottleProbeNotification);
    }
}

function runWorkerOnce(): void
{
    test()->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
}

describe('with the mail limit on a real queue', function (): void {
    beforeEach(function (): void {
        config()->set('queue.default', 'database');
        config()->set('naijafresh.mail.limit', 1);
        config()->set('naijafresh.mail.per_seconds', 1);
        RateLimiter::clear('outgoing-mail');
    });

    it('sends a burst one email per second instead of all at once', function (): void {
        queueThreeProbeEmails();
        expect(DB::table('jobs')->count())->toBe(3);

        runWorkerOnce();
        expect(emailsSentSoFar())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(2);

        $this->travel(2)->seconds();
        runWorkerOnce();
        expect(emailsSentSoFar())->toBe(2);

        $this->travel(2)->seconds();
        runWorkerOnce();
        expect(emailsSentSoFar())->toBe(3)
            ->and(DB::table('jobs')->count())->toBe(0);
    });

    it('can be set to allow fewer emails over a longer window, for strict providers', function (): void {
        config()->set('naijafresh.mail.per_seconds', 10);
        $this->freezeTime();
        queueThreeProbeEmails();

        runWorkerOnce();
        $this->travel(5)->seconds();
        runWorkerOnce();
        expect(emailsSentSoFar())->toBe(1);

        $this->travel(6)->seconds();
        runWorkerOnce();
        expect(emailsSentSoFar())->toBe(2);
    });

    it('never fails an email just because it had to wait its turn', function (): void {
        $this->freezeTime();
        queueThreeProbeEmails();

        // Many passes inside the same second: the later emails are put back each time.
        foreach (range(1, 5) as $_) {
            runWorkerOnce();
        }

        expect(DB::table('failed_jobs')->count())->toBe(0)
            ->and(emailsSentSoFar())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(2);
    });
});

describe('when the limit does not apply', function (): void {
    it('is not applied to the in-app copy, to inline sending, or when switched off', function (): void {
        $notification = new ThrottleProbeNotification;
        $user = new stdClass;

        config()->set('queue.default', 'database');
        config()->set('naijafresh.mail.limit', 1);
        config()->set('naijafresh.mail.per_seconds', 1);
        expect($notification->middleware($user, 'mail'))->toHaveCount(1)->and($notification->middleware($user, 'mail')[0])->toBeInstanceOf(RateLimited::class)
            ->and($notification->middleware($user, 'database'))->toBe([]);

        config()->set('queue.default', 'sync');
        expect($notification->middleware($user, 'mail'))->toBe([]);

        config()->set('queue.default', 'database');
        config()->set('naijafresh.mail.limit', 0);
        expect($notification->middleware($user, 'mail'))->toBe([]);
    });

    it('is shared by every email notification', function (): void {
        $usesTrait = collect(glob(app_path('Notifications/*.php')))
            ->map(fn (string $file): string => 'App\\Notifications\\'.basename($file, '.php'))
            ->filter(fn (string $class): bool => class_exists($class) && in_array(QueuesEmail::class, class_uses_recursive($class), true));

        expect($usesTrait)->not->toBeEmpty();
    });
});

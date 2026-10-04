<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Shared delivery guarantees for the notifiers.
 *
 *  - Nothing is sent until the surrounding database transaction commits, so a
 *    rolled-back order never emails anyone.
 *  - A failure to notify (queue or SMTP down, bad address) is reported but
 *    never thrown, so the business action always completes.
 *  - Email is queued by the notification itself (see QueuesEmail).
 */
abstract class Notifier
{
    /**
     * @param  User|iterable<User>|null  $recipients
     */
    protected function send(User|iterable|null $recipients, Notification $notification): void
    {
        if ($recipients === null) {
            return;
        }

        DB::afterCommit(function () use ($recipients, $notification): void {
            try {
                $recipients instanceof User
                    ? $recipients->notify($notification)
                    : NotificationFacade::send($recipients, $notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @return iterable<User>
     */
    protected function admins(): iterable
    {
        return User::query()->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])->get();
    }
}

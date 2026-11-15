<?php

namespace Tests\Support;

use App\Notifications\Concerns\QueuesEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Stand-in for any email notification, to exercise the shared queue rules.
 */
class ThrottleProbeNotification extends Notification implements ShouldQueue
{
    use QueuesEmail;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Probe')->line('Hello');
    }
}

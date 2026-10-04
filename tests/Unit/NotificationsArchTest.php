<?php

use Illuminate\Contracts\Queue\ShouldQueue;

arch('every notification is queued, so no email is ever sent during a request')
    ->expect('App\Notifications')
    ->classes()
    ->toImplement(ShouldQueue::class);

arch('email only leaves the app through notifications (no ad-hoc Mail:: or Mailable sends)')
    ->expect('App')
    ->not->toUse([
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Mail\Mailable',
    ]);

arch('queued notifications route the bell to sync and email to the queue')
    ->expect('App\Notifications')
    ->classes()
    ->toHaveMethod('viaConnections');

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;

/**
 * The signed-in user's in-app notifications (the bell). Customers and admins
 * use the same endpoints; each only ever sees their own.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return NotificationResource::collection($user->notifications()->paginate(15))
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $notification)
    {
        $found = $request->user()->notifications()->findOrFail($notification);
        $found->markAsRead();

        return new NotificationResource($found);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}

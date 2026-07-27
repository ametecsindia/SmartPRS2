<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * P0 Foundation — the in-app notification bell / "Notifications" screen backend.
 *
 * The SPA already ships a Notifications nav item and a topbar bell; until now
 * they had no data source. These endpoints feed them from `notification_log`
 * (written by {@see NotificationService}). All routes sit inside the standard
 * authenticated /app group, so the current user is always resolved from the
 * session exactly like EssController.
 */
class NotificationController extends Controller
{
    /** GET /app/notifications/feed — recent items + unread count for the bell. */
    public function feed(Request $request)
    {
        try {
            $user = $request->user();
            $uid = $user->id ?? null;
            $tid = $user->tenant_id ?? null;

            return response()->json([
                'ok' => true,
                'unread' => NotificationService::unreadCount($uid, $tid),
                'items' => NotificationService::feedForUser($uid, $tid, 30),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/notifications/{id}/read — mark one item read. */
    public function markRead(Request $request, $id)
    {
        try {
            $user = $request->user();
            $ok = NotificationService::markRead((int) $id, $user->id ?? null);

            return response()->json([
                'ok' => $ok,
                'unread' => NotificationService::unreadCount($user->id ?? null, $user->tenant_id ?? null),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** POST /app/notifications/read-all — mark every unread item read. */
    public function markAllRead(Request $request)
    {
        try {
            $user = $request->user();
            $n = NotificationService::markAllRead($user->id ?? null, $user->tenant_id ?? null);

            return response()->json(['ok' => true, 'updated' => $n, 'unread' => 0]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}

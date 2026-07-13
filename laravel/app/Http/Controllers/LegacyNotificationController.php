<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LegacyNotificationController extends Controller
{
    public function notifications(Request $request): JsonResponse
    {
        $userId = (int) $request->session()->get('user_id');
        if (! $this->notificationsTableExists()) {
            return response()->json(['success' => true, 'notifications' => [], 'unread_count' => 0, 'pending_count' => 0]);
        }

        $type = strtoupper(trim((string) $request->query('type', '')));
        $conditions = ['recipient_user_id = ?'];
        $params = [$userId];

        if ($type === 'FORM_EDITED' || $type === 'FORM_DELETED') {
            $conditions[] = 'type = ?';
            $params[] = $type;
        }

        $where = implode(' AND ', $conditions);

        try {
            $rows = DB::select("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC", $params);
            $counts = DB::select(<<<'SQL'
                SELECT
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    SUM(CASE WHEN acknowledged = 0 THEN 1 ELSE 0 END) AS pending_count
                FROM notifications
                WHERE recipient_user_id = ?
                SQL, [$userId]);
            $countRow = $counts[0] ?? (object) ['unread_count' => 0, 'pending_count' => 0];

            return response()->json([
                'success' => true,
                'notifications' => array_map(fn (object|array $row): array => $this->mapNotificationRow($this->rowToArray($row)), $rows),
                'unread_count' => (int) ($countRow->unread_count ?? 0),
                'pending_count' => (int) ($countRow->pending_count ?? 0),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve notifications'], 500);
        }
    }

    public function pending(Request $request): JsonResponse
    {
        $userId = (int) $request->session()->get('user_id');
        if (! $this->notificationsTableExists()) {
            return response()->json(['success' => true, 'notifications' => [], 'pending_count' => 0]);
        }

        try {
            $rows = DB::select(<<<'SQL'
                SELECT *
                FROM notifications
                WHERE recipient_user_id = ? AND acknowledged = 0
                ORDER BY created_at ASC
                SQL, [$userId]);

            return response()->json([
                'success' => true,
                'notifications' => array_map(fn (object|array $row): array => $this->mapNotificationRow($this->rowToArray($row)), $rows),
                'pending_count' => count($rows),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve pending notifications'], 500);
        }
    }

    public function acknowledge(Request $request): JsonResponse
    {
        $notificationId = (int) ($request->json('notification_id') ?? 0);
        if ($notificationId <= 0) {
            return response()->json(['error' => 'notification_id is required'], 400);
        }

        if (! $this->notificationsTableExists()) {
            return response()->json(['success' => true]);
        }

        try {
            $updated = DB::update('UPDATE notifications SET acknowledged = 1, is_read = 1 WHERE id = ? AND recipient_user_id = ?', [
                $notificationId,
                (int) $request->session()->get('user_id'),
            ]);

            if ($updated === 0) {
                return response()->json(['error' => 'Notification not found'], 404);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to acknowledge notification'], 500);
        }
    }

    public function markRead(Request $request): JsonResponse
    {
        $notificationId = (int) ($request->json('notification_id') ?? 0);
        if ($notificationId <= 0) {
            return response()->json(['error' => 'notification_id is required'], 400);
        }

        if (! $this->notificationsTableExists()) {
            return response()->json(['success' => true]);
        }

        try {
            $updated = DB::update('UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?', [
                $notificationId,
                (int) $request->session()->get('user_id'),
            ]);

            if ($updated === 0) {
                return response()->json(['error' => 'Notification not found'], 404);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to mark notification as read'], 500);
        }
    }

    private function notificationsTableExists(): bool
    {
        try {
            return Schema::hasTable('notifications');
        } catch (QueryException) {
            return false;
        }
    }

    private function mapNotificationRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'recipientUserId' => (int) $row['recipient_user_id'],
            'type' => $row['type'],
            'formId' => $row['form_id'] !== null ? (int) $row['form_id'] : null,
            'formTitle' => $row['form_title'],
            'message' => $row['message'],
            'deletionReason' => $row['deletion_reason'],
            'adminId' => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
            'adminName' => $row['admin_name'],
            'createdAt' => $row['created_at'],
            'read' => (bool) $row['is_read'],
            'acknowledged' => (bool) $row['acknowledged'],
        ];
    }

    private function rowToArray(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }
}
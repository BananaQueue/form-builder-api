<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(10, (int) $request->query('page_size', 25)));
        $offset = ($page - 1) * $pageSize;
        $action = trim((string) $request->query('action', ''));
        $search = trim((string) $request->query('search', ''));

        $where = [];
        $params = [];

        if ($action !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $where[] = '(
                actor_username LIKE ?
                OR actor_role LIKE ?
                OR entity_type LIKE ?
                OR entity_label LIKE ?
                OR action LIKE ?
                OR ip_address LIKE ?
            )';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = count($where) > 0 ? 'WHERE '.implode(' AND ', $where) : '';

        try {
            $countRows = DB::select("SELECT COUNT(*) as total FROM audit_logs {$whereSql}", $params);
            $total = (int) ($countRows[0]->total ?? 0);

            $logs = DB::select(<<<SQL
                SELECT
                    id,
                    actor_user_id,
                    actor_username,
                    actor_role,
                    action,
                    entity_type,
                    entity_id,
                    entity_label,
                    metadata,
                    ip_address,
                    user_agent,
                    created_at,
                    UNIX_TIMESTAMP(created_at) AS created_at_unix
                FROM audit_logs
                {$whereSql}
                ORDER BY id DESC, created_at DESC
                LIMIT {$pageSize} OFFSET {$offset}
                SQL, $params);
            $logs = $this->normalizeMetadata(array_map(fn (object|array $row): array => $this->rowToArray($row), $logs));

            $actions = DB::select(<<<'SQL'
                SELECT DISTINCT action
                FROM audit_logs
                ORDER BY action ASC
                SQL);

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'actions' => array_map(fn (object|array $row): mixed => $this->rowToArray($row)['action'], $actions),
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => (int) max(1, ceil($total / $pageSize)),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to retrieve audit logs'], 500);
        }
    }

    private function requireSuperAdmin(Request $request): ?JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        if ($request->session()->get('role') !== 'super_admin') {
            return response()->json(['error' => 'Super admin access required'], 403);
        }

        return null;
    }

    private function normalizeMetadata(array $logs): array
    {
        $ownerIds = [];
        foreach ($logs as $log) {
            $metadata = json_decode((string) ($log['metadata'] ?? ''), true);
            if (is_array($metadata) && isset($metadata['owner_user_id'])) {
                $ownerIds[] = (int) $metadata['owner_user_id'];
            }
        }

        $ownerNames = [];
        $ownerIds = array_values(array_unique(array_filter($ownerIds)));
        if (count($ownerIds) > 0) {
            $placeholders = implode(', ', array_fill(0, count($ownerIds), '?'));
            $users = DB::select("SELECT id, username FROM users WHERE id IN ({$placeholders})", $ownerIds);
            foreach ($users as $user) {
                $user = $this->rowToArray($user);
                $ownerNames[(int) $user['id']] = $user['username'];
            }
        }

        foreach ($logs as &$log) {
            $metadata = json_decode((string) ($log['metadata'] ?? ''), true);
            if (! is_array($metadata)) {
                continue;
            }

            if (isset($metadata['owner_user_id']) && ! isset($metadata['form_owner'])) {
                $ownerId = (int) $metadata['owner_user_id'];
                $metadata['form_owner'] = $ownerNames[$ownerId] ?? ('User #'.$ownerId);
                unset($metadata['owner_user_id']);
            }

            unset($metadata['super_admin_action']);

            if (($log['action'] ?? '') === 'FORM_UPDATED' && empty($metadata['changes'])) {
                $metadata['changes'] = ['Updated form details'];
            }

            if (($log['action'] ?? '') === 'FORM_UPDATED') {
                $questionNumber = isset($metadata['question_count']) ? (int) $metadata['question_count'] : null;
                if (isset($metadata['changes']) && is_array($metadata['changes'])) {
                    $metadata['changes'] = array_values(array_map(
                        fn (mixed $change): string => $this->normalizeUpdateChangeText((string) $change, $questionNumber),
                        $metadata['changes'],
                    ));
                }
                unset($metadata['question_count']);
            }

            $log['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        unset($log);

        return $logs;
    }

    private function normalizeUpdateChangeText(string $change, ?int $questionNumber = null): string
    {
        $normalized = preg_replace('/\s+(for|from)\s+".*"$/', '', $change);
        $normalized = preg_replace('/\s+".*"$/', '', $normalized ?? $change);
        $normalized = trim((string) $normalized);

        if (
            $questionNumber !== null
            && $questionNumber > 0
            && ! preg_match('/\((Question|Section)\s+\d+\)$/', $normalized)
            && stripos($normalized, 'question') !== false
        ) {
            $normalized .= " (Question {$questionNumber})";
        }

        return $normalized === '' ? 'Updated form details' : $normalized;
    }

    private function rowToArray(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }
}
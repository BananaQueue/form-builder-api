<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyAdminFormController extends Controller
{
    public function allForms(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        $perPage = max(1, (int) $request->query('per_page', 10));
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $search = trim((string) $request->query('search', ''));
        $categoryId = (int) $request->query('category_id', 0);
        $ownerId = (int) $request->query('owner_id', 0);
        $sortBy = (string) $request->query('sort_by', 'created_desc');

        $sortMap = [
            'created_desc' => 'f.created_at DESC',
            'created_asc' => 'f.created_at ASC',
            'title_asc' => 'f.title ASC',
            'title_desc' => 'f.title DESC',
            'owner_asc' => 'u.username ASC',
            'responses_desc' => 'response_count DESC',
        ];
        $orderSql = $sortMap[$sortBy] ?? $sortMap['created_desc'];

        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(f.title LIKE ? OR f.description LIKE ? OR u.username LIKE ?)';
            $like = '%'.$search.'%';
            array_push($params, $like, $like, $like);
        }

        if ($categoryId > 0) {
            $conditions[] = 'f.category_id = ?';
            $params[] = $categoryId;
        }

        if ($ownerId > 0) {
            $conditions[] = 'f.created_by = ?';
            $params[] = $ownerId;
        }

        $whereSql = count($conditions) > 0 ? 'WHERE '.implode(' AND ', $conditions) : '';

        try {
            $countRows = DB::select(<<<SQL
                SELECT COUNT(DISTINCT f.id) as total
                FROM forms f
                LEFT JOIN users u ON f.created_by = u.id
                {$whereSql}
                SQL, $params);
            $total = (int) ($countRows[0]->total ?? 0);

            $forms = DB::select(<<<SQL
                SELECT
                    f.id,
                    f.form_code,
                    f.title,
                    f.description,
                    f.created_at,
                    f.category_id,
                    c.name AS category_name,
                    u.id AS owner_id,
                    u.username AS owner_username,
                    u.role AS owner_role,
                    COUNT(DISTINCT q.id) AS question_count,
                    COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN r.id END) AS response_count
                FROM forms f
                LEFT JOIN users u ON f.created_by = u.id
                LEFT JOIN categories c ON f.category_id = c.id
                LEFT JOIN questions q ON f.id = q.form_id AND q.question_type != 'section'
                LEFT JOIN responses r ON f.id = r.form_id
                LEFT JOIN answers a ON a.response_id = r.id
                {$whereSql}
                GROUP BY
                    f.id, f.form_code, f.title, f.description,
                    f.created_at, f.category_id,
                    c.name, u.id, u.username, u.role
                ORDER BY {$orderSql}
                LIMIT {$perPage} OFFSET {$offset}
                SQL, $params);

            $metricRows = DB::select(<<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM forms) AS total_forms,
                    (SELECT COUNT(*) FROM users) AS total_users,
                    (SELECT COUNT(DISTINCT response_id) FROM answers) AS total_responses
                SQL);
            $metrics = $metricRows[0] ?? (object) ['total_forms' => 0, 'total_users' => 0, 'total_responses' => 0];

            return response()->json([
                'success' => true,
                'forms' => $forms,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
                'metrics' => [
                    'total_forms' => (int) $metrics->total_forms,
                    'total_users' => (int) $metrics->total_users,
                    'total_responses' => (int) $metrics->total_responses,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve forms'], 500);
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
}
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogEndpointTest extends TestCase
{
    public function test_audit_logs_require_super_admin(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_audit_logs.php');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_audit_logs_match_legacy_success_shape(): void
    {
        $params = ['FORM_UPDATED', '%admin%', '%admin%', '%admin%', '%admin%', '%admin%', '%admin%'];
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'SELECT COUNT(*)') && str_contains($sql, 'action = ?')), $params)->andReturn([
            (object) ['total' => 1],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'UNIX_TIMESTAMP(created_at)') && str_contains($sql, 'LIMIT 25 OFFSET 0')), $params)->andReturn([
            (object) [
                'id' => 99,
                'actor_user_id' => 1,
                'actor_username' => 'admin',
                'actor_role' => 'super_admin',
                'action' => 'FORM_UPDATED',
                'entity_type' => 'form',
                'entity_id' => 10,
                'entity_label' => 'Inspection',
                'metadata' => json_encode([
                    'owner_user_id' => 7,
                    'super_admin_action' => true,
                    'changes' => ['Updated question text from "Old"'],
                    'question_count' => 3,
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
                'created_at' => '2026-06-24 10:00:00',
                'created_at_unix' => 1782276000,
            ],
        ]);
        DB::shouldReceive('select')->once()->with('SELECT id, username FROM users WHERE id IN (?)', [7])->andReturn([
            (object) ['id' => 7, 'username' => 'owner'],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'SELECT DISTINCT action')))->andReturn([
            (object) ['action' => 'FORM_CREATED'],
            (object) ['action' => 'FORM_UPDATED'],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_audit_logs.php?action=FORM_UPDATED&search=admin');

        $response->assertOk()->assertJson([
            'success' => true,
            'logs' => [[
                'id' => 99,
                'actor_username' => 'admin',
                'action' => 'FORM_UPDATED',
                'entity_label' => 'Inspection',
                'metadata' => json_encode([
                    'changes' => ['Updated question text (Question 3)'],
                    'form_owner' => 'owner',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at_unix' => 1782276000,
            ]],
            'actions' => ['FORM_CREATED', 'FORM_UPDATED'],
            'pagination' => [
                'page' => 1,
                'page_size' => 25,
                'total' => 1,
                'total_pages' => 1,
            ],
        ]);
    }

    public function test_audit_logs_clamp_page_size_like_legacy(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [])->andReturn([(object) ['total' => 0]]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'LIMIT 100 OFFSET 100')), [])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'))->andReturn([]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_audit_logs.php?page=2&page_size=500');

        $response->assertOk()->assertJson([
            'success' => true,
            'logs' => [],
            'actions' => [],
            'pagination' => ['page' => 2, 'page_size' => 100, 'total' => 0, 'total_pages' => 1],
        ]);
    }

    public function test_native_audit_logs_route_requires_super_admin(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/admin/audit-logs');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_native_audit_logs_route_matches_legacy_success_shape(): void
    {
        $params = ['FORM_UPDATED', '%admin%', '%admin%', '%admin%', '%admin%', '%admin%', '%admin%'];
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'SELECT COUNT(*)') && str_contains($sql, 'action = ?')), $params)->andReturn([
            (object) ['total' => 1],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'UNIX_TIMESTAMP(created_at)') && str_contains($sql, 'LIMIT 25 OFFSET 0')), $params)->andReturn([
            (object) [
                'id' => 99,
                'actor_user_id' => 1,
                'actor_username' => 'admin',
                'actor_role' => 'super_admin',
                'action' => 'FORM_UPDATED',
                'entity_type' => 'form',
                'entity_id' => 10,
                'entity_label' => 'Inspection',
                'metadata' => json_encode([
                    'owner_user_id' => 7,
                    'super_admin_action' => true,
                    'changes' => ['Updated question text from "Old"'],
                    'question_count' => 3,
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
                'created_at' => '2026-06-24 10:00:00',
                'created_at_unix' => 1782276000,
            ],
        ]);
        DB::shouldReceive('select')->once()->with('SELECT id, username FROM users WHERE id IN (?)', [7])->andReturn([
            (object) ['id' => 7, 'username' => 'owner'],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'SELECT DISTINCT action')))->andReturn([
            (object) ['action' => 'FORM_CREATED'],
            (object) ['action' => 'FORM_UPDATED'],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/api/admin/audit-logs?action=FORM_UPDATED&search=admin');

        $response->assertOk()->assertJson([
            'success' => true,
            'logs' => [[
                'id' => 99,
                'actor_username' => 'admin',
                'action' => 'FORM_UPDATED',
                'entity_label' => 'Inspection',
                'created_at_unix' => 1782276000,
            ]],
            'actions' => ['FORM_CREATED', 'FORM_UPDATED'],
            'pagination' => [
                'page' => 1,
                'page_size' => 25,
                'total' => 1,
                'total_pages' => 1,
            ],
        ]);
    }
}
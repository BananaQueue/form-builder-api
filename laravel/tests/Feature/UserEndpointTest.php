<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserEndpointTest extends TestCase
{
    public function test_user_list_requires_super_admin(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_users.php');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_user_list_matches_legacy_success_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'))->andReturn([
            (object) [
                'id' => 1,
                'username' => 'admin',
                'role' => 'super_admin',
                'created_at' => '2026-06-24 08:00:00',
                'form_count' => 3,
            ],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_users.php');

        $response->assertOk()->assertJson([
            'success' => true,
            'users' => [[
                'id' => 1,
                'username' => 'admin',
                'role' => 'super_admin',
                'created_at' => '2026-06-24 08:00:00',
                'form_count' => 3,
            ]],
        ]);
    }

    public function test_create_user_rejects_weak_password(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/create_user_api.php', [
            'username' => 'newuser',
            'password' => 'short',
            'role' => 'user',
        ]);

        $response->assertStatus(400)->assertJson(['success' => false, 'error' => 'Password must be at least 12 characters']);
    }

    public function test_create_user_hashes_password_and_returns_user_id(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('select')->once()->with('SELECT id FROM users WHERE username = ?', ['newuser'])->andReturn([]);
        DB::shouldReceive('insert')->once()->with('INSERT INTO users (username, role, password_hash) VALUES (?, ?, ?)', \Mockery::on(function (array $values): bool {
            return $values[0] === 'newuser'
                && $values[1] === 'user'
                && $values[2] !== 'StrongPass123'
                && password_verify('StrongPass123', $values[2]);
        }))->andReturnTrue();
        $pdo = new class {
            public function lastInsertId(): string { return '42'; }
        };
        DB::shouldReceive('getPdo')->once()->andReturn($pdo);
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'USER_CREATED' && $row['entity_id'] === 42))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/create_user_api.php', [
            'username' => 'newuser',
            'password' => 'StrongPass123',
            'role' => 'user',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'user_id' => 42]);
    }

    public function test_delete_user_rejects_self_delete(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/delete_user.php', ['user_id' => 1]);

        $response->assertStatus(400)->assertJson(['success' => false, 'error' => 'You cannot delete your own account']);
    }

    public function test_change_password_updates_hash(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('update')->once()->with('UPDATE users SET password_hash = ? WHERE id = ?', \Mockery::on(function (array $values): bool {
            return $values[1] === 7
                && $values[0] !== 'NewStrong123'
                && password_verify('NewStrong123', $values[0]);
        }))->andReturn(1);
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'USER_PASSWORD_CHANGED' && $row['entity_id'] === 7))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/change_password.php', [
            'user_id' => 7,
            'new_password' => 'NewStrong123',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }
}
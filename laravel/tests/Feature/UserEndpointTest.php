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
        $targetQuery = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('users')->andReturn($targetQuery);
        $targetQuery->shouldReceive('select')->once()->with('id', 'role')->andReturnSelf();
        $targetQuery->shouldReceive('where')->once()->with('id', 7)->andReturnSelf();
        $targetQuery->shouldReceive('first')->once()->andReturn((object) ['id' => 7, 'role' => 'user']);

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

    public function test_change_password_rejects_super_admin_target_without_verified_token(): void
    {
        $token = 'csrf-token';
        $targetQuery = \Mockery::mock();
        DB::shouldReceive('table')->with('users')->andReturn($targetQuery);
        $targetQuery->shouldReceive('select')->with('id', 'role')->andReturnSelf();
        $targetQuery->shouldReceive('where')->with('id', 9)->andReturnSelf();
        $targetQuery->shouldReceive('first')->andReturn((object) ['id' => 9, 'role' => 'super_admin']);

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/change_password.php', [
            'user_id' => 9,
            'new_password' => 'NewStrong123',
        ]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'error' => 'Email verification is required to change a Super Admin password',
        ]);
    }

    public function test_change_password_succeeds_for_super_admin_with_verified_reset_token(): void
    {
        $token = 'csrf-token';
        $targetQuery = \Mockery::mock();
        DB::shouldReceive('table')->with('users')->andReturn($targetQuery);
        $targetQuery->shouldReceive('select')->with('id', 'role')->andReturnSelf();
        $targetQuery->shouldReceive('where')->with('id', 9)->andReturnSelf();
        $targetQuery->shouldReceive('first')->andReturn((object) ['id' => 9, 'role' => 'super_admin']);

        $codeQuery = \Mockery::mock();
        DB::shouldReceive('table')->with('password_reset_codes')->andReturn($codeQuery);
        $codeQuery->shouldReceive('where')->andReturnSelf();
        $codeQuery->shouldReceive('whereNotNull')->with('verified_at')->andReturnSelf();
        $codeQuery->shouldReceive('whereNull')->with('used_at')->andReturnSelf();
        $codeQuery->shouldReceive('first')->once()->andReturn((object) ['id' => 55]);
        $codeQuery->shouldReceive('update')->once()->with(\Mockery::on(
            fn (array $row): bool => array_key_exists('used_at', $row)
        ))->andReturn(1);

        DB::shouldReceive('update')->once()->with('UPDATE users SET password_hash = ? WHERE id = ?', \Mockery::on(function (array $values): bool {
            return $values[1] === 9 && password_verify('NewStrong123', $values[0]);
        }))->andReturn(1);

        $audit = \Mockery::mock();
        DB::shouldReceive('table')->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/change_password.php', [
            'user_id' => 9,
            'new_password' => 'NewStrong123',
            'reset_token' => 'verified-token-abc',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }
}
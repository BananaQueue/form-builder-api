<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    private function mockUserLookup(string $correctPassword): void
    {
        $user = (object) [
            'id' => 1,
            'username' => 'admin',
            'password_hash' => password_hash($correctPassword, PASSWORD_BCRYPT),
            'role' => 'super_admin',
        ];

        $query = \Mockery::mock();
        DB::shouldReceive('table')->with('users')->andReturn($query);
        $query->shouldReceive('select')->with('id', 'username', 'password_hash', 'role')->andReturnSelf();
        $query->shouldReceive('where')->with('username', 'admin')->andReturnSelf();
        $query->shouldReceive('first')->andReturn($user);
    }

    public function test_login_rejects_an_incorrect_password_via_password_verify(): void
    {
        $this->mockUserLookup('CorrectPass123');

        $response = $this->postJson('/api/login', [
            'username' => 'admin',
            'password' => 'WrongPassword999',
        ]);

        $response->assertStatus(401)->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_login_locks_out_after_repeated_failures(): void
    {
        $this->mockUserLookup('CorrectPass123');

        // Eight consecutive wrong-password attempts trip the rate limiter.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/login', [
                'username' => 'admin',
                'password' => 'WrongPassword999',
            ])->assertStatus(401);
        }

        // Now even the correct password is rejected because the account/IP is
        // locked -- the limiter short-circuits before the credential check.
        $response = $this->postJson('/api/login', [
            'username' => 'admin',
            'password' => 'CorrectPass123',
        ]);

        $response->assertStatus(401)->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_login_succeeds_with_correct_password_and_audits(): void
    {
        $this->mockUserLookup('CorrectPass123');
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'USER_LOGIN' && $row['actor_username'] === 'admin'))->andReturnTrue();

        $response = $this->postJson('/api/login', [
            'username' => 'admin',
            'password' => 'CorrectPass123',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'username' => 'admin',
            'role' => 'super_admin',
        ]);
        $this->assertTrue(session('logged_in'));
        $this->assertSame(1, session('user_id'));
    }
}

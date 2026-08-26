<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetEmailEndpointTest extends TestCase
{
    private function postEmail(int $actingUserId, int $targetId, string $email): \Illuminate\Testing\TestResponse
    {
        $token = 'csrf-token';

        return $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => $actingUserId,
            'username' => 'acting_admin',
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson("/users/{$targetId}/email", [
            'email' => $email,
        ]);
    }

    public function test_super_admin_can_set_email_for_a_regular_user(): void
    {
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('select')->once()->with('id', 'role', 'email')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn((object) ['id' => 10, 'role' => 'user', 'email' => null]);

            return $query;
        });
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('where')->once()->with('email', 'regular@example.com')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', '!=', 10)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn(null);

            return $query;
        });
        DB::shouldReceive('update')->once()->with('UPDATE users SET email = ? WHERE id = ?', ['regular@example.com', 10])->andReturn(1);
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn(\Mockery::mock(['insert' => true]));

        $response = $this->postEmail(actingUserId: 1, targetId: 10, email: 'regular@example.com');

        $response->assertOk()->assertJson(['success' => true, 'email' => 'regular@example.com']);
    }

    public function test_super_admin_can_bootstrap_email_for_another_super_admin_with_none_set(): void
    {
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('select')->once()->with('id', 'role', 'email')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', 2)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn((object) ['id' => 2, 'role' => 'super_admin', 'email' => null]);

            return $query;
        });
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('where')->once()->with('email', 'newadmin@example.com')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', '!=', 2)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn(null);

            return $query;
        });
        DB::shouldReceive('update')->once()->with('UPDATE users SET email = ? WHERE id = ?', ['newadmin@example.com', 2])->andReturn(1);
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn(\Mockery::mock(['insert' => true]));

        $response = $this->postEmail(actingUserId: 1, targetId: 2, email: 'newadmin@example.com');

        $response->assertOk()->assertJson(['success' => true, 'email' => 'newadmin@example.com']);
    }

    public function test_super_admin_can_change_their_own_already_configured_email(): void
    {
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('select')->once()->with('id', 'role', 'email')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', 1)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn((object) ['id' => 1, 'role' => 'super_admin', 'email' => 'old@example.com']);

            return $query;
        });
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('where')->once()->with('email', 'new@example.com')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', '!=', 1)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn(null);

            return $query;
        });
        DB::shouldReceive('update')->once()->with('UPDATE users SET email = ? WHERE id = ?', ['new@example.com', 1])->andReturn(1);
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn(\Mockery::mock(['insert' => true]));

        $response = $this->postEmail(actingUserId: 1, targetId: 1, email: 'new@example.com');

        $response->assertOk()->assertJson(['success' => true, 'email' => 'new@example.com']);
    }

    public function test_super_admin_cannot_hijack_another_super_admins_configured_email(): void
    {
        // Regression test: this is the takeover path - a Super Admin used to
        // be able to repoint ANY other Super Admin's already-configured
        // recovery email, then walk the legitimate password-reset-code flow
        // using the intercepted code to seize that account.
        DB::shouldReceive('table')->once()->with('users')->andReturnUsing(function () {
            $query = \Mockery::mock();
            $query->shouldReceive('select')->once()->with('id', 'role', 'email')->andReturnSelf();
            $query->shouldReceive('where')->once()->with('id', 2)->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn((object) ['id' => 2, 'role' => 'super_admin', 'email' => 'victim@example.com']);

            return $query;
        });
        DB::shouldReceive('update')->never();

        $response = $this->postEmail(actingUserId: 1, targetId: 2, email: 'attacker@example.com');

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'error' => 'Only the account owner can change an already-configured Super Admin recovery email',
        ]);
    }
}

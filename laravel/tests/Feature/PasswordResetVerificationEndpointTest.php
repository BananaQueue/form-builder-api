<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetVerificationEndpointTest extends TestCase
{
    private function mockPendingCodeRow(): void
    {
        $codeQuery = \Mockery::mock();
        DB::shouldReceive('table')->with('password_reset_codes')->andReturn($codeQuery);
        $codeQuery->shouldReceive('where')->andReturnSelf();
        $codeQuery->shouldReceive('whereNull')->with('used_at')->andReturnSelf();
        $codeQuery->shouldReceive('orderByDesc')->with('id')->andReturnSelf();
        $codeQuery->shouldReceive('first')->andReturn((object) [
            'id' => 1,
            'code_hash' => Hash::make('999999'),
        ]);
    }

    private function verify(string $code, string $token = 'valid-token'): \Illuminate\Testing\TestResponse
    {
        $csrfToken = 'csrf-token';

        return $this->withSession([
            '_token' => $csrfToken,
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $csrfToken)->postJson('/users/9/password-reset-code/verify', [
            'token' => $token,
            'code' => $code,
        ]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->mockPendingCodeRow();

        $this->verify('111111')->assertStatus(400)->assertJson([
            'success' => false,
            'error' => 'Invalid or expired code',
        ]);
    }

    public function test_verify_locks_out_after_five_wrong_attempts(): void
    {
        $this->mockPendingCodeRow();

        for ($i = 0; $i < 5; $i++) {
            $this->verify('111111')->assertStatus(400);
        }

        // A sixth attempt is blocked even though the query would otherwise run again.
        $this->verify('111111')->assertStatus(429)->assertJson([
            'success' => false,
            'error' => 'Too many incorrect attempts. Request a new code.',
        ]);
    }

    public function test_lockout_is_scoped_per_token(): void
    {
        $this->mockPendingCodeRow();

        for ($i = 0; $i < 5; $i++) {
            $this->verify('111111', 'token-a')->assertStatus(400);
        }
        $this->verify('111111', 'token-a')->assertStatus(429);

        // A different pending token for the same target is unaffected.
        $this->verify('111111', 'token-b')->assertStatus(400);
    }
}

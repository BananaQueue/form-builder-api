<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootstrapSuperAdminCommandTest extends TestCase
{
    public function test_it_requires_a_username_and_password(): void
    {
        $this->artisan('fb:bootstrap-super-admin')
            ->expectsOutputToContain('Usage: set FB_BOOTSTRAP_ADMIN_USERNAME')
            ->assertExitCode(1);
    }

    public function test_it_enforces_the_password_policy(): void
    {
        $this->artisan('fb:bootstrap-super-admin', ['username' => 'admin', 'password' => 'short'])
            ->expectsOutputToContain('Password must be at least 12 characters')
            ->assertExitCode(1);
    }

    public function test_it_aborts_when_a_super_admin_already_exists(): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, "role = 'super_admin'") && str_contains($sql, 'FOR UPDATE')))->andReturn([
            (object) ['id' => 1],
        ]);
        DB::shouldReceive('insert')->never();

        $this->artisan('fb:bootstrap-super-admin', ['username' => 'admin', 'password' => 'StrongPass123'])
            ->expectsOutputToContain('A Super Admin already exists')
            ->assertExitCode(1);
    }

    public function test_it_creates_the_super_admin_when_none_exists(): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, "role = 'super_admin'")))->andReturn([]);
        DB::shouldReceive('select')->once()->with('SELECT id FROM users WHERE username = ? FOR UPDATE', ['admin'])->andReturn([]);
        DB::shouldReceive('insert')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, "VALUES (?, 'super_admin', ?)")), \Mockery::on(function (array $params): bool {
            return $params[0] === 'admin'
                && $params[1] !== 'StrongPass123'
                && password_verify('StrongPass123', $params[1]);
        }))->andReturnTrue();

        $this->artisan('fb:bootstrap-super-admin', ['username' => 'admin', 'password' => 'StrongPass123'])
            ->expectsOutputToContain('Super Admin created: admin')
            ->assertExitCode(0);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteFormEndpointTest extends TestCase
{
    public function test_delete_form_requires_authenticated_session(): void
    {
        $response = $this->postJson('/delete_form.php', []);
        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_delete_form_requires_form_id(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/delete_form.php', []);
        $response->assertStatus(400)->assertJson(['error' => 'Form ID is required']);
    }

    public function test_delete_form_returns_not_found(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id, title, created_by FROM forms WHERE id = ?', [10])->andReturn([]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/delete_form.php', ['form_id' => 10]);
        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_delete_form_blocks_non_owner(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id, title, created_by FROM forms WHERE id = ?', [10])->andReturn([(object) ['id' => 10, 'title' => 'Other', 'created_by' => 9]]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/delete_form.php', ['form_id' => 10]);
        $response->assertStatus(403)->assertJson(['error' => 'You can only delete your own forms']);
    }

    public function test_delete_form_requires_reason_for_super_admin(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id, title, created_by FROM forms WHERE id = ?', [10])->andReturn([(object) ['id' => 10, 'title' => 'Owned', 'created_by' => 5]]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/delete_form.php', ['form_id' => 10]);
        $response->assertStatus(400)->assertJson(['error' => 'Deletion reason is required']);
    }

    public function test_delete_form_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id, title, created_by FROM forms WHERE id = ?', [10])->andReturn([(object) ['id' => 10, 'title' => 'Mine', 'created_by' => 5]]);
            DB::shouldReceive('delete')->once()->with('DELETE FROM forms WHERE id = ?', [10])->andReturn(1);
            $audit = \Mockery::mock();
            DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
            $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'user1', 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/delete_form.php', ['form_id' => 10]);
        $response->assertOk()->assertJson(['success' => true, 'message' => 'Form deleted successfully']);
    }
}
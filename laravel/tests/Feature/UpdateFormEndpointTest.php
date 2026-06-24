<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateFormEndpointTest extends TestCase
{
    public function test_update_form_requires_authenticated_session(): void
    {
        $response = $this->postJson('/update_form.php', []);
        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_update_form_rejects_invalid_payload(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10]);
        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_update_form_returns_not_found(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Missing', 'questions' => []]);
        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_update_form_blocks_non_owner(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([(object) ['id' => 10, 'title' => 'Other', 'created_by' => 9, 'owner_username' => 'other']]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Other', 'questions' => []]);
        $response->assertStatus(403)->assertJson(['error' => 'You can only edit your own forms']);
    }

    public function test_update_form_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([(object) ['id' => 10, 'title' => 'Old', 'created_by' => 5, 'owner_username' => 'user1']]);
            DB::shouldReceive('update')->once()->with('UPDATE forms SET title = ?, description = ?, category_id = ?, privacy_notice = ?, step_mode = ? WHERE id = ?', ['Updated', 'Desc', 1, 1, 0, 10])->andReturn(1);
            DB::shouldReceive('select')->once()->with('SELECT id FROM questions WHERE form_id = ?', [10])->andReturn([(object) ['id' => 21]]);
            DB::shouldReceive('update')->once()->with(\Mockery::type('string'), \Mockery::type('array'))->andReturn(1);
            DB::shouldReceive('delete')->once()->with('DELETE FROM question_options WHERE question_id = ?', [21])->andReturn(1);
            DB::shouldReceive('insert')->once()->with('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', [21, 'Yes', 0])->andReturnTrue();
            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'user1', 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Updated', 'description' => 'Desc', 'category_id' => 1, 'step_mode' => 0, 'questions' => [['id' => 21, 'text' => 'Ready?', 'type' => 'multiple_choice', 'options' => ['Yes']]]]);

        $response->assertOk()->assertJson(['success' => true, 'message' => 'Form updated successfully', 'form_id' => 10]);
    }
}
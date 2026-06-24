<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaveFormEndpointTest extends TestCase
{
    public function test_save_form_requires_authenticated_session(): void
    {
        $response = $this->postJson('/save_form.php', []);
        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_save_form_rejects_invalid_payload(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/save_form.php', ['title' => 'Missing questions']);
        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_save_form_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE form_code = ?', \Mockery::type('array'))->andReturn([]);
            DB::shouldReceive('insert')->once()->with('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', \Mockery::on(fn ($values) => $values[0] === 'Safety Check' && $values[2] === 1 && $values[4] === 5))->andReturnTrue();
            $pdo = new class {
                public int $next = 100;
                public function lastInsertId(): string { return (string) $this->next++; }
            };
            DB::shouldReceive('getPdo')->times(3)->andReturn($pdo);
            DB::shouldReceive('insert')->twice()->with('INSERT INTO questions (form_id, question_text, question_type, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, condition_question_id, condition_type, condition_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', \Mockery::type('array'))->andReturnTrue();
            DB::shouldReceive('insert')->once()->with('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', ['101', 'Yes', 0])->andReturnTrue();
            DB::shouldReceive('insert')->once()->with('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', ['101', 'No', 1])->andReturnTrue();
            DB::shouldReceive('update')->once()->with('UPDATE questions SET condition_question_id = ?, condition_type = ?, condition_value = ? WHERE id = ?', ['101', 'equals', 'Yes', '102'])->andReturn(1);
            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'admin', 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/save_form.php', [
                'title' => 'Safety Check',
                'description' => 'Daily',
                'category_id' => 1,
                'step_mode' => 0,
                'questions' => [
                    ['id' => 'q1', 'text' => 'Ready?', 'type' => 'multiple_choice', 'options' => ['Yes', 'No'], 'is_required' => 1],
                    ['id' => 'q2', 'text' => 'Explain', 'type' => 'text', 'condition_question_id' => 'q1', 'condition_type' => 'equals', 'condition_value' => 'Yes'],
                ],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'message' => 'Form saved successfully', 'form_id' => '100'])
            ->assertJsonStructure(['form_code']);
        $this->assertStringStartsWith('safety-check-', $response->json('form_code'));
    }
}
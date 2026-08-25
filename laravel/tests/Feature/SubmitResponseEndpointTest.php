<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubmitResponseEndpointTest extends TestCase
{
    public function test_submit_response_returns_not_found_for_missing_form(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE id = ?', [999])->andReturn([]);

        $response = $this->postJson('/api/public/forms/999/responses', [
            'answers' => [],
        ]);

        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_submit_response_rejects_question_ids_outside_form(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE id = ?', [10])->andReturn([(object) ['id' => 10]]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 21, 'question_text' => 'Email', 'question_type' => 'email', 'number_min' => null, 'number_max' => null, 'is_required' => 1, 'condition_question_id' => null, 'condition_type' => 'equals', 'condition_value' => null],
        ]);

        $response = $this->postJson('/api/public/forms/10/responses', [
            'answers' => [['question_id' => 99, 'answer_text' => 'x@example.com']],
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_native_public_submission_route_injects_form_id_from_url(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE id = ?', [10])->andReturn([(object) ['id' => 10]]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 21, 'question_text' => 'Email', 'question_type' => 'email', 'number_min' => null, 'number_max' => null, 'is_required' => 1, 'condition_question_id' => null, 'condition_type' => 'equals', 'condition_value' => null],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [21])->andReturn([]);
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('insert')->once()->with('INSERT INTO responses (form_id) VALUES (?)', [10])->andReturnTrue();
            DB::shouldReceive('getPdo')->once()->andReturn(new class {
                public function lastInsertId(): string
                {
                    return '55';
                }
            });
            DB::shouldReceive('insert')->once()->with('INSERT INTO answers (response_id, question_id, question_text, question_type, answer_text) VALUES (?, ?, ?, ?, ?)', ['55', 21, 'Email', 'email', 'person@example.com'])->andReturnTrue();

            return $callback();
        });

        // Note: no form_id in the body — it comes from the {id} route segment.
        $response = $this->postJson('/api/public/forms/10/responses', [
            'answers' => [['question_id' => 21, 'answer_text' => 'person@example.com']],
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Response submitted successfully',
            'response_id' => '55',
        ]);
    }
}
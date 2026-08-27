<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubmitResponseEndpointTest extends TestCase
{
    public function test_submit_response_returns_not_found_for_missing_form(): void
    {
        DB::shouldReceive('selectOne')->once()->with('SELECT form_code FROM forms WHERE id = ?', [999])->andReturn(null);

        $response = $this->postJson('/api/public/forms/999/responses', [
            'form_code' => 'whatever-1234567',
            'answers' => [],
        ]);

        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_submit_response_requires_form_code(): void
    {
        $response = $this->postJson('/api/public/forms/10/responses', [
            'answers' => [],
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_submit_response_rejects_the_right_id_with_the_wrong_code(): void
    {
        // Regression test: the id alone used to be sufficient, so anyone
        // could walk id=1,2,3... and inject responses into forms whose
        // share link was never distributed. The code is the actual
        // capability now, not just the id.
        DB::shouldReceive('selectOne')->once()->with('SELECT form_code FROM forms WHERE id = ?', [10])->andReturn((object) ['form_code' => 'daily-inspection-abc1234']);

        $response = $this->postJson('/api/public/forms/10/responses', [
            'form_code' => 'totally-guessed-wrong',
            'answers' => [],
        ]);

        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_submit_response_rate_limits_repeated_wrong_code_guesses(): void
    {
        // Regression test: recordRateLimitAttempt() used to run only after a
        // code match succeeded, so a wrong guess never counted against the
        // limit and isRateLimited() could never trip - an attacker could
        // brute-force the share code (or walk ids) at unlimited speed as
        // long as every guess kept failing. Every attempt must count,
        // matched or not.
        DB::shouldReceive('selectOne')
            ->times(20)
            ->with('SELECT form_code FROM forms WHERE id = ?', [10])
            ->andReturn((object) ['form_code' => 'daily-inspection-abc1234']);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/public/forms/10/responses', [
                'form_code' => 'totally-guessed-wrong-'.$i,
                'answers' => [],
            ]);
            $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
        }

        // The 21st attempt is blocked before the form is even looked up -
        // the mock's ->times(20) above would fail the test if it were called
        // again here.
        $response = $this->postJson('/api/public/forms/10/responses', [
            'form_code' => 'totally-guessed-wrong-20',
            'answers' => [],
        ]);
        $response->assertStatus(429)->assertJson(['error' => 'Too many submissions. Please try again later.']);
    }

    public function test_submit_response_accepts_a_code_whose_slug_is_stale_but_suffix_matches(): void
    {
        // A form's share URL is rebuilt from its CURRENT title every time,
        // but forms.form_code is fixed at creation - so a link generated
        // before a later title edit carries a different slug but the same
        // suffix. That link must keep working.
        DB::shouldReceive('selectOne')->once()->with('SELECT form_code FROM forms WHERE id = ?', [10])->andReturn((object) ['form_code' => 'old-title-abc1234']);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('insert')->once()->with('INSERT INTO responses (form_id) VALUES (?)', [10])->andReturnTrue();
            DB::shouldReceive('getPdo')->once()->andReturn(new class {
                public function lastInsertId(): string
                {
                    return '77';
                }
            });

            return $callback();
        });

        $response = $this->postJson('/api/public/forms/10/responses', [
            'form_code' => 'brand-new-title-abc1234',
            'answers' => [],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_submit_response_rejects_question_ids_outside_form(): void
    {
        DB::shouldReceive('selectOne')->once()->with('SELECT form_code FROM forms WHERE id = ?', [10])->andReturn((object) ['form_code' => 'daily-inspection-abc1234']);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 21, 'question_text' => 'Email', 'question_type' => 'email', 'number_min' => null, 'number_max' => null, 'is_required' => 1, 'condition_question_id' => null, 'condition_type' => 'equals', 'condition_value' => null],
        ]);

        $response = $this->postJson('/api/public/forms/10/responses', [
            'form_code' => 'daily-inspection-abc1234',
            'answers' => [['question_id' => 99, 'answer_text' => 'x@example.com']],
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_native_public_submission_route_injects_form_id_from_url(): void
    {
        DB::shouldReceive('selectOne')->once()->with('SELECT form_code FROM forms WHERE id = ?', [10])->andReturn((object) ['form_code' => 'daily-inspection-abc1234']);
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
            'form_code' => 'daily-inspection-abc1234',
            'answers' => [['question_id' => 21, 'answer_text' => 'person@example.com']],
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Response submitted successfully',
            'response_id' => '55',
        ]);
    }
}

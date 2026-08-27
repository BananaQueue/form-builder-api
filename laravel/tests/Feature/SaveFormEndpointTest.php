<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaveFormEndpointTest extends TestCase
{
    public function test_save_form_requires_authenticated_session(): void
    {
        $response = $this->postJson('/api/forms', []);
        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_save_form_rejects_invalid_payload(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/forms', ['title' => 'Missing questions']);
        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_save_form_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE RIGHT(form_code, LENGTH(?)) = ?', \Mockery::type('array'))->andReturn([]);
            DB::shouldReceive('insert')->once()->with('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', \Mockery::on(fn ($values) => $values[0] === 'Safety Check' && $values[2] === 1 && $values[4] === 5))->andReturnTrue();
            $pdo = new class {
                public int $next = 100;
                public function lastInsertId(): string { return (string) $this->next++; }
            };
            DB::shouldReceive('getPdo')->times(3)->andReturn($pdo);
            DB::shouldReceive('insert')->twice()->with('INSERT INTO questions (form_id, question_text, question_type, description, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, condition_question_id, condition_type, condition_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', \Mockery::type('array'))->andReturnTrue();
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
            ->postJson('/api/forms', [
                'title' => 'Safety Check',
                'description' => 'Daily',
                'category_id' => 1,
                'step_mode' => 0,
                'questions' => [
                    ['id' => 'q1', 'text' => 'Ready?', 'type' => 'multiple_choice', 'description' => 'Pick one', 'options' => ['Yes', 'No'], 'is_required' => 1],
                    ['id' => 'q2', 'text' => 'Explain', 'type' => 'text', 'condition_question_id' => 'q1', 'condition_type' => 'equals', 'condition_value' => 'Yes'],
                ],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'message' => 'Form saved successfully', 'form_id' => '100'])
            ->assertJsonStructure(['form_code']);
        $this->assertStringStartsWith('safety-check-', $response->json('form_code'));
    }
    public function test_save_form_caps_long_generated_form_codes_to_schema_length(): void
    {
        $token = 'csrf-token';
        $capturedFormCode = null;

        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$capturedFormCode) {
            DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE RIGHT(form_code, LENGTH(?)) = ?', \Mockery::type('array'))->andReturn([]);
            DB::shouldReceive('insert')->once()->with('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', \Mockery::on(function ($values) use (&$capturedFormCode) {
                $capturedFormCode = $values[3] ?? '';

                return $values[0] === 'Employee Details Form With A Very Long Name (Copy)'
                    && strlen($capturedFormCode) <= 20
                    && preg_match('/^[a-z0-9-]+-[A-Za-z0-9]{7}$/', $capturedFormCode) === 1;
            }))->andReturnTrue();
            $pdo = new class {
                public function lastInsertId(): string { return '200'; }
            };
            DB::shouldReceive('getPdo')->once()->andReturn($pdo);

            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'admin', 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/forms', [
                'title' => 'Employee Details Form With A Very Long Name (Copy)',
                'description' => 'Duplicated form',
                'category_id' => 1,
                'step_mode' => 0,
                'questions' => [],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'message' => 'Form saved successfully', 'form_id' => '200'])
            ->assertJson(['form_code' => $capturedFormCode]);
        $this->assertLessThanOrEqual(20, strlen($response->json('form_code')));
    }

    public function test_save_form_retries_when_the_generated_codes_suffix_collides(): void
    {
        // Regression test: forms.form_code is always stored as "slug-code"
        // (see generateFormCodeWithSlug), never the bare code alone, so a
        // naive WHERE form_code = ? collision check against just the random
        // code could never match an existing row - it always "passed" on
        // the first try no matter what, even when the code's suffix (the
        // part FormCodeMatcher actually treats as the unique, unguessable
        // capability) was already in use by another form. The check has to
        // compare against that trailing suffix instead, and retry when it
        // collides.
        $token = 'csrf-token';
        $seenCodes = [];

        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$seenCodes) {
            DB::shouldReceive('select')
                ->twice()
                ->with('SELECT id FROM forms WHERE RIGHT(form_code, LENGTH(?)) = ?', \Mockery::on(function (array $bindings) use (&$seenCodes): bool {
                    if (count($bindings) !== 2 || $bindings[0] !== $bindings[1] || strlen($bindings[0]) !== 7) {
                        return false;
                    }
                    $seenCodes[] = $bindings[0];

                    return true;
                }))
                ->andReturn([(object) ['id' => 1]], []);
            DB::shouldReceive('insert')->once()->with('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', \Mockery::type('array'))->andReturnTrue();
            $pdo = new class {
                public function lastInsertId(): string { return '400'; }
            };
            DB::shouldReceive('getPdo')->once()->andReturn($pdo);

            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'admin', 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/forms', [
                'title' => 'Retry Check',
                'questions' => [],
            ]);

        $response->assertOk()->assertJson(['success' => true, 'form_id' => '400']);
        $this->assertCount(2, $seenCodes, 'a colliding suffix should force a second, freshly-generated candidate to be checked');
    }

    public function test_native_create_form_route_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with('SELECT id FROM forms WHERE RIGHT(form_code, LENGTH(?)) = ?', \Mockery::type('array'))->andReturn([]);
            DB::shouldReceive('insert')->once()->with('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', \Mockery::on(fn ($values) => $values[0] === 'Native Route Form'))->andReturnTrue();
            $pdo = new class {
                public function lastInsertId(): string { return '300'; }
            };
            DB::shouldReceive('getPdo')->once()->andReturn($pdo);

            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::type('array'))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'admin', 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/forms', [
                'title' => 'Native Route Form',
                'description' => 'Created via native route',
                'category_id' => 1,
                'step_mode' => 0,
                'questions' => [],
            ]);

        $response->assertOk()->assertJson(['success' => true, 'message' => 'Form saved successfully', 'form_id' => '300']);
    }
}

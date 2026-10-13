<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Checkbox answers are stored as one comma-joined string (see
 * LegacySubmissionController::normalizeAnswerText / splitAnswerOptions), so a
 * checkbox option whose own text contains a comma would be read back as two
 * options -- corrupting stored answers and conditional-question visibility.
 * Such options are refused when a form is saved or updated.
 */
class CheckboxOptionCommaTest extends TestCase
{
    private function asAdmin(): static
    {
        $token = 'csrf-token';

        return $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'admin', 'role' => 'super_admin'])
            ->withHeader('X-CSRF-TOKEN', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(string $type, array $options, array $extra = []): array
    {
        return [
            'title' => 'Survey',
            'description' => '',
            'category_id' => 1,
            'step_mode' => 0,
            'questions' => [
                ['id' => 'q1', 'text' => 'Which apply?', 'type' => $type, 'options' => $options, 'is_required' => 1],
            ],
            ...$extra,
        ];
    }

    public function test_saving_a_form_with_a_comma_in_a_checkbox_option_is_refused(): void
    {
        DB::shouldReceive('transaction')->never();

        $this->asAdmin()
            ->postJson('/api/forms', $this->form('checkbox', ['Water, air', 'Waste']))
            ->assertStatus(400)
            ->assertJson(['error' => 'Checkbox options cannot contain commas: "Water, air". Use another word or symbol, e.g. "Water and air" or "Water / air".']);
    }

    public function test_updating_a_form_with_a_comma_in_a_checkbox_option_is_refused(): void
    {
        DB::shouldReceive('transaction')->never();

        $this->asAdmin()
            ->putJson('/api/forms/7', $this->form('checkbox', ['Waste', 'Permits, licenses']))
            ->assertStatus(400)
            ->assertJson(['error' => 'Checkbox options cannot contain commas: "Permits, licenses". Use another word or symbol, e.g. "Water and air" or "Water / air".']);
    }

    public function test_the_question_type_key_used_by_older_clients_is_checked_too(): void
    {
        DB::shouldReceive('transaction')->never();

        $payload = $this->form('text', []);
        $payload['questions'][0] = ['id' => 'q1', 'question_text' => 'Which apply?', 'question_type' => 'checkbox', 'options' => ['A, B']];

        $this->asAdmin()->postJson('/api/forms', $payload)->assertStatus(400);
    }

    public function test_commas_in_multiple_choice_options_are_still_allowed(): void
    {
        // Reaching the transaction means validation passed; stop there.
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('stop after validation'));

        $this->asAdmin()
            ->postJson('/api/forms', $this->form('multiple_choice', ['Yes, definitely', 'No']))
            ->assertStatus(500)
            ->assertJson(['error' => 'Failed to save form']);
    }

    public function test_checkbox_options_without_commas_are_accepted(): void
    {
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('stop after validation'));

        $this->asAdmin()
            ->postJson('/api/forms', $this->form('checkbox', ['Water and air', 'Waste / chemicals']))
            ->assertStatus(500)
            ->assertJson(['error' => 'Failed to save form']);
    }
}

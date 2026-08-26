<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicFormEndpointTest extends TestCase
{
    public function test_public_form_lookup_matches_legacy_success_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['ABC123'])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
                'privacy_notice' => 1,
                'step_mode' => 0,
                'category_id' => 1,
                'category_name' => 'General',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) [
                'id' => 21,
                'question_text' => 'Status?',
                'question_type' => 'dropdown',
                'description' => 'Choose the current status',
                'rating_scale' => null,
                'number_min' => null,
                'number_max' => null,
                'number_step' => null,
                'datetime_type' => null,
                'position' => 1,
                'is_required' => 1,
                'condition_question_id' => null,
                'condition_type' => 'equals',
                'condition_value' => null,
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [21])->andReturn([
            (object) ['option_text' => 'Open', 'position' => 1],
            (object) ['option_text' => 'Closed', 'position' => 2],
        ]);

        $response = $this->get('/api/public/forms/ABC123');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => [
                'id' => 10,
                'title' => 'Inspection',
                'privacy_notice' => 1,
                'step_mode' => 0,
                'questions' => [[
                    'id' => 21,
                    'question_text' => 'Status?',
                    'question_type' => 'dropdown',
                    'description' => 'Choose the current status',
                    'options' => ['Open', 'Closed'],
                ]],
            ],
        ]);
        // form_code is internal to matching, never part of the public payload.
        $response->assertJsonMissingPath('form.form_code');
    }

    public function test_public_form_lookup_finds_a_link_rebuilt_with_a_longer_untruncated_title_slug(): void
    {
        // Regression test: generateFormCodeWithSlug truncates the slug to a
        // short max length at creation, but the frontend rebuilds share
        // URLs from the form's CURRENT, full, untruncated title every time
        // one is generated. For any real title whose slug exceeds that
        // truncation length - nearly all of them - the app's own
        // self-generated share link never exactly matched the stored code,
        // and the old bare-suffix-only fallback didn't cover this case
        // either (it only ever matched a stored code with NO slug at all).
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['employee-satisfaction-survey-2026-r1KUj5o'])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['r1KUj5o', 'r1KUj5o'])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'employee-sat-r1KUj5o',
                'title' => 'Employee Satisfaction Survey 2026',
                'description' => null,
                'privacy_notice' => 1,
                'step_mode' => 0,
                'category_id' => 1,
                'category_name' => 'General',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);

        $response = $this->get('/api/public/forms/employee-satisfaction-survey-2026-r1KUj5o');

        $response->assertOk()->assertJson(['success' => true, 'form' => ['id' => 10, 'questions' => []]]);
    }

    public function test_public_form_lookup_tries_slug_tail_code(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['daily-form-ABC123'])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['ABC123', 'ABC123'])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => null,
                'privacy_notice' => 0,
                'step_mode' => 0,
                'category_id' => 1,
                'category_name' => 'General',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);

        $response = $this->get('/api/public/forms/daily-form-ABC123');

        $response->assertOk()->assertJson(['success' => true, 'form' => ['id' => 10, 'questions' => []]]);
    }

    public function test_public_form_lookup_does_not_fall_back_to_wildcard_match(): void
    {
        // Regression test: a code that reduces to a bare '%' after the slug
        // split used to bind a LIKE query as "%-%", matching every
        // form_code in the table (all shaped "slug-code") and disclosing an
        // arbitrary form with no valid code. The current suffix fallback
        // uses RIGHT()/LENGTH() - an exact value comparison, not a LIKE
        // pattern - so a literal '%' in the bound value can only ever match
        // a form_code whose actual last character is a literal '%', which
        // no real code (alphanumeric only) has.
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['some-%'])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['%', '%'])->andReturn([]);

        $response = $this->get('/api/public/forms/some-%25');

        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_public_form_lookup_rejects_a_suffix_match_that_isnt_really_the_same_code(): void
    {
        // The RIGHT()/LENGTH() query only narrows candidates; FormCodeMatcher
        // makes the actual accept/reject decision. A row whose form_code
        // ends the same way by pure coincidence, but whose own last
        // hyphen-segment differs from the submitted suffix, must not match.
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['weird-title-1234567'])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['1234567', '1234567'])->andReturn([
            (object) [
                'id' => 99,
                'form_code' => 'unrelated-form-code-1234567-extra',
                'title' => 'Unrelated',
                'description' => null,
                'privacy_notice' => 1,
                'step_mode' => 0,
                'category_id' => 1,
                'category_name' => 'General',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);

        $response = $this->get('/api/public/forms/weird-title-1234567');

        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }
}

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
    }

    public function test_public_form_lookup_tries_slug_tail_code(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['daily-form-ABC123'])->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['ABC123'])->andReturn([
            (object) [
                'id' => 10,
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
}
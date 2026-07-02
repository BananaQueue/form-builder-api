<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResponseEndpointTest extends TestCase
{
    public function test_response_list_requires_authenticated_session(): void
    {
        $response = $this->get('/get_responses.php?form_id=10');

        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_response_list_blocks_non_owner(): void
    {
        $query = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('forms')->andReturn($query);
        $query->shouldReceive('select')->once()->with('id', 'title', 'created_by')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'title' => 'Other Form',
            'created_by' => 99,
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_responses.php?form_id=10');

        $response->assertStatus(403)->assertJson(['error' => 'You do not have permission to view responses for this form']);
    }

    public function test_response_list_matches_legacy_success_shape(): void
    {
        $query = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('forms')->andReturn($query);
        $query->shouldReceive('select')->once()->with('id', 'title', 'created_by')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'title' => 'Inspection',
            'created_by' => 5,
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00', 'answer_count' => 2],
            (object) ['id' => 100, 'submitted_at' => '2026-06-23 09:00:00', 'answer_count' => 1],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_responses.php?form_id=10');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => ['id' => 10, 'title' => 'Inspection'],
            'responses' => [
                ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00', 'answer_count' => 2],
                ['id' => 100, 'submitted_at' => '2026-06-23 09:00:00', 'answer_count' => 1],
            ],
            'total_responses' => 2,
        ]);
    }

    public function test_response_details_requires_id(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_response_details.php');

        $response->assertStatus(400)->assertJson(['error' => 'Response ID is required']);
    }

    public function test_response_details_matches_legacy_success_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101])->andReturn([
            (object) [
                'id' => 101,
                'form_id' => 10,
                'submitted_at' => '2026-06-24 09:00:00',
                'form_title' => 'Inspection',
                'form_owner_id' => 5,
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101])->andReturn([
            (object) [
                'id' => 201,
                'question_id' => 21,
                'answer_text' => 'Open',
                'question_text' => 'Status?',
                'question_type' => 'dropdown',
                'description' => 'Current workflow status',
            ],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_response_details.php?id=101');

        $response->assertOk()->assertJson([
            'success' => true,
            'response' => [
                'id' => 101,
                'form_id' => 10,
                'submitted_at' => '2026-06-24 09:00:00',
                'form_title' => 'Inspection',
                'answers' => [[
                    'id' => 201,
                    'question_id' => 21,
                    'answer_text' => 'Open',
                    'question_text' => 'Status?',
                    'question_type' => 'dropdown',
                    'description' => 'Current workflow status',
                ]],
            ],
        ])->assertJsonMissing(['form_owner_id' => 5]);
    }

    public function test_native_response_list_route_maps_path_form_id_to_legacy_shape(): void
    {
        $query = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('forms')->andReturn($query);
        $query->shouldReceive('select')->once()->with('id', 'title', 'created_by')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'title' => 'Inspection',
            'created_by' => 5,
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00', 'answer_count' => 2],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/forms/10/responses');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => ['id' => 10, 'title' => 'Inspection'],
            'responses' => [
                ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00', 'answer_count' => 2],
            ],
            'total_responses' => 1,
        ]);
    }

    public function test_native_response_details_route_maps_path_response_id_to_legacy_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101])->andReturn([
            (object) [
                'id' => 101,
                'form_id' => 10,
                'submitted_at' => '2026-06-24 09:00:00',
                'form_title' => 'Inspection',
                'form_owner_id' => 5,
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101])->andReturn([]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/responses/101');

        $response->assertOk()->assertJson([
            'success' => true,
            'response' => [
                'id' => 101,
                'form_id' => 10,
                'form_title' => 'Inspection',
                'answers' => [],
            ],
        ])->assertJsonMissing(['form_owner_id' => 5]);
    }

    public function test_native_export_responses_route_maps_path_form_id_to_csv_download(): void
    {
        $formQuery = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('forms')->andReturn($formQuery);
        $formQuery->shouldReceive('select')->once()->with('id', 'title', 'created_by')->andReturnSelf();
        $formQuery->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $formQuery->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'title' => 'Inspection Form',
            'created_by' => 5,
        ]);

        $auditQuery = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($auditQuery);
        $auditQuery->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'RESPONSES_EXPORTED' && $row['entity_id'] === 10))->andReturnTrue();

        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 21, 'question_text' => 'Status?', 'position' => 1],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00'],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101, 21])->andReturn([
            (object) ['response_id' => 101, 'question_id' => 21, 'answer_text' => 'Open'],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/forms/10/responses/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="Inspection_Form_responses_', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame("\"Submitted At\",Status?\n\"2026-06-24 09:00:00\",Open\n", $response->getContent());
    }
    public function test_export_responses_requires_form_id(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/export_responses.php');

        $response->assertStatus(400)->assertJson(['error' => 'Form ID is required']);
    }

    public function test_export_responses_returns_csv_download(): void
    {
        $formQuery = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('forms')->andReturn($formQuery);
        $formQuery->shouldReceive('select')->once()->with('id', 'title', 'created_by')->andReturnSelf();
        $formQuery->shouldReceive('where')->once()->with('id', 10)->andReturnSelf();
        $formQuery->shouldReceive('first')->once()->andReturn((object) [
            'id' => 10,
            'title' => 'Inspection Form',
            'created_by' => 5,
        ]);

        $auditQuery = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($auditQuery);
        $auditQuery->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'RESPONSES_EXPORTED' && $row['entity_id'] === 10))->andReturnTrue();

        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 21, 'question_text' => 'Status?', 'position' => 1],
            (object) ['id' => 22, 'question_text' => 'Notes', 'position' => 2],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) ['id' => 101, 'submitted_at' => '2026-06-24 09:00:00'],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [101, 21, 22])->andReturn([
            (object) ['response_id' => 101, 'question_id' => 21, 'answer_text' => 'Open'],
            (object) ['response_id' => 101, 'question_id' => 22, 'answer_text' => 'Looks good'],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'user',
        ])->get('/export_responses.php?form_id=10');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="Inspection_Form_responses_', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame("\"Submitted At\",Status?,Notes\n\"2026-06-24 09:00:00\",Open,\"Looks good\"\n", $response->getContent());
    }
}
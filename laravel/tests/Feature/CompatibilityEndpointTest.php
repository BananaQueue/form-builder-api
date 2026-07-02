<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompatibilityEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_laravel_backend(): void
    {
        $response = $this->get('/_fb_laravel_health');

        $response->assertOk()->assertJson(['ok' => true, 'app' => 'Form Builder Laravel', 'backend' => 'laravel']);
    }

    public function test_check_session_matches_legacy_guest_shape(): void
    {
        $response = $this->get('/check_session.php');

        $response->assertOk()->assertJson(['logged_in' => false]);
    }

    public function test_check_session_matches_legacy_authenticated_shape(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/check_session.php');

        $response->assertOk()
            ->assertJson(['logged_in' => true, 'username' => 'admin', 'user_id' => 1, 'role' => 'super_admin'])
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_login_requires_username_and_password_without_csrf(): void
    {
        $response = $this->postJson('/login.php', []);

        $response->assertStatus(400)->assertJson(['error' => 'Username and password are required']);
    }

    public function test_logout_matches_legacy_success_shape_and_clears_session(): void
    {
        $token = 'test-csrf-token';

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/logout.php');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFalse(session()->has('logged_in'));
        $this->assertFalse(session()->has('username'));
    }

    public function test_categories_matches_legacy_success_shape(): void
    {
        $query = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('categories')->andReturn($query);
        $query->shouldReceive('select')->once()->with('id', 'name')->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('name')->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect([
            ['id' => 2, 'name' => 'External'],
            ['id' => 1, 'name' => 'General'],
        ]));

        $response = $this->get('/get_categories.php');

        $response->assertOk()->assertJson([
            'success' => true,
            'categories' => [
                ['id' => 2, 'name' => 'External'],
                ['id' => 1, 'name' => 'General'],
            ],
        ]);
    }

    public function test_forms_requires_authenticated_session(): void
    {
        $response = $this->get('/get_forms.php');

        $response->assertStatus(401)->assertJson(['error' => 'Not authenticated']);
    }

    public function test_forms_matches_legacy_success_shape_for_current_user(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'f.form_code') && str_contains($sql, 'GROUP BY') && str_contains($sql, 'f.description') && str_contains($sql, 'f.category_id')), [5])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
                'created_at' => '2026-06-23 10:00:00',
                'category_id' => 1,
                'category_name' => 'General',
                'question_count' => 3,
                'response_count' => 2,
            ],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'user1',
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_forms.php');

        $response->assertOk()->assertJson([
            'success' => true,
            'forms' => [[
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
                'category_id' => 1,
                'category_name' => 'General',
                'question_count' => 3,
                'response_count' => 2,
            ]],
        ]);
    }

    public function test_forms_allows_super_admin_to_scope_by_user_id(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'f.form_code') && str_contains($sql, 'GROUP BY') && str_contains($sql, 'f.description') && str_contains($sql, 'f.category_id')), [7])->andReturn([]);

        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_forms.php?user_id=7');

        $response->assertOk()->assertJson(['success' => true, 'forms' => []]);
    }

    public function test_form_details_requires_id(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_form_details.php');

        $response->assertStatus(400)->assertJson(['error' => 'Form ID is required']);
    }

    public function test_form_details_blocks_non_owner(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'privacy_notice' => 0,
                'step_mode' => 0,
                'title' => 'Other Form',
                'description' => null,
                'category_id' => 1,
                'category_name' => 'General',
                'created_by' => 99,
                'question_count' => 0,
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_form_details.php?id=10');

        $response->assertStatus(403)->assertJson(['error' => 'You can only view your own forms']);
    }

    public function test_form_details_matches_legacy_success_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'privacy_notice' => 1,
                'step_mode' => 0,
                'title' => 'Inspection',
                'description' => 'Daily check',
                'category_id' => 1,
                'category_name' => 'General',
                'created_by' => 5,
                'question_count' => 1,
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) [
                'id' => 21,
                'question_text' => 'Status?',
                'question_type' => 'dropdown',
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

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_form_details.php?id=10');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => [
                'id' => 10,
                'form_code' => 'ABC123',
                'privacy_notice' => 1,
                'step_mode' => 0,
                'title' => 'Inspection',
                'description' => 'Daily check',
                'category_id' => 1,
                'category_name' => 'General',
                'question_count' => 1,
                'questions' => [[
                    'id' => 21,
                    'question_text' => 'Status?',
                    'question_type' => 'dropdown',
                    'options' => ['Open', 'Closed'],
                ]],
            ],
        ])->assertJsonMissing(['created_by' => 5]);
    }

    public function test_native_categories_route_matches_legacy_success_shape(): void
    {
        $query = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('categories')->andReturn($query);
        $query->shouldReceive('select')->once()->with('id', 'name')->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('name')->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect([
            ['id' => 1, 'name' => 'General'],
        ]));

        $response = $this->get('/api/categories');

        $response->assertOk()->assertJson([
            'success' => true,
            'categories' => [
                ['id' => 1, 'name' => 'General'],
            ],
        ]);
    }

    public function test_native_forms_route_matches_legacy_success_shape_for_current_user(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'f.form_code') && str_contains($sql, 'GROUP BY')), [5])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
                'created_at' => '2026-06-23 10:00:00',
                'category_id' => 1,
                'category_name' => 'General',
                'question_count' => 3,
                'response_count' => 2,
            ],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'username' => 'user1',
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/forms');

        $response->assertOk()->assertJson([
            'success' => true,
            'forms' => [[
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
            ]],
        ]);
    }

    public function test_native_form_details_route_maps_path_id_to_legacy_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'privacy_notice' => 1,
                'step_mode' => 0,
                'title' => 'Inspection',
                'description' => 'Daily check',
                'category_id' => 1,
                'category_name' => 'General',
                'created_by' => 5,
                'question_count' => 0,
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/api/forms/10');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => [
                'id' => 10,
                'title' => 'Inspection',
                'questions' => [],
            ],
        ]);
    }

    public function test_native_public_form_route_maps_path_code_to_legacy_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), ['ABC123'])->andReturn([
            (object) [
                'id' => 10,
                'title' => 'Public Form',
                'description' => 'Open to public',
                'privacy_notice' => null,
                'step_mode' => 0,
                'category_id' => 1,
                'category_name' => 'General',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);
        DB::shouldReceive('select')->once()->with('SELECT id, question_text, question_type, description, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, condition_question_id, condition_type, condition_value FROM questions WHERE form_id = ? AND is_active = 1 ORDER BY position ASC', [10])->andReturn([]);

        $response = $this->get('/api/public/forms/ABC123');

        $response->assertOk()->assertJson([
            'success' => true,
            'form' => [
                'id' => 10,
                'title' => 'Public Form',
                'questions' => [],
            ],
        ]);
    }
    public function test_laravel_hosts_react_routes_without_catching_missing_assets(): void
    {
        $root = $this->get('/');
        $root->assertOk();

        $reactRoute = $this->get('/form/example-form-code');
        $reactRoute->assertOk();

        $missingAsset = $this->get('/missing-image.png');
        $missingAsset->assertNotFound();
    }
    public function test_local_proxy_forwarded_for_header_controls_request_ip(): void
    {
        Route::get('/_test_forwarded_ip.php', fn (Request $request) => response()->json(['ip' => $request->ip()]));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '192.168.6.123')
            ->get('/_test_forwarded_ip.php');

        $response->assertOk()->assertJson(['ip' => '192.168.6.123']);
    }
}




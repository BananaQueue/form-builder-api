<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminFormEndpointTest extends TestCase
{
    public function test_all_forms_requires_super_admin(): void
    {
        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->get('/get_all_forms.php');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_all_forms_matches_legacy_paginated_success_shape(): void
    {
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [])->andReturn([
            (object) ['total' => 1],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'LIMIT 10 OFFSET 0') && str_contains($sql, 'ORDER BY f.created_at DESC')), [])->andReturn([
            (object) [
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'description' => 'Daily check',
                'created_at' => '2026-06-24 09:00:00',
                'category_id' => 1,
                'category_name' => 'General',
                'owner_id' => 1,
                'owner_username' => 'admin',
                'owner_role' => 'super_admin',
                'question_count' => 4,
                'response_count' => 2,
            ],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'))->andReturn([
            (object) ['total_forms' => 5, 'total_users' => 2, 'total_responses' => 8],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_all_forms.php');

        $response->assertOk()->assertJson([
            'success' => true,
            'forms' => [[
                'id' => 10,
                'form_code' => 'ABC123',
                'title' => 'Inspection',
                'owner_username' => 'admin',
                'question_count' => 4,
                'response_count' => 2,
            ]],
            'pagination' => [
                'total' => 1,
                'page' => 1,
                'per_page' => 10,
                'total_pages' => 1,
            ],
            'metrics' => [
                'total_forms' => 5,
                'total_users' => 2,
                'total_responses' => 8,
            ],
        ]);
    }

    public function test_all_forms_applies_filters_and_whitelisted_sort(): void
    {
        $expectedParams = ['%safe%', '%safe%', '%safe%', 3, 7];
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'f.category_id = ?') && str_contains($sql, 'f.created_by = ?')), $expectedParams)->andReturn([
            (object) ['total' => 0],
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::on(fn (string $sql): bool => str_contains($sql, 'ORDER BY response_count DESC') && str_contains($sql, 'LIMIT 5 OFFSET 5')), $expectedParams)->andReturn([]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'))->andReturn([
            (object) ['total_forms' => 0, 'total_users' => 0, 'total_responses' => 0],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'super_admin',
        ])->get('/get_all_forms.php?page=2&per_page=5&search=safe&category_id=3&owner_id=7&sort_by=responses_desc');

        $response->assertOk()->assertJson([
            'success' => true,
            'forms' => [],
            'pagination' => ['total' => 0, 'page' => 2, 'per_page' => 5, 'total_pages' => 0],
        ]);
    }
}
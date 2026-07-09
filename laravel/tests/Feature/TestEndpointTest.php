<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEndpointTest extends TestCase
{
    public function test_database_guard_endpoint_is_routed_before_react_fallback(): void
    {
        config(['database.connections.sqlite.database' => ':memory:']);

        $response = $this->get('/test_database_guard.php');

        $response->assertStatus(500)->assertJson([
            'ok' => false,
            'error' => 'E2E tests must use a dedicated test database.',
        ]);
    }

    public function test_reset_endpoint_uses_e2e_token_guard_instead_of_csrf(): void
    {
        config(['database.connections.sqlite.database' => 'form_builder_test']);

        $response = $this->post('/test_reset_database.php');

        $response->assertStatus(403)->assertJson([
            'ok' => false,
            'error' => 'Invalid reset token',
        ]);
    }}

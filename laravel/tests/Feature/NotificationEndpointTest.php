<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationEndpointTest extends TestCase
{
    public function test_notifications_match_legacy_success_shape(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('select')->once()->with('SELECT * FROM notifications WHERE recipient_user_id = ? AND type = ? ORDER BY created_at DESC', [5, 'FORM_EDITED'])->andReturn([
            (object) $this->row(['id' => 10, 'type' => 'FORM_EDITED', 'is_read' => 0, 'acknowledged' => 0]),
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [5])->andReturn([
            (object) ['unread_count' => 2, 'pending_count' => 1],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
        ])->get('/api/notifications?type=FORM_EDITED');

        $response->assertOk()->assertJson([
            'success' => true,
            'notifications' => [[
                'id' => 10,
                'recipientUserId' => 5,
                'type' => 'FORM_EDITED',
                'formId' => 77,
                'formTitle' => 'Inspection',
                'adminId' => 1,
                'adminName' => 'admin',
                'read' => false,
                'acknowledged' => false,
            ]],
            'unread_count' => 2,
            'pending_count' => 1,
        ]);
    }

    public function test_native_notifications_route_requires_authenticated_session(): void
    {
        $response = $this->get('/api/notifications');

        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_native_notifications_route_matches_legacy_success_shape(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('select')->once()->with('SELECT * FROM notifications WHERE recipient_user_id = ? AND type = ? ORDER BY created_at DESC', [5, 'FORM_EDITED'])->andReturn([
            (object) $this->row(['id' => 10, 'type' => 'FORM_EDITED', 'is_read' => 0, 'acknowledged' => 0]),
        ]);
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [5])->andReturn([
            (object) ['unread_count' => 2, 'pending_count' => 1],
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
        ])->get('/api/notifications?type=FORM_EDITED');

        $response->assertOk()->assertJson([
            'success' => true,
            'notifications' => [['id' => 10, 'type' => 'FORM_EDITED', 'read' => false, 'acknowledged' => false]],
            'unread_count' => 2,
            'pending_count' => 1,
        ]);
    }

    public function test_native_pending_notifications_route_matches_legacy_shape(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [5])->andReturn([
            (object) $this->row(['id' => 11, 'type' => 'FORM_DELETED', 'is_read' => 0, 'acknowledged' => 0]),
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
        ])->get('/api/notifications/pending');

        $response->assertOk()->assertJson([
            'success' => true,
            'pending_count' => 1,
            'notifications' => [['id' => 11, 'type' => 'FORM_DELETED']],
        ]);
    }

    public function test_native_acknowledge_route_maps_path_id_to_legacy_update(): void
    {
        $token = 'csrf-token';
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('update')->once()->with('UPDATE notifications SET acknowledged = 1, is_read = 1 WHERE id = ? AND recipient_user_id = ?', [10, 5])->andReturn(1);

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/notifications/10/acknowledge');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_native_mark_read_route_maps_path_id_to_legacy_update(): void
    {
        $token = 'csrf-token';
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('update')->once()->with('UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?', [10, 5])->andReturn(1);

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/notifications/10/read');

        $response->assertOk()->assertJson(['success' => true]);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 10,
            'recipient_user_id' => 5,
            'type' => 'FORM_EDITED',
            'form_id' => 77,
            'form_title' => 'Inspection',
            'message' => 'Form changed',
            'deletion_reason' => null,
            'admin_id' => 1,
            'admin_name' => 'admin',
            'created_at' => '2026-06-24 11:00:00',
            'is_read' => 0,
            'acknowledged' => 0,
        ], $overrides);
    }
}
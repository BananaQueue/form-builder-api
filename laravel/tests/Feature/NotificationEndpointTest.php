<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationEndpointTest extends TestCase
{
    public function test_notifications_require_authenticated_session(): void
    {
        $response = $this->get('/get_notifications.php');

        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

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
        ])->get('/get_notifications.php?type=FORM_EDITED');

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

    public function test_pending_notifications_match_legacy_shape(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [5])->andReturn([
            (object) $this->row(['id' => 11, 'type' => 'FORM_DELETED', 'is_read' => 0, 'acknowledged' => 0]),
        ]);

        $response = $this->withSession([
            'logged_in' => true,
            'user_id' => 5,
        ])->get('/get_pending_notifications.php');

        $response->assertOk()->assertJson([
            'success' => true,
            'pending_count' => 1,
            'notifications' => [['id' => 11, 'type' => 'FORM_DELETED']],
        ]);
    }

    public function test_acknowledge_requires_notification_id(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/acknowledge_notification.php', []);

        $response->assertStatus(400)->assertJson(['error' => 'notification_id is required']);
    }

    public function test_acknowledge_updates_owned_notification(): void
    {
        $token = 'csrf-token';
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('update')->once()->with('UPDATE notifications SET acknowledged = 1, is_read = 1 WHERE id = ? AND recipient_user_id = ?', [10, 5])->andReturn(1);

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/acknowledge_notification.php', ['notification_id' => 10]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_mark_read_updates_owned_notification(): void
    {
        $token = 'csrf-token';
        Schema::shouldReceive('hasTable')->once()->with('notifications')->andReturnTrue();
        DB::shouldReceive('update')->once()->with('UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?', [10, 5])->andReturn(1);

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
        ])->withHeader('X-CSRF-TOKEN', $token)->postJson('/mark_notification_read.php', ['notification_id' => 10]);

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
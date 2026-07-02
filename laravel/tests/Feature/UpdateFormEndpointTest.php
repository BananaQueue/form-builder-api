<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateFormEndpointTest extends TestCase
{
    public function test_update_form_requires_authenticated_session(): void
    {
        $response = $this->postJson('/update_form.php', []);
        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_update_form_rejects_invalid_payload(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10]);
        $response->assertStatus(400)->assertJson(['error' => 'Invalid data provided']);
    }

    public function test_update_form_returns_not_found(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Missing', 'questions' => []]);
        $response->assertStatus(404)->assertJson(['error' => 'Form not found']);
    }

    public function test_update_form_blocks_non_owner(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([(object) ['id' => 10, 'title' => 'Other', 'created_by' => 9, 'owner_username' => 'other']]);
            return $callback();
        });
        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Other', 'questions' => []]);
        $response->assertStatus(403)->assertJson(['error' => 'You can only edit your own forms']);
    }

    public function test_update_form_audit_describes_section_changes(): void
    {
        $controller = new \App\Http\Controllers\LegacyFormWriteController();
        $method = new \ReflectionMethod($controller, 'describeQuestionAuditChanges');
        $method->setAccessible(true);

        $changes = $method->invoke($controller, [
            ['id' => 11, 'question_text' => 'Employment', 'question_type' => 'section', 'description' => 'Old details'],
            ['id' => 12, 'question_text' => 'Benefits', 'question_type' => 'section', 'description' => ''],
            ['id' => 13, 'question_text' => 'Name', 'question_type' => 'text', 'description' => null],
        ], [
            ['id' => 11, 'text' => 'Employment', 'type' => 'section', 'description' => 'New details'],
            ['id' => 'tmp-section', 'text' => 'Approvals', 'type' => 'section', 'description' => ''],
            ['id' => 13, 'text' => 'Name', 'type' => 'text'],
        ]);

        $this->assertSame(['Edited section', 'Added section', 'Deleted section'], $changes);
    }

    public function test_update_form_audit_describes_question_and_option_changes(): void
    {
        $controller = new \App\Http\Controllers\LegacyFormWriteController();
        $method = new \ReflectionMethod($controller, 'describeQuestionAuditChanges');
        $method->setAccessible(true);

        $changes = $method->invoke($controller, [
            ['id' => 21, 'question_text' => 'Name', 'question_type' => 'text', 'description' => null, 'is_required' => 1, 'options' => []],
            ['id' => 22, 'question_text' => 'Status', 'question_type' => 'multiple_choice', 'description' => null, 'is_required' => 0, 'options' => ['Open']],
            ['id' => 23, 'question_text' => 'Priority', 'question_type' => 'multiple_choice', 'description' => null, 'is_required' => 1, 'options' => ['Low', 'High']],
            ['id' => 24, 'question_text' => 'Remove me', 'question_type' => 'text', 'description' => null, 'is_required' => 1, 'options' => []],
        ], [
            ['id' => 21, 'text' => 'Full name', 'type' => 'email', 'is_required' => 0, 'options' => []],
            ['id' => 22, 'text' => 'Status', 'type' => 'multiple_choice', 'is_required' => 0, 'options' => ['Open', 'Closed']],
            ['id' => 23, 'text' => 'Priority', 'type' => 'multiple_choice', 'is_required' => 1, 'options' => ['Medium', 'High']],
            ['id' => 'tmp-question', 'text' => 'New question', 'type' => 'text', 'is_required' => 1, 'options' => []],
        ]);

        $this->assertSame([
            'Changed question type',
            'Marked question optional',
            'Edited question text',
            'Added options',
            'Edited options',
            'Added question',
            'Deleted question',
        ], $changes);
    }

    public function test_update_form_audit_describes_form_setting_changes(): void
    {
        $controller = new \App\Http\Controllers\LegacyFormWriteController();
        $method = new \ReflectionMethod($controller, 'describeFormAuditChanges');
        $method->setAccessible(true);

        $enabled = $method->invoke($controller, ['title' => 'Old', 'description' => 'Old', 'category_id' => 1, 'step_mode' => 0], ['title' => 'New', 'description' => 'New', 'category_id' => 2, 'step_mode' => 1]);
        $disabled = $method->invoke($controller, ['title' => 'Same', 'description' => 'Same', 'category_id' => 1, 'step_mode' => 1], ['title' => 'Same', 'description' => 'Same', 'category_id' => 1, 'step_mode' => 0]);

        $this->assertSame(['Edited form title', 'Edited form description', 'Changed form category', 'Enabled step mode'], $enabled);
        $this->assertSame(['Disabled step mode'], $disabled);
    }

    public function test_update_form_matches_legacy_success_shape(): void
    {
        $token = 'csrf-token';
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            DB::shouldReceive('select')->once()->with(\Mockery::type('string'), [10])->andReturn([(object) ['id' => 10, 'title' => 'Old', 'description' => 'Old desc', 'category_id' => 1, 'step_mode' => 0, 'created_by' => 5, 'owner_username' => 'user1']]);
            DB::shouldReceive('update')->once()->with('UPDATE forms SET title = ?, description = ?, category_id = ?, privacy_notice = ?, step_mode = ? WHERE id = ?', ['Updated', 'Desc', 1, 1, 0, 10])->andReturn(1);
            DB::shouldReceive('select')->once()->with('SELECT id, question_text, question_type, description, is_required FROM questions WHERE form_id = ?', [10])->andReturn([(object) ['id' => 21, 'question_text' => 'Ready?', 'question_type' => 'multiple_choice', 'description' => null, 'is_required' => 1]]);
            DB::shouldReceive('select')->once()->with('SELECT option_text FROM question_options WHERE question_id = ? ORDER BY position ASC', [21])->andReturn([(object) ['option_text' => 'Yes']]);
            DB::shouldReceive('update')->once()->with(\Mockery::type('string'), \Mockery::type('array'))->andReturn(1);
            DB::shouldReceive('delete')->once()->with('DELETE FROM question_options WHERE question_id = ?', [21])->andReturn(1);
            DB::shouldReceive('insert')->once()->with('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', [21, 'Yes', 0])->andReturnTrue();
            return $callback();
        });
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(function (array $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            return is_array($metadata)
                && ($metadata['changes'] ?? null) === ['Edited form title', 'Edited form description'];
        }))->andReturnTrue();

        $response = $this->withSession(['_token' => $token, 'logged_in' => true, 'user_id' => 5, 'username' => 'user1', 'role' => 'user'])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/update_form.php', ['form_id' => 10, 'title' => 'Updated', 'description' => 'Desc', 'category_id' => 1, 'step_mode' => 0, 'questions' => [['id' => 21, 'text' => 'Ready?', 'type' => 'multiple_choice', 'options' => ['Yes']]]]);

        $response->assertOk()->assertJson(['success' => true, 'message' => 'Form updated successfully', 'form_id' => 10]);
    }
}
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight, ongoing stand-in for the one-time schema-diff CI job that
 * proved the single migration byte-equivalent to the legacy dump (see
 * form-builder-app commit c05e0e0 - the job and the dump it compared
 * against were both retired once the migration was the confirmed source
 * of truth).
 *
 * This can't catch MySQL-specific drift (column types, collation,
 * index/FK names) the way that job did, but it does catch the drift
 * that matters most day to day: a column silently renamed or dropped,
 * or a table disappearing.
 *
 * Needs a MySQL/MariaDB-compatible connection, not SQLite: two of this
 * schema's index names are legitimately reused across different tables
 * (fine in MySQL/MariaDB, where index names are scoped per table; not
 * fine in SQLite, which requires them unique database-wide). Run it in
 * CI against the real target database - see e2e.yml, right after the
 * migrate step.
 */
class VerifySchemaShape extends Command
{
    protected $signature = 'fb:verify-schema-shape';

    protected $description = 'Check the migrated schema still has every expected table, column, and category seed row';

    private const EXPECTED_COLUMNS = [
        'users' => ['id', 'username', 'email', 'role', 'password_hash', 'created_at'],
        'categories' => ['id', 'name', 'created_at'],
        'audit_logs' => [
            'id', 'actor_user_id', 'actor_username', 'actor_role', 'action',
            'entity_type', 'entity_id', 'entity_label', 'metadata',
            'ip_address', 'user_agent', 'created_at',
        ],
        'password_reset_codes' => [
            'id', 'user_id', 'requested_by_user_id', 'code_hash', 'token',
            'verified_at', 'used_at', 'expires_at', 'created_at', 'updated_at',
        ],
        'forms' => [
            'id', 'created_by', 'form_code', 'title', 'description',
            'privacy_notice', 'step_mode', 'created_at', 'category_id',
        ],
        'questions' => [
            'id', 'form_id', 'question_text', 'description', 'question_type',
            'rating_scale', 'number_min', 'number_max', 'number_step',
            'datetime_type', 'position', 'is_active', 'is_required',
            'condition_question_id', 'condition_type', 'condition_value',
        ],
        'question_options' => ['id', 'question_id', 'option_text', 'position'],
        'responses' => ['id', 'form_id', 'submitted_at'],
        'answers' => [
            'id', 'response_id', 'question_id', 'question_text',
            'question_type', 'answer_text',
        ],
        'notifications' => [
            'id', 'recipient_user_id', 'type', 'form_id', 'form_title',
            'message', 'deletion_reason', 'admin_id', 'admin_name',
            'is_read', 'acknowledged', 'created_at',
        ],
    ];

    public function handle(): int
    {
        $failures = [];

        foreach (self::EXPECTED_COLUMNS as $table => $expectedColumns) {
            if (! Schema::hasTable($table)) {
                $failures[] = "missing table: {$table}";

                continue;
            }

            $actualColumns = Schema::getColumnListing($table);
            sort($expectedColumns);
            sort($actualColumns);

            if ($actualColumns !== $expectedColumns) {
                $failures[] = "column mismatch on '{$table}': expected [".implode(', ', $expectedColumns).'], got ['.implode(', ', $actualColumns).']';
            }
        }

        $actualTables = collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn ($name) => $name === 'migrations')
            ->sort()
            ->values()
            ->all();
        $expectedTables = collect(array_keys(self::EXPECTED_COLUMNS))->sort()->values()->all();

        if ($actualTables !== $expectedTables) {
            $failures[] = 'unexpected table set: expected ['.implode(', ', $expectedTables).'], got ['.implode(', ', $actualTables).']';
        }

        $categories = DB::table('categories')->orderBy('id')->pluck('name', 'id')->all();
        if ($categories !== [1 => 'General', 2 => 'External', 3 => 'Internal']) {
            $failures[] = 'category seed rows missing or changed: got '.json_encode($categories);
        }

        if ($failures !== []) {
            $this->error('Schema shape check failed:');
            foreach ($failures as $failure) {
                $this->error("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('Schema shape check passed: all expected tables, columns, and seed rows present.');

        return self::SUCCESS;
    }
}

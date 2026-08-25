<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Recreates the legacy MariaDB schema exactly: signed int(11) ids,
// utf8mb4 / utf8mb4_general_ci, current_timestamp() defaults, original
// index and foreign-key names. Verified byte-for-byte by the schema-diff
// CI job against src/form_builder.sql.
return new class extends Migration
{
    public function up(): void
    {
        $tune = function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_general_ci';
        };

        Schema::create('users', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->string('username', 100);
            $t->string('email', 191)->nullable();
            $t->enum('role', ['user', 'super_admin'])->default('user');
            $t->string('password_hash', 255);
            $t->timestamp('created_at')->useCurrent();
            $t->unique('username', 'username');
            $t->unique('email', 'email');
        });

        Schema::create('categories', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->string('name', 50);
            $t->timestamp('created_at')->useCurrent();
            $t->unique('name', 'name');
        });

        Schema::create('audit_logs', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('actor_user_id')->nullable();
            $t->string('actor_username', 100)->nullable();
            $t->string('actor_role', 50)->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 80)->nullable();
            $t->integer('entity_id')->nullable();
            $t->string('entity_label', 255)->nullable();
            $t->longText('metadata')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['actor_user_id', 'created_at'], 'idx_audit_actor_created');
            $t->index(['action', 'created_at'], 'idx_audit_action_created');
            $t->index(['entity_type', 'entity_id'], 'idx_audit_entity');
        });

        Schema::create('password_reset_codes', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('user_id');
            $t->integer('requested_by_user_id')->nullable();
            $t->string('code_hash', 255);
            $t->string('token', 64);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('expires_at')->useCurrent();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent();
            $t->unique('token', 'password_reset_codes_token_unique');
            $t->index(['user_id', 'token'], 'idx_password_reset_codes_user_token');
        });

        Schema::create('forms', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('created_by')->nullable()->comment('FK to users.id — which user created this form');
            $t->string('form_code', 20)->nullable();
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->boolean('privacy_notice')->default(0)->comment('0 = no privacy notice modal, 1 = show standard privacy notice on submit');
            $t->boolean('step_mode')->default(0)->comment('0 = continuous form, 1 = multi-step form driven by section blocks');
            $t->timestamp('created_at')->useCurrent();
            $t->integer('category_id')->nullable()->default(1);
            $t->unique('form_code', 'form_code');
            $t->index('category_id', 'category_id');
            $t->index('form_code', 'idx_form_code');
            $t->index('created_by', 'fk_forms_created_by');
        });

        Schema::create('questions', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('form_id');
            $t->text('question_text');
            $t->text('description')->nullable();
            $t->string('question_type', 50);
            $t->string('rating_scale', 50)->nullable();
            $t->decimal('number_min', 10, 2)->nullable();
            $t->decimal('number_max', 10, 2)->nullable();
            $t->string('number_step', 10)->nullable();
            $t->string('datetime_type', 20)->nullable();
            $t->integer('position')->nullable()->default(0);
            $t->boolean('is_active')->default(1);
            $t->boolean('is_required')->default(1)->nullable();
            $t->integer('condition_question_id')->nullable();
            $t->string('condition_type', 50)->default('equals')->nullable();
            $t->text('condition_value')->nullable();
            $t->index('form_id', 'form_id');
            $t->index('condition_question_id', 'fk_condition_question');
        });

        Schema::create('question_options', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('question_id');
            $t->string('option_text', 255);
            $t->integer('position')->nullable()->default(0);
            $t->index('question_id', 'question_id');
        });

        Schema::create('responses', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('form_id');
            $t->timestamp('submitted_at')->useCurrent();
            $t->index('form_id', 'form_id');
        });

        Schema::create('answers', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('response_id');
            $t->integer('question_id');
            $t->text('question_text')->nullable();
            $t->string('question_type', 50)->nullable();
            $t->text('answer_text')->nullable();
            $t->index('response_id', 'response_id');
            $t->index('question_id', 'question_id');
        });

        Schema::create('notifications', function (Blueprint $t) use ($tune) {
            $tune($t);
            $t->integer('id')->autoIncrement();
            $t->integer('recipient_user_id');
            $t->enum('type', ['FORM_EDITED', 'FORM_DELETED']);
            $t->integer('form_id')->nullable();
            $t->string('form_title', 255);
            $t->text('message');
            $t->text('deletion_reason')->nullable();
            $t->integer('admin_id')->nullable();
            $t->string('admin_name', 100)->nullable();
            $t->boolean('is_read')->default(0);
            $t->boolean('acknowledged')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['recipient_user_id', 'acknowledged', 'created_at'], 'idx_recipient_pending');
            $t->index(['recipient_user_id', 'created_at'], 'idx_recipient_created');
        });

        // Foreign keys added after all tables exist (incl. the self-reference).
        Schema::table('forms', function (Blueprint $t) {
            $t->foreign('created_by', 'fk_forms_created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('category_id', 'forms_ibfk_1')->references('id')->on('categories');
        });
        Schema::table('questions', function (Blueprint $t) {
            $t->foreign('condition_question_id', 'fk_condition_question')->references('id')->on('questions')->nullOnDelete();
            $t->foreign('form_id', 'questions_ibfk_1')->references('id')->on('forms')->cascadeOnDelete();
        });
        Schema::table('question_options', function (Blueprint $t) {
            $t->foreign('question_id', 'question_options_ibfk_1')->references('id')->on('questions')->cascadeOnDelete();
        });
        Schema::table('responses', function (Blueprint $t) {
            $t->foreign('form_id', 'responses_ibfk_1')->references('id')->on('forms')->cascadeOnDelete();
        });
        Schema::table('answers', function (Blueprint $t) {
            $t->foreign('response_id', 'answers_ibfk_1')->references('id')->on('responses')->cascadeOnDelete();
            $t->foreign('question_id', 'answers_ibfk_2')->references('id')->on('questions')->cascadeOnDelete();
        });
        Schema::table('notifications', function (Blueprint $t) {
            $t->foreign('recipient_user_id', 'fk_notifications_recipient')->references('id')->on('users')->cascadeOnDelete();
        });

        // Essential reference data: the app defaults forms.category_id to 1.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'General',  'created_at' => now()],
            ['id' => 2, 'name' => 'External', 'created_at' => now()],
            ['id' => 3, 'name' => 'Internal', 'created_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['notifications', 'answers', 'responses', 'question_options',
                  'questions', 'forms', 'password_reset_codes', 'audit_logs',
                  'categories', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};

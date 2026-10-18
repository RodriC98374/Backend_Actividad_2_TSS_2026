<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('La plataforma requiere PostgreSQL; configure DB_CONNECTION=pgsql.');
        }
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->boolean('can_view_contacts')->default(false);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('datasets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('reference_period')->nullable();
            $t->string('status')->default('active');
            $t->bigInteger('data_version')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('import_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('survey_type');
            $t->integer('profile_version')->default(1);
            $t->string('dictionary_version');
            $t->jsonb('settings');
            $t->uuid('previous_profile_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::table('import_profiles', fn (Blueprint $t) => $t->foreign('previous_profile_id')->references('id')->on('import_profiles')->restrictOnDelete());
        Schema::create('imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('original_name');
            $t->string('storage_disk')->default('local');
            $t->text('storage_path');
            $t->char('sha256', 64);
            $t->string('source_label')->nullable();
            $t->string('status');
            $t->string('dictionary_version');
            $t->integer('configuration_revision')->default(1);
            $t->jsonb('counts')->default('{}');
            $t->string('failure_code')->nullable();
            $t->text('failure_message')->nullable();
            $t->timestampTz('committed_at')->nullable();
            $t->timestampsTz();
            $t->index(['dataset_id', 'status']);
            $t->index(['dataset_id', 'sha256']);
        });
        Schema::create('import_sheets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $t->foreignUuid('import_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->integer('sheet_index');
            $t->string('name');
            $t->boolean('selected')->default(false);
            $t->string('survey_type')->nullable();
            $t->integer('header_row')->nullable();
            $t->string('dictionary_version');
            $t->jsonb('data_region')->nullable();
            $t->jsonb('headers')->default('[]');
            $t->jsonb('mapping')->default('[]');
            $t->jsonb('multiselect_rules')->default('[]');
            $t->integer('row_count')->default(0);
            $t->integer('column_count')->default(0);
            $t->timestampsTz();
            $t->unique(['import_id', 'sheet_index']);
        });
        Schema::create('raw_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('import_sheet_id')->constrained()->restrictOnDelete();
            $t->integer('row_number');
            $t->jsonb('original_cells');
            $t->text('provider_response_id')->nullable();
            $t->timestampTz('provider_timestamp')->nullable();
            $t->string('row_status')->default('read');
            $t->unique(['import_sheet_id', 'row_number']);
        });
        Schema::create('validation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $t->integer('configuration_revision');
            $t->string('dictionary_version');
            $t->string('normalization_version');
            $t->jsonb('configuration_snapshot');
            $t->string('status');
            $t->jsonb('counts')->default('{}');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('prepared_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('validation_run_id')->constrained()->restrictOnDelete();
            $t->foreignId('raw_row_id')->constrained()->restrictOnDelete();
            $t->jsonb('prepared_fields');
            $t->jsonb('prepared_contacts')->default('{}');
            $t->boolean('proposed_inclusion');
            $t->text('exclusion_reason')->nullable();
            $t->jsonb('effective_decision')->nullable();
            $t->unique(['validation_run_id', 'raw_row_id']);
        });
        Schema::create('row_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $t->foreignId('raw_row_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->integer('configuration_revision');
            $t->string('action');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['raw_row_id', 'configuration_revision']);
        });
        Schema::create('form_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('survey_type');
            $t->string('dictionary_version');
            $t->string('questionnaire_version');
            $t->char('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('used_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('direct_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('form_invitation_id')->unique()->constrained()->restrictOnDelete();
            $t->jsonb('original_payload');
            $t->char('request_hash', 64);
            $t->jsonb('publication_result');
            $t->timestampTz('received_at');
        });
        Schema::create('survey_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained()->restrictOnDelete();
            $t->string('source_kind');
            $t->foreignId('raw_row_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('validation_run_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('direct_submission_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('survey_type');
            $t->string('dictionary_version');
            $t->string('normalization_version');
            $t->boolean('included');
            $t->text('exclusion_reason')->nullable();
            $t->integer('response_revision')->default(1);
            $t->timestampsTz();
            $t->index(['dataset_id', 'survey_type', 'included']);
        });
        Schema::create('response_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('response_id')->constrained('survey_responses')->restrictOnDelete();
            $t->string('field_id');
            $t->string('state');
            $t->jsonb('value')->nullable();
            $t->jsonb('provenance')->default('{}');
            $t->unique(['response_id', 'field_id']);
            $t->index(['field_id', 'state']);
        });
        Schema::create('contact_details', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('response_id')->unique()->constrained('survey_responses')->restrictOnDelete();
            $t->text('email')->nullable();
            $t->text('phone')->nullable();
            $t->text('linkedin')->nullable();
        });
        Schema::create('quality_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('import_id')->nullable()->constrained('imports')->restrictOnDelete();
            $t->foreignUuid('direct_submission_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('validation_run_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('raw_row_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('response_id')->nullable()->constrained('survey_responses')->restrictOnDelete();
            $t->string('field_id')->nullable();
            $t->string('code');
            $t->string('severity');
            $t->text('message');
            $t->string('status')->default('open');
            $t->text('resolution')->nullable();
            $t->jsonb('context')->default('{}');
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['validation_run_id', 'severity', 'status']);
        });
        Schema::create('change_log', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('response_id')->constrained('survey_responses')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('field_id')->nullable();
            $t->jsonb('before');
            $t->jsonb('after');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('import_commit_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->jsonb('request_body');
            $t->smallInteger('response_status');
            $t->jsonb('response_body');
            $t->timestampTz('completed_at');
            $t->unique(['actor_id', 'import_id', 'idempotency_key'], 'commit_request_scope_unique');
        });
        Schema::create('exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('data_version');
            $t->string('rules_version');
            $t->jsonb('specification');
            $t->string('status');
            $t->text('storage_path')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE survey_responses ADD CONSTRAINT response_origin CHECK ((source_kind='import' AND raw_row_id IS NOT NULL AND validation_run_id IS NOT NULL AND direct_submission_id IS NULL) OR (source_kind='direct' AND direct_submission_id IS NOT NULL AND raw_row_id IS NULL AND validation_run_id IS NULL))");
        DB::statement('ALTER TABLE quality_issues ADD CONSTRAINT issue_origin CHECK ((import_id IS NOT NULL)::int + (direct_submission_id IS NOT NULL)::int = 1)');
        DB::statement("ALTER TABLE response_answers ADD CONSTRAINT answer_state CHECK (state IN ('respondida','no_aplica','sin_respuesta','invalida','no_disponible','indeterminada'))");
        DB::statement("ALTER TABLE row_decisions ADD CONSTRAINT decision_action CHECK (action IN ('include','exclude','reset') AND length(trim(reason)) > 0)");
        DB::statement('ALTER TABLE datasets ADD CONSTRAINT dataset_version CHECK (data_version >= 0)');
        DB::statement('ALTER TABLE raw_rows ADD CONSTRAINT row_position CHECK (row_number >= 1)');
        DB::statement("ALTER TABLE survey_responses ADD CONSTRAINT response_survey CHECK (survey_type IN ('titulados','empleadores') AND response_revision >= 1)");
    }

    public function down(): void
    {
        foreach (['exports', 'import_commit_requests', 'change_log', 'quality_issues', 'contact_details', 'response_answers', 'survey_responses', 'direct_submissions', 'form_invitations', 'row_decisions', 'prepared_rows', 'validation_runs', 'raw_rows', 'import_sheets', 'imports', 'import_profiles', 'datasets', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'can_view_contacts']));
    }
};

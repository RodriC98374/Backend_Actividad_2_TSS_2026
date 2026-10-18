<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_schemas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('legacy_survey_type')->nullable()->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('survey_schema_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('schema_id')->constrained('survey_schemas')->restrictOnDelete();
            $t->integer('version_number');
            $t->integer('revision')->default(1);
            $t->string('status')->default('draft');
            $t->string('legacy_dictionary_version')->nullable();
            $t->char('content_hash', 64)->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['schema_id', 'version_number']);
            $t->unique(['schema_id', 'legacy_dictionary_version']);
            $t->unique(['id', 'schema_id']);
        });
        Schema::create('survey_fields', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('schema_id')->constrained('survey_schemas')->restrictOnDelete();
            $t->string('code', 64);
            $t->unique(['schema_id', 'code']);
            $t->unique(['id', 'schema_id']);
        });
        Schema::create('survey_field_definitions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('schema_id');
            $t->uuid('schema_version_id');
            $t->uuid('field_id');
            $t->string('label', 2000);
            $t->string('type', 32);
            $t->string('unit', 64)->nullable();
            $t->boolean('required')->default(false);
            $t->boolean('sensitive')->default(false);
            $t->jsonb('legacy_payload')->nullable();
            $t->integer('legacy_position')->nullable();
            $t->unique(['schema_version_id', 'field_id']);
            $t->unique(['id', 'schema_version_id']);
            $t->foreign(['schema_version_id', 'schema_id'], 'definition_version_schema_fk')->references(['id', 'schema_id'])->on('survey_schema_versions')->restrictOnDelete();
            $t->foreign(['field_id', 'schema_id'], 'definition_field_schema_fk')->references(['id', 'schema_id'])->on('survey_fields')->restrictOnDelete();
        });
        Schema::create('survey_field_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('definition_id');
            $t->uuid('schema_version_id');
            $t->string('code', 128);
            $t->string('label', 2000);
            $t->integer('position');
            $t->unique(['definition_id', 'code']);
            $t->foreign(['definition_id', 'schema_version_id'], 'option_definition_version_fk')->references(['id', 'schema_version_id'])->on('survey_field_definitions')->restrictOnDelete();
        });
        Schema::create('dataset_schema_bindings', function (Blueprint $t) {
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignUuid('schema_version_id')->constrained('survey_schema_versions')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['dataset_id', 'schema_version_id']);
        });
        DB::statement("ALTER TABLE survey_schema_versions ADD CONSTRAINT schema_version_state CHECK (revision > 0 AND version_number > 0 AND ((status = 'draft' AND published_at IS NULL AND content_hash IS NULL) OR (status = 'published' AND published_at IS NOT NULL AND content_hash IS NOT NULL)))");
        DB::statement("ALTER TABLE survey_field_definitions ADD CONSTRAINT definition_type CHECK (type IN ('boolean','number','category','ordered_category','multiselect','date','text','range','scale','contact'))");

        // Frozen migration input: future catalog edits must never alter this snapshot.
        $catalog = json_decode(file_get_contents(database_path('data/survey-catalog-2026-10-17.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['titulados', 'empleadores'] as $survey) {
            $schemaId = (string) Str::uuid();
            DB::table('survey_schemas')->insert(['id' => $schemaId, 'name' => ucfirst($survey), 'legacy_survey_type' => $survey, 'created_at' => now(), 'updated_at' => now()]);
            $fieldIds = [];
            foreach (array_values(array_filter($catalog, fn ($f) => $f['survey_type'] === $survey)) as $field) {
                $fieldIds[$field['id']] = (string) Str::uuid();
                DB::table('survey_fields')->insert(['id' => $fieldIds[$field['id']], 'schema_id' => $schemaId, 'code' => $field['id']]);
            }
            foreach (['umss-encuestas-2026-10-10', 'umss-hibrido-v1'] as $index => $dictionary) {
                $fields = array_values(array_filter($catalog, fn ($f) => $f['survey_type'] === $survey && ($index === 1 || ! str_starts_with($f['id'], 'X'))));
                $versionId = (string) Str::uuid();
                DB::table('survey_schema_versions')->insert(['id' => $versionId, 'schema_id' => $schemaId, 'version_number' => $index + 1, 'revision' => 1, 'status' => 'published', 'legacy_dictionary_version' => $dictionary, 'content_hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($fields as $fieldPosition => $field) {
                    $definitionId = (string) Str::uuid();
                    DB::table('survey_field_definitions')->insert(['id' => $definitionId, 'schema_id' => $schemaId, 'schema_version_id' => $versionId, 'field_id' => $fieldIds[$field['id']], 'label' => $field['label'], 'type' => $field['type'], 'sensitive' => $field['type'] === 'contact', 'legacy_payload' => json_encode($field, JSON_THROW_ON_ERROR), 'legacy_position' => $fieldPosition]);
                    foreach ($field['options'] as $position => $option) {
                        DB::table('survey_field_options')->insert(['definition_id' => $definitionId, 'schema_version_id' => $versionId, 'code' => $option['code'], 'label' => $option['label'], 'position' => $position]);
                    }
                }
            }
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_published_schema_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status = 'published' THEN
        RAISE EXCEPTION 'Published schema versions are immutable' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.schema_id <> OLD.schema_id OR NEW.version_number <> OLD.version_number OR NEW.id <> OLD.id THEN
        RAISE EXCEPTION 'Schema version identity is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER immutable_schema_version BEFORE UPDATE OR DELETE ON survey_schema_versions
FOR EACH ROW EXECUTE FUNCTION guard_published_schema_version();

CREATE OR REPLACE FUNCTION guard_schema_definition() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE version_id uuid; version_status text;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.schema_version_id <> OLD.schema_version_id THEN
        RAISE EXCEPTION 'Cannot move schema definitions' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN version_id := OLD.schema_version_id; ELSE version_id := NEW.schema_version_id; END IF;
    SELECT status INTO version_status FROM survey_schema_versions WHERE id = version_id FOR UPDATE;
    IF version_status = 'published' THEN
        RAISE EXCEPTION 'Published schema definitions are immutable' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER immutable_field_definition BEFORE INSERT OR UPDATE OR DELETE ON survey_field_definitions
FOR EACH ROW EXECUTE FUNCTION guard_schema_definition();
CREATE TRIGGER immutable_field_options BEFORE INSERT OR UPDATE OR DELETE ON survey_field_options
FOR EACH ROW EXECUTE FUNCTION guard_schema_definition();

CREATE OR REPLACE FUNCTION guard_field_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Field identities are immutable' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER immutable_field_identity BEFORE UPDATE ON survey_fields
FOR EACH ROW EXECUTE FUNCTION guard_field_identity();

CREATE OR REPLACE FUNCTION guard_schema_binding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM survey_schema_versions WHERE id = NEW.schema_version_id AND status = 'published') THEN
        RAISE EXCEPTION 'Only published versions may be bound' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER published_schema_binding BEFORE INSERT OR UPDATE ON dataset_schema_bindings
FOR EACH ROW EXECUTE FUNCTION guard_schema_binding();
SQL);
    }

    public function down(): void
    {
        foreach (['dataset_schema_bindings', 'survey_field_options', 'survey_field_definitions', 'survey_fields', 'survey_schema_versions', 'survey_schemas'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['guard_published_schema_version', 'guard_schema_definition', 'guard_field_identity', 'guard_schema_binding'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};

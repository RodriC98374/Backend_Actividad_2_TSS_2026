<?php

namespace Tests\Feature;

use App\Domain\Catalog\Catalog;
use App\Models\FieldDefinition;
use App\Models\SchemaVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchemaRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('umss_testing', DB::connection()->getDatabaseName());
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin']);
    }

    private function schema(): string
    {
        return $this->postJson('/api/v1/schemas', ['name' => 'Encuesta configurable'])->assertCreated()->json('data.id');
    }

    private function version(string $schema, array $body = []): string
    {
        return $this->postJson("/api/v1/schemas/$schema/versions", $body)->assertCreated()->json('data.id');
    }

    private function field(string $code = 'EDAD'): array
    {
        return ['code' => $code, 'label' => 'Edad', 'type' => 'number', 'unit' => 'years', 'required' => true, 'sensitive' => false, 'options' => []];
    }

    private function putFields(string $version, array $fields, int $revision = 1)
    {
        return $this->putJson("/api/v1/schema-versions/$version/fields", ['expected_revision' => $revision, 'fields' => $fields]);
    }

    public function test_frozen_catalogs_keep_every_legacy_field_option_and_order(): void
    {
        $original = json_decode(file_get_contents(config_path('survey-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['titulados', 'empleadores'] as $survey) {
            foreach ([config('surveys.legacy_version'), config('surveys.dictionary_version')] as $dictionary) {
                $expected = array_values(array_filter($original, fn ($f) => $f['survey_type'] === $survey && ($dictionary !== config('surveys.legacy_version') || ! str_starts_with($f['id'], 'X'))));
                $this->assertEquals($expected, app(Catalog::class)->fields($survey, $dictionary));
            }
        }
        $this->assertSame(4, SchemaVersion::where('status', 'published')->count());
    }

    public function test_create_edit_publish_clone_and_bind_without_mutating_old_definitions(): void
    {
        $schema = $this->schema();
        $version = $this->version($schema);
        $dataset = $this->postJson('/api/v1/datasets', ['name' => 'Estudio'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/datasets/$dataset/schema-bindings", ['schema_version_id' => $version])->assertConflict();
        $this->putFields($version, [$this->field()])->assertOk()->assertJsonPath('data.version.revision', 2);
        $this->postJson("/api/v1/schema-versions/$version/publish", ['expected_revision' => 2])->assertOk()->assertJsonPath('data.status', 'published');
        $old = $this->getJson("/api/v1/schema-versions/$version/fields")->assertOk()->json('data.0');
        $this->putFields($version, [$this->field()], 3)->assertConflict();
        $this->postJson("/api/v1/datasets/$dataset/schema-bindings", ['schema_version_id' => $version])->assertOk();
        $this->postJson("/api/v1/datasets/$dataset/schema-bindings", ['schema_version_id' => $version])->assertOk();
        $this->assertDatabaseCount('dataset_schema_bindings', 1);
        $draft = $this->version($schema, ['base_version_id' => $version]);
        $this->putFields($draft, [array_replace($this->field(), ['label' => 'Edad al responder'])])->assertOk();
        $next = $this->getJson("/api/v1/schema-versions/$draft/fields")->assertOk()->json('data.0');
        $this->assertSame($old['field_id'], $next['field_id']);
        $this->assertNotSame($old['id'], $next['id']);
        $this->assertDatabaseHas('survey_field_definitions', ['id' => $old['id'], 'label' => 'Edad']);
        $this->putFields($draft, [array_replace($this->field(), ['unit' => 'months'])], 2)->assertUnprocessable()->assertJsonPath('code', 'field_identity_conflict');
    }

    public function test_many_variables_can_be_edited_in_batches_and_read_in_pages(): void
    {
        $version = $this->version($this->schema());
        for ($batch = 0; $batch < 3; $batch++) {
            $fields = [];
            for ($i = 0; $i < 80; $i++) {
                $fields[] = $this->field('V'.str_pad((string) ($batch * 80 + $i), 3, '0', STR_PAD_LEFT));
            }
            $this->putFields($version, $fields, $batch + 1)->assertOk();
        }
        $this->getJson("/api/v1/schema-versions/$version/fields?per_page=100&page=3")->assertOk()->assertJsonPath('meta.total', 240)->assertJsonCount(40, 'data');
        $this->getJson("/api/v1/schema-versions/$version/fields?search=V239")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/schema-versions/$version/fields?per_page=101")->assertUnprocessable();
        $this->putFields($version, [$this->field('STALE')], 1)->assertConflict();
        $this->assertDatabaseMissing('survey_fields', ['code' => 'STALE']);
    }

    public function test_invalid_batches_roll_back_all_changes_and_validate_option_codes_per_field(): void
    {
        $version = $this->version($this->schema());
        $category = array_replace($this->field('SECTOR'), ['type' => 'category', 'unit' => null, 'options' => [['code' => 'a', 'label' => 'Público'], ['code' => 'a', 'label' => 'Privado']]]);
        $this->putFields($version, [$this->field(), $category])->assertUnprocessable();
        $this->assertSame(0, FieldDefinition::where('schema_version_id', $version)->count());
        $this->assertDatabaseMissing('survey_fields', ['code' => 'EDAD']);
        $category['options'][1]['code'] = 'b';
        $this->putFields($version, [$category, array_replace($category, ['code' => 'SECTOR_2'])])->assertOk();
        $this->postJson("/api/v1/schema-versions/$version/publish", ['expected_revision' => 2])->assertOk();
    }

    public function test_publication_checks_competing_draft_meanings_and_schema_scope(): void
    {
        $schema = $this->schema();
        $first = $this->version($schema);
        $second = $this->version($schema);
        $this->putFields($first, [$this->field()])->assertOk();
        $this->putFields($second, [array_replace($this->field(), ['unit' => 'months'])])->assertOk();
        $this->postJson("/api/v1/schema-versions/$first/publish", ['expected_revision' => 2])->assertOk();
        $this->postJson("/api/v1/schema-versions/$second/publish", ['expected_revision' => 2])->assertUnprocessable();
        $other = $this->schema();
        $this->postJson("/api/v1/schemas/$other/versions", ['base_version_id' => $first])->assertNotFound();
        $definition = FieldDefinition::where('schema_version_id', $first)->firstOrFail();
        $this->deleteJson("/api/v1/schema-versions/$second/fields/$definition->id", ['expected_revision' => 2])->assertNotFound();
    }

    public function test_database_protects_published_definitions_and_options_from_direct_writes(): void
    {
        $definition = FieldDefinition::where('type', 'category')->firstOrFail();
        foreach ([
            fn () => DB::table('survey_field_definitions')->where('id', $definition->id)->update(['label' => 'Changed']),
            fn () => DB::table('survey_field_options')->where('definition_id', $definition->id)->delete(),
            fn () => DB::table('survey_schema_versions')->where('id', $definition->schema_version_id)->update(['status' => 'draft', 'published_at' => null, 'content_hash' => null]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Published schema was modified.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_empty_schemas_cannot_publish_and_non_admins_cannot_create_them(): void
    {
        $version = $this->version($this->schema());
        $this->postJson("/api/v1/schema-versions/$version/publish", ['expected_revision' => 1])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]), ['admin']);
        $this->postJson('/api/v1/schemas', ['name' => 'Forbidden'])->assertForbidden();
    }
}

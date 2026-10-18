<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\SchemaRegistry;
use App\Models\Dataset;
use App\Models\FieldDefinition;
use App\Models\SchemaVersion;
use App\Models\SurveySchema;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchemaController extends Controller
{
    public function index(Request $r)
    {
        return Api::page(SurveySchema::orderBy('name')->orderBy('id'), $r);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000']);

        return Api::data(SurveySchema::create($v + ['created_by' => $r->user()->id])->fresh(), 201);
    }

    public function versions(Request $r, SurveySchema $schema)
    {
        return Api::page(SchemaVersion::where('schema_id', $schema->id)->orderByDesc('version_number'), $r);
    }

    public function createVersion(Request $r, SurveySchema $schema, SchemaRegistry $registry)
    {
        $v = $r->validate(['base_version_id' => 'nullable|uuid']);

        return Api::data($registry->createVersion($schema, $v['base_version_id'] ?? null, $r->user()->id), 201);
    }

    public function show(SchemaVersion $version)
    {
        return Api::data($version->toArray() + ['field_count' => FieldDefinition::where('schema_version_id', $version->id)->count()]);
    }

    public function fields(Request $r, SchemaVersion $version)
    {
        $v = $r->validate(['search' => 'sometimes|string|max:200']);
        $query = FieldDefinition::where('schema_version_id', $version->id)->join('survey_fields', 'survey_fields.id', '=', 'survey_field_definitions.field_id')->select('survey_field_definitions.*', 'survey_fields.code')->with('options')->orderBy('survey_fields.code');
        if (isset($v['search'])) {
            // A literal substring, not an SQL LIKE pattern provided by the caller.
            $query->whereRaw('(strpos(lower(label), lower(?)) > 0 OR strpos(lower(code), lower(?)) > 0)', [$v['search'], $v['search']]);
        }

        return Api::page($query, $r);
    }

    public function edit(Request $r, SchemaVersion $version, SchemaRegistry $registry)
    {
        $v = $r->validate([
            'expected_revision' => 'required|integer|min:1', 'fields' => 'required|array|list|min:1|max:100',
            'fields.*' => 'required|array:code,label,type,unit,required,sensitive,options',
            'fields.*.code' => ['required', 'string', 'max:64', 'distinct:strict', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/D'],
            'fields.*.label' => 'required|string|max:2000', 'fields.*.type' => 'required|in:boolean,number,category,ordered_category,multiselect,date,text',
            'fields.*.unit' => 'nullable|string|max:64', 'fields.*.required' => 'required|boolean', 'fields.*.sensitive' => 'required|boolean',
            'fields.*.options' => 'present|array|list|max:500', 'fields.*.options.*' => 'required|array:code,label',
            'fields.*.options.*.code' => 'required|string|max:128', 'fields.*.options.*.label' => 'required|string|max:2000',
        ]);

        return Api::data($registry->edit($version, $v['expected_revision'], $v['fields']));
    }

    public function remove(Request $r, SchemaVersion $version, string $definition, SchemaRegistry $registry)
    {
        $v = $r->validate(['expected_revision' => 'required|integer|min:1']);

        return Api::data($registry->remove($version, $definition, $v['expected_revision']));
    }

    public function publish(Request $r, SchemaVersion $version, SchemaRegistry $registry)
    {
        $v = $r->validate(['expected_revision' => 'required|integer|min:1']);

        return Api::data($registry->publish($version, $v['expected_revision']));
    }

    public function bindings(Request $r, Dataset $dataset)
    {
        return Api::page(DB::table('dataset_schema_bindings')->where('dataset_id', $dataset->id)->orderBy('schema_version_id'), $r);
    }

    public function bind(Request $r, Dataset $dataset)
    {
        $v = $r->validate(['schema_version_id' => 'required|uuid']);

        return Api::data(DB::transaction(function () use ($r, $dataset, $v) {
            $d = Dataset::lockForUpdate()->findOrFail($dataset->id);
            if ($d->status !== 'active') {
                throw new ApiProblem('dataset_closed', 'El estudio no está activo.', 409);
            }
            $version = SchemaVersion::findOrFail($v['schema_version_id']);
            if ($version->status !== 'published') {
                throw new ApiProblem('schema_not_published', 'Publique la versión antes de asociarla al estudio.', 409);
            }
            DB::table('dataset_schema_bindings')->insertOrIgnore(['dataset_id' => $d->id, 'schema_version_id' => $version->id, 'created_by' => $r->user()->id, 'created_at' => now()]);

            return DB::table('dataset_schema_bindings')->where('dataset_id', $d->id)->where('schema_version_id', $version->id)->first();
        }));
    }
}

<?php

return [
    'dictionary_version' => 'umss-hibrido-v1',
    'legacy_version' => 'umss-encuestas-2026-10-10',
    'questionnaire_version' => 'academico-titulados-v1',
    'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 10240),
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 10000),
    'max_columns' => (int) env('IMPORT_MAX_COLUMNS', 200),
    'max_sheets' => (int) env('IMPORT_MAX_SHEETS', 20),
    'max_cells' => (int) env('IMPORT_MAX_CELLS', 200000),
    'max_uncompressed_bytes' => (int) env('IMPORT_MAX_UNCOMPRESSED_BYTES', 104857600),
    'token_hours' => (int) env('ADMIN_TOKEN_HOURS', 8),
];

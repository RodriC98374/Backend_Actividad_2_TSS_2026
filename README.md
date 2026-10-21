# Backend de encuestas UMSS — v1

Laravel 13 / PHP 8.4 / PostgreSQL 17. API para importación XLSX/CSV, captura de titulados por invitación, revisión, estadísticas y exportación. El frontend React vive en otro repositorio.

- [Contrato OpenAPI implementado](openapi.json)
- [Peticiones de ejemplo](ejemplos.http)
- [Plan del proyecto, método de importación y defensa](PLAN_PROYECTO.md)
- [Arquitectura prevista para variables configurables y dashboards](ARQUITECTURA_V1.md) (especificación pendiente de implementación; primera versión sin IA)

## Obtener esta versión

```powershell
git clone --branch backend-v1 https://github.com/RodriC98374/Backend_Actividad_2_TSS_2026.git
cd Backend_Actividad_2_TSS_2026
```

La rama `backend-v1` contiene la base para integración. Este avance local amplía el contrato OpenAPI a 1.2.0 (51 operaciones): evaluación de hojas, registro de esquemas versionados y revisión de incidencias por grupos. Los formatos reales pueden revisarse sin depender de una plantilla institucional fija.

Verificación del avance: 28 pruebas, 1770 aserciones sobre PostgreSQL (`umss_testing`) y contrato de rutas comprobado. Incluye edición de 240 variables por lotes, revisión/exclusión de 1005 filas y protección de versiones publicadas. Las consultas de evaluación y revisión no publican datos automáticamente.

## Arranque con Docker

Ejecutar desde la carpeta del backend (PowerShell):

```powershell
Copy-Item .env.example .env
```

Cambiar `DB_PASSWORD` en `.env` antes de iniciar PostgreSQL. No compartir ni versionar `.env`. Si ya existe, conservarlo. Después:

```powershell
docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan app:create-admin admin@example.test --name="Administrador" --contacts
docker compose up -d app
```

El comando de administrador solicita la contraseña oculta (mínimo 12 caracteres). `--contacts` habilita carga/vista de originales y contactos; sin esa opción el administrador puede analizar y corregir datos sin acceder a esos valores. No hay cuentas o contraseñas predeterminadas.

API: `http://localhost:8000/api/v1`. Salud: `http://localhost:8000/up`. PostgreSQL desde un cliente local: `localhost:55432`, base y usuario de `.env`. Desde el contenedor: `db:5432`. Los datos persisten en el volumen de Docker.

CORS permite `FRONTEND_URL` (por defecto `http://localhost:5173`). Es un entorno de desarrollo; para despliegue deben configurarse HTTPS, servidor PHP apropiado, `APP_DEBUG=false`, secretos, copias de seguridad y orígenes definitivos.

## Verificación

```powershell
docker compose run --rm app php artisan test
docker compose run --rm app vendor/bin/pint --test
node scripts/build-openapi.cjs
```

Las pruebas usan exclusivamente `umss_testing`, creada al inicializar el volumen con `docker/init-testing.sql`. `phpunit.xml` fuerza PostgreSQL y esa base. Nunca cambiarla por una base de trabajo: las pruebas recrean sus tablas. Si se usa una instancia externa, crear previamente una base vacía `umss_testing` y conceder al usuario de pruebas permisos sobre ella.

`node scripts/build-openapi.cjs` regenera el contrato a partir de su fuente mantenible; Node solo es necesario para esa tarea, no para ejecutar la API. Las pruebas comprueban que todas las rutas públicas coincidan con OpenAPI.

## Organización

```text
app/Domain/Catalog/       catálogo y capacidades por pregunta
app/Domain/Imports/       lectura, mapeo, preparación, incorporación
app/Domain/Surveys/       normalización, publicación y estadísticas
app/Http/Controllers/    endpoints y validación HTTP
app/Http/Middleware/     administración y captura por invitación
app/Models/              persistencia PostgreSQL
app/Support/             respuestas y errores de API
app/Console/Commands/    creación segura de administradores
database/migrations/    tablas, relaciones y restricciones
config/survey-catalog.json catálogo versionado T/E/P/V/X
tests/Feature/           pruebas integradas con PostgreSQL
docker/                  configuración PHP y base de pruebas
```

## Estado de la V1 y contrato con frontend

La versión actual implementa el núcleo híbrido del sistema. El contrato que debe seguir el frontend es `openapi.json` (1.2.0); este README reúne el estado de implementación y las funcionalidades pendientes. No se requiere ninguna API externa ni IA.

## Actualizar una instalación existente

La migración `2026_10_17_000001_create_schema_registry.php` agrega seis tablas sin modificar ni borrar respuestas existentes. Crea cuatro instantáneas publicadas (dos encuestas y dos versiones del catálogo) a partir de `database/data/survey-catalog-2026-10-17.json`. Ese archivo es una entrada congelada de migración; no debe editarse. El adaptador de `GET /catalog` ahora lee esas instantáneas de PostgreSQL, conservando preguntas, opciones y orden.

Antes de actualizar una base con datos, guardar un respaldo. Aplicar con `docker compose exec -T app php artisan migrate --force`; no usar `migrate:fresh`, que elimina datos. La actualización de código y esta migración deben desplegarse juntas. Las versiones publicadas están protegidas contra edición y borrado mediante triggers de PostgreSQL.

## Registro de esquemas configurables: primer bloque implementado

1. `POST /schemas` crea un esquema con nombre y descripción.
2. `POST /schemas/{schema}/versions` crea un borrador vacío, o copia una versión publicada del mismo esquema mediante `base_version_id`.
3. `PUT /schema-versions/{version}/fields` guarda un lote de hasta 100 variables con `expected_revision`. Es un upsert por código: los campos omitidos se conservan. El límite total configurable es `SCHEMA_MAX_FIELDS` (1000 por defecto), distinto del tamaño de cada petición.
4. `GET /schema-versions/{version}/fields` lista variables por páginas de hasta 100, con búsqueda literal `search` en código/etiqueta. Cada variable puede tener hasta 500 opciones.
5. `POST /schema-versions/{version}/publish` publica un borrador no vacío, fija su hash y lo vuelve inmutable. Una edición posterior necesita otra versión. Tipo, unidad y sensibilidad de una identidad publicada no pueden cambiar: crear otro código para representar otro significado.
6. `POST /datasets/{dataset}/schema-bindings` asocia una versión publicada al estudio. Repetir la asociación no duplica registros ni cambia la versión de datos.

Tipos configurables: booleano, número, categoría, categoría ordenada, multiselección, fecha y texto. Los números requieren declarar `unit` (`none` para cantidades sin unidad); los demás tipos no llevan unidad. Categorías y multiselecciones requieren opciones con códigos únicos. Los metadatos `required` y `sensitive` son explícitos.

**Alcance de este bloque:** se pueden administrar las definiciones y sus versiones. La importación, captura y estadísticas todavía usan los catálogos iniciales; asociar un esquema nuevo no habilita por sí solo su uso en esos flujos. El siguiente bloque conecta las variables dinámicas con mapeo, reglas y publicación. No se almacenan aún respuestas de variables nuevas ni se ofrecen histogramas o series temporales dinámicas.

## Revisión administrativa sin listas completas en pantalla

`GET /imports/{import}/review-summary?validation_run_id=...` agrupa incidencias por campo, código y gravedad. Los grupos también son paginados. El resumen incluye incidencias totales y filas únicas del filtro, cantidad de filas preparadas seleccionables y revisión de configuración. Los filtros `field_id`, `code` y `severity` se combinan con AND y también están disponibles en `/issues`.

`POST /imports/{import}/review-exclusions` permite excluir un conjunto revisado usando esos filtros, sin enviar miles de IDs. Requiere `validation_run_id`, `expected_configuration_revision`, `selection` con al menos un filtro, `expected_affected_rows` y `reason`. El conteo debe coincidir con `summary.selectable_rows` consultado con el mismo filtro; incluye filas preparadas ya propuestas como excluidas. Se procesa en bloques internos de 500 dentro de una transacción y se guarda actor/motivo por fila. Una fila con varias incidencias se decide una sola vez.

La decisión incrementa la revisión y obliga a validar de nuevo. No corrige valores, resuelve incidencias ni publica automáticamente. Si el conteo o la revisión cambiaron devuelve 409 sin decisiones parciales. La revisión individual existente sigue disponible. Así el volumen total no depende del tamaño de página; continúan vigentes los límites de carga del sistema y el procesamiento síncrono.

## Qué hace esta versión

1. Un administrador inicia sesión y crea un estudio (`dataset`).
2. Carga un XLSX con varias hojas o CSV. Se guarda en almacenamiento privado y se conservan filas/celdas por posición, incluidos encabezados repetidos.
3. Selecciona hojas, tipo de encuesta, fila de encabezado, región y correspondencia entre columnas y preguntas. Confirma explícitamente cómo interpretar multiselecciones. Puede guardar esa configuración como perfil inmutable.
4. Valida: el backend normaliza booleanos, códigos, números y selecciones, señala valores inválidos y conserva estados de ausencia. La revisión es una instantánea; no cambia las estadísticas.
5. Revisa incidencias y decide inclusiones/exclusiones por fila. Al cambiar una configuración o decisión se incrementa su revisión y se debe validar otra vez.
6. Confirma la validación vigente con una clave UUID de idempotencia. En una transacción publica respuestas, guarda el resultado y aumenta `data_version`. Un reintento idéntico no duplica registros. Reemplazar otra importación excluye lógicamente sus respuestas y conserva el historial.
7. Como segunda entrada, genera una invitación individual para un titulado. El formulario valida sus respuestas y las incorpora directamente al mismo estudio. No crea cuentas ni perfiles de encuestados.
8. Consulta distribuciones, cruces de dos variables categóricas y resúmenes de números exactos; descarga esas estadísticas como CSV o XLSX.

Solo las respuestas `included=true` intervienen en estadísticas. Los originales y las preparaciones nunca se cuentan directamente. Las correcciones requieren motivo y revisión vigente, generan historial e incrementan la versión del estudio.

## Base de datos

PostgreSQL es obligatorio. La migración ejecutable es `database/migrations/2026_10_11_000001_create_survey_platform.php`. Las migraciones son la fuente de verdad para nombres, relaciones y restricciones.

| Etapa | Tablas principales | Función |
|---|---|---|
| Acceso | users, personal_access_tokens | Administradores y tokens con caducidad |
| Estudio | datasets | Agrupación y versión de datos incorporados |
| Esquemas | survey_schemas, survey_schema_versions, survey_fields, survey_field_definitions, survey_field_options, dataset_schema_bindings | Registro de variables y versiones inmutables; asociación explícita a estudios |
| Entrada por archivo | imports, import_sheets, raw_rows | Archivo privado, hojas y valores originales por posición |
| Configuración | import_profiles, row_decisions | Perfiles versionados y decisiones independientes de las validaciones |
| Preparación | validation_runs, prepared_rows, quality_issues | Instantáneas de validación y advertencias/bloqueos |
| Entrada por formulario | form_invitations, direct_submissions | Token de un uso y envío original; la FK es `form_invitation_id` |
| Respuestas | survey_responses, response_answers, contact_details | Origen exclusivo import/direct, respuesta por pregunta y contactos separados |
| Trazabilidad | change_log, import_commit_requests | Correcciones y confirmaciones idempotentes |
| Salida | exports | Versión, parámetros y archivo privado de estadísticas |

`response_answers.value` es JSONB: booleano real, número, código de opción, lista de códigos o texto. Un valor inválido no se reemplaza por una suposición: queda `value=null`, estado `invalida`, y se conserva el original. Los contactos se separan; el valor público de sus preguntas es null. Restricciones PostgreSQL evitan respuestas sin origen o con ambos orígenes, estados desconocidos y publicaciones duplicadas de una fila/envío.

## Para el equipo de frontend

Base local: `http://localhost:8000/api/v1`. Enviar `Accept: application/json`. Para JSON agregar `Content-Type: application/json`; para `FormData` dejar que el navegador calcule el boundary.

- Login: `POST /auth/login`, `{email,password}`. Guardar `data.access_token` en memoria y enviarlo como `Authorization: Bearer ...`. `GET /auth/me` devuelve permisos; `POST /auth/logout` revoca el token. No hay registro público administrativo.
- Respuesta normal: `{data: ...}`. Las listas paginadas añaden `meta: {page,per_page,total}`, máximo 100 por página.
- Error: `{message,code,errors}`. `422` señala validación; `409` obliga a refrescar revisión/estado; `401` requiere iniciar sesión o renovar invitación; `403` indica falta de permiso.
- `GET /catalog?survey_type=titulados` devuelve etiquetas, códigos exactos, tipos y operadores. React debe construir opciones con este catálogo y enviar los códigos, sin inventar equivalencias. Consultar `empleadores` por separado.
- Filas desde 1; columnas desde 0. Encabezados no son identificadores únicos. La evaluación de hojas sugiere encabezados y correspondencias; el mapeo y la región requieren confirmación explícita.
- Los endpoints de lectura/mapeo de originales requieren `can_view_contacts`. Las filas preparadas ocultan valores de contacto para otros administradores.
- `GET /datasets/{id}/summary` ofrece conteos por encuesta, origen e inclusión. Las gráficas usan endpoints `analytics`.
- Formulario: obtener token mediante creación administrativa de invitación; el enlace del frontend debe llevarlo en un fragmento privado o mecanismo equivalente, y usarlo solo como Bearer en `/capture/*`. No reutilizar el token administrativo ni ponerlo en URLs.
- `GET /capture/schema` define los campos y marca el instrumento como provisional. Requeridos X01/X03/X05/X06/X07; X04 si trabaja. X02 no puede preceder X01. T31=true autoriza contactos opcionales. `consent=true` confirma el envío.

## Evaluación de archivos con estructuras diferentes

`GET /imports/{import}/sheets/{sheet}/assessment?survey_type=titulados` examina las primeras 50 filas y devuelve hasta cinco candidatos de encabezado. Ordena por columnas reconocidas, cantidad de textos y posición; son sugerencias, no una detección definitiva. Admite títulos y filas vacías anteriores al encabezado, columnas reordenadas y nombres repetidos. Los encabezados compuestos de varias filas todavía requieren interpretación manual.

Agregar `&header_row=3` evalúa todas las filas posteriores a ese encabezado. Cada columna conserva su posición y devuelve conteos de vacíos/no vacíos, tipos originales, fórmulas sin resultado almacenado y valores distintos. Los valores cero y false cuentan como presentes. El conteo de distintos se limita a 100: `distinct_count_is_lower_bound=true` indica que hay más. Las columnas ausentes en filas CSV cortas cuentan como vacías.

`field_candidates` compara códigos, nombres, etiquetas y alias del catálogo normalizando mayúsculas, acentos y puntuación. No deduce equivalencias por semejanza ni transforma valores. Una lista vacía identifica columnas que necesitan interpretación manual; las repetidas conservan candidatos independientes y el configurador continúa rechazando mapeos escalares ambiguos. Multiselecciones señalan `requires_multiselect_rule=true`.

Requiere permiso de contactos porque muestra encabezados originales. Es una consulta: no modifica revisión, región, respuestas ni estadísticas. El perfil incluye cualquier pie de tabla o total posterior al encabezado; confirmar `data_region` antes de validar e incorporar. La evaluación estructural funciona para contenido desconocido, pero las estadísticas de encuestas siguen usando las preguntas del catálogo de titulados/empleadores. El registro permite definir esquemas nuevos; conectarlos con importación/captura/análisis sigue pendiente. XLS antiguo todavía no está soportado.

## Filtros y porcentajes

Todos los filtros se combinan con AND, hasta 20 condiciones. En consultas GET, enviar **un único parámetro `filters` con JSON codificado en URL**:

```js
const query = new URLSearchParams({
  survey_type: 'titulados',
  field_id: 'T12',
  filters: JSON.stringify([{field_id: 'T08', operator: 'eq', value: true}]),
});
fetch(`${base}/datasets/${datasetId}/analytics/numeric-summary?${query}`, {
  headers: {Accept: 'application/json', Authorization: `Bearer ${token}`},
});
```

| Tipo | Operadores |
|---|---|
| Booleano | eq / in con true o false; state_is |
| Categoría, rango o escala | eq / in con códigos del catálogo; state_is |
| Número de unidad confirmada | gte / lte / between con números; state_is |
| Multiselección | contains_any / contains_all con listas de códigos; state_is |
| Texto, contacto, T13 sin unidad confirmada | state_is |

Ejemplos: `{"field_id":"T12","operator":"between","value":[0,10]}`; `{"field_id":"X07","operator":"contains_any","value":["beca"]}`. En exportaciones `filters` es directamente una lista JSON en el cuerpo.

La distribución divide por encuestados con respuesta válida al campo, después de filtros. En multiselección cuenta una vez a cada persona por opción: la suma puede superar 100%. Los cruces usan pares válidos y porcentajes sobre todos esos pares. Sin respuestas válidas, porcentajes y medias son null. Las ausencias se informan por estado; no equivalen a cero ni a «No». T12 representa años, no meses. Los rangos T01/T21/T22 no producen medias exactas. T13 conserva números pero no ofrece operaciones numéricas hasta confirmar su unidad.

Los informes incluyen `data_version`, denominador, filtros y reglas. Las exportaciones requieren `expected_data_version`: si los datos cambiaron, devolverán 409. Son privadas, solo descargables por quien las solicitó y caducan a los 7 días. La eliminación física de archivos vencidos todavía requiere una tarea de mantenimiento.

## Seguridad y concurrencia

Sanctum emite tokens administrativos con vigencia configurable (8 h por defecto). Roles: administrador con/sin permiso de contactos; todos los administradores comparten estudios en v1. CORS limitado al origen configurado, límites por minuto para login/API/captura, archivos fuera del directorio público y errores sin trazas SQL. Los tokens de invitación se guardan como hash, son revocables y permiten una respuesta; el mismo envío devuelve su resultado previo. No se ejecutan fórmulas Excel ni se aceptan macros; CSV/XLSX de exportación neutralizan fórmulas.

Las escrituras de respuestas bloquean el estudio y aumentan su versión dentro de la transacción. Los informes usan bloqueo compartido para observar una versión coherente. Las migraciones y pruebas se ejecutan en PostgreSQL real. El bloqueo se verifica funcionalmente mediante revisiones/reintentos; todavía falta una prueba de carga con múltiples procesos concurrentes.

## Límites y trabajo siguiente

- Procesamiento síncrono: 10 MB por archivo, 10 000 filas totales, 200 columnas, 20 hojas, 200 000 celdas y 100 MB descomprimidos por defecto. Consultar capacidades y configuración. Falta probar rendimiento con libros institucionales grandes antes de ampliar límites o activar colas.
- XLSX y CSV; XLS antiguo no admitido. CSV usa UTF-8 o Windows-1252 explícito. Si la lectura falla, corregir y subir de nuevo; no se implementó el endpoint de retry del diseño anterior.
- La evaluación permite explorar estructuras diferentes sin un Excel institucional previo. Cada carga sigue requiriendo confirmar región, unidades, rutas condicionales y códigos. Se conservan valores inesperados como incidencias; no se completan datos ni se interpreta texto libre con IA.
- Detección de archivo idéntico por SHA-256 y protección contra doble incorporación. Falta conciliación de personas repetidas entre archivos distintos/formulario, detección por identificador del proveedor y revisión de posibles duplicados. No se exige CI ni identidad personal en esta versión.
- El formulario adicional X01–X07 está incluido en el alcance del proyecto y disponible por invitación. Su versión sigue identificada como provisional en el contrato para distinguirla del instrumento institucional. Autorreporte de habilidades adicional, radar, simulación y perfiles personales siguen fuera de v1.
- Correcciones administrativas por pregunta e inclusión están implementadas; no recalculan dependencias de otras preguntas automáticamente. Edición de contactos, cierre/resolución manual de incidencias y exportación de microdatos quedan pendientes.
- Solo hay permisos globales; quedan pendientes recuperación de contraseña, gestión visual de usuarios, permisos por estudio y despliegue productivo.

El frontend puede empezar ahora por login, estudios, importación/configuración/revisión, formulario, tablas de respuestas, gráficas y descarga de estadísticas. Las mejoras posteriores deben mantener o versionar este contrato.


### Puertos locales de ACREDI

Web: http://127.0.0.1:5174 · API: http://127.0.0.1:8001/api/v1. El puerto interno del contenedor PHP sigue siendo 8000. Vite usa puerto estricto y conecta su proxy al 8001 para evitar conflictos con comunidad-otb (5173/8000). PostgreSQL mantiene 55432.

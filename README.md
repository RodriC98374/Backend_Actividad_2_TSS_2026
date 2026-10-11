# Backend de encuestas UMSS — v1

Laravel 13 / PHP 8.4 / PostgreSQL 17. API para importación XLSX/CSV, captura de titulados por invitación, revisión, estadísticas y exportación. El frontend React vive en otro repositorio.

- [Contrato OpenAPI implementado](openapi.json)
- [Peticiones de ejemplo](ejemplos.http)

## Obtener esta versión

```powershell
git clone --branch backend-v1 https://github.com/RodriC98374/Backend_Actividad_2_TSS_2026.git
cd Backend_Actividad_2_TSS_2026
```

La rama `backend-v1` contiene el avance para integración. Frontend puede comenzar con el contrato OpenAPI 1.0.0 (37 operaciones). Estado verificado: 15 pruebas sobre PostgreSQL, formato PHP correcto y validación de OpenAPI; falta contrastar con el Excel institucional real.

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

La versión actual implementa el núcleo híbrido del sistema. El contrato que debe seguir el frontend es `openapi.json` (1.0.0); este README reúne el estado de implementación y las funcionalidades pendientes. No se requiere ninguna API externa.

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
- Filas desde 1; columnas desde 0. Encabezados no son identificadores únicos. Mapeo manual explícito en v1; no hay detección automática de estructura institucional.
- Los endpoints de lectura/mapeo de originales requieren `can_view_contacts`. Las filas preparadas ocultan valores de contacto para otros administradores.
- `GET /datasets/{id}/summary` ofrece conteos por encuesta, origen e inclusión. Las gráficas usan endpoints `analytics`.
- Formulario: obtener token mediante creación administrativa de invitación; el enlace del frontend debe llevarlo en un fragmento privado o mecanismo equivalente, y usarlo solo como Bearer en `/capture/*`. No reutilizar el token administrativo ni ponerlo en URLs.
- `GET /capture/schema` define los campos y marca el instrumento como provisional. Requeridos X01/X03/X05/X06/X07; X04 si trabaja. X02 no puede preceder X01. T31=true autoriza contactos opcionales. `consent=true` confirma el envío.

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
- Falta comprobar el Excel real y confirmar encabezados, unidades, rutas condicionales y códigos. Se conservan valores inesperados como incidencias; no se completan datos ni se interpreta texto libre con IA.
- Detección de archivo idéntico por SHA-256 y protección contra doble incorporación. Falta conciliación de personas repetidas entre archivos distintos/formulario, detección por identificador del proveedor y revisión de posibles duplicados. No se exige CI ni identidad personal en esta versión.
- El formulario X01–X07 es una propuesta provisional; requiere validación del equipo antes de usarlo para recolección oficial. Autorreporte de habilidades adicional, radar, simulación y perfiles personales siguen fuera de v1.
- Correcciones administrativas por pregunta e inclusión están implementadas; no recalculan dependencias de otras preguntas automáticamente. Edición de contactos, cierre/resolución manual de incidencias y exportación de microdatos quedan pendientes.
- Solo hay permisos globales; quedan pendientes recuperación de contraseña, gestión visual de usuarios, permisos por estudio y despliegue productivo.

El frontend puede empezar ahora por login, estudios, importación/configuración/revisión, formulario, tablas de respuestas, gráficas y descarga de estadísticas. Las mejoras posteriores deben mantener o versionar este contrato.

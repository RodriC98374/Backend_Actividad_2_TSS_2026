# Importación de respuestas desde PDF

Implementado: PDF digital de la encuesta de empleadores con la tabla dividida en bloques horizontales de 16 páginas. Se comprueban encabezados, orden de columnas, alineación de filas y marcas temporales antes de unir los bloques. Se admiten hasta 64 páginas; no se admiten escaneos, informes con gráficos ni otros diseños de cuestionario. No es un conversor PDF genérico.

Cada bloque horizontal contiene 32 columnas: marca temporal, E00–E12, P01–P10 y V01–V08. El primer bloque incluye encabezados; los siguientes continúan las respuestas sin repetirlos. Los encabezados reconocidos se identifican con los códigos del catálogo; el texto original del encabezado y la página/fila de cada celda permanecen en `raw_rows.original_cells`.

El lector conserva las respuestas textualmente. Si un carácter cruza o llega al límite de una celda, se marca una posible pérdida/superposición de texto. La validación registra `pdf_text_clipped`, deja esa respuesta como `indeterminada` y la excluye del denominador válido. Esta detección es conservadora y no garantiza identificar todas las pérdidas: revisar el original sigue siendo necesario. No se reconstruyen textos faltantes ni se completan opciones por coincidencias parciales.

## Uso

1. En Importaciones, cargar el PDF original, evitando el Excel producido por una conversión automática.
2. Revisar la encuesta de empleadores y sus columnas. Ignorar la marca temporal si no se necesita; confirmar las reglas de preguntas con selección múltiple.
3. Revisar los conteos y observaciones. La carga por sí sola no publica datos.
4. Confirmar la incorporación para ver las estadísticas y los denominadores por pregunta.

La importación antigua no se corrige ni elimina automáticamente. Si ya estaba publicada, usar la sustitución explícita y revisar el alcance; si solo estaba pendiente, no aporta estadísticas.

## Instalación y límites

`docker compose build app` y `docker compose up -d --no-deps app` instalan el lector aislado en `/opt/pdf`. Fuera de Docker, instalar `requirements-pdf.txt` en un entorno virtual y configurar `PDF_PYTHON` con su ejecutable. El proceso tiene 45 segundos de tiempo máximo; en Linux, 512 MiB de memoria y 40 segundos de CPU. Se mantienen los límites de tamaño, filas y celdas existentes.

## Verificación

- `python tests/pdf_reader_test.py`: unión horizontal, procedencia, rechazo de páginas faltantes, geometría inconsistente, encabezados ajenos, continuidad inválida y límites; lectura de un PDF sintético sin información real.
- `php artisan test --filter='SurveyPlatformTest|SheetAssessmentTest'`: carga, revisión, confirmación y estadísticas; un valor marcado como recortado no entra en el denominador válido. Base exclusiva `umss_testing`.
- El PDF original revisado produce 53 respuestas, 32 columnas y 229 celdas marcadas para revisión. Esta comprobación de lectura no incorpora datos a la base ni certifica la integridad de las respuestas originales.

Pendiente: OCR, otros diseños PDF y reconstrucción manual de respuestas perdidas a partir de una fuente íntegra.

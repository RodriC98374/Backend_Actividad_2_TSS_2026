# Plan de continuidad y defensa del proyecto

Fecha de revisión: 17 de octubre de 2026. Documento de trabajo; las funcionalidades propuestas abajo todavía no están implementadas salvo donde se indique expresamente.

Alcance confirmado: la primera versión usa reglas y configuración del administrador, con variables nuevas y dashboards compatibles con sus tipos. IA queda reservada para una posible segunda versión. La especificación concreta de datos, validación, publicación y migración está en [ARQUITECTURA_V1.md](ARQUITECTURA_V1.md); ese documento precisa las decisiones técnicas de este plan.

Actualización de implementación: el contrato local es 1.2.0 (51 operaciones). Se agregó el registro de esquemas, borradores editables por lotes, versiones publicadas inmutables y asociación a estudios. El catálogo legacy se conserva en PostgreSQL mediante migración aditiva. También se implementó revisión de incidencias agrupada, filtros paginados y exclusiones por conjunto con conteo confirmado. La integración de variables nuevas con importación/captura/estadísticas continúa pendiente (B2–B4); la revisión no exige abrir individualmente todas las filas.

## 1. Objetivo y alcance

Construir una plataforma que reciba archivos de encuestas con estructuras diferentes, permita definir cómo interpretarlos, revise la calidad de sus datos y genere estadísticas trazables. Complementar la importación con un formulario de captura directa.

El requisito «cualquier Excel» se traduce en independencia de una plantilla fija y capacidad de configurar variables desconocidas. No significa interpretar automáticamente el significado de cualquier celda. Una columna «Tiempo» puede representar años, meses o minutos: el usuario debe confirmar su significado y unidad.

La unidad de entrada para la primera entrega será una tabla rectangular: una fila por registro y una columna por variable, con multiselecciones configurables. Libros con tablas cruzadas, fichas verticales, varias tablas en una misma hoja, imágenes o encabezados complejos necesitan adaptación o una transformación específica. Debemos identificarlos y explicar qué preparación requieren.

Hay tres problemas distintos:

| Dimensión | Ejemplo | Solución prevista |
|---|---|---|
| Formato físico | XLSX, CSV, XLS antiguo | Lector específico por formato y validación de límites |
| Estructura | Encabezado en fila 5, columnas en otro orden | Vista previa, sugerencias y selección de región/columnas |
| Significado | «Trabaja», «Situación laboral», «Tiempo» | Esquema configurable, opciones, unidades y equivalencias confirmadas |

## 2. Estado comprobado

### Frontend

Se consultaron las ramas remotas de `RodriC98374/Frontend_Actividad_2_TSS_2026`. Solo aparece `main`, en `4f19709`, el mismo commit de la copia local. No se encontraron cambios locales pendientes. Esto no permite conocer trabajo que otros integrantes aún no hayan subido.

Existe React/Vite/Tailwind, enrutamiento básico, layout, página de inicio y página no encontrada. La pantalla inicial muestra «Estructura base lista». No existen pantallas de autenticación, estudios, importación, revisión, formulario o estadísticas.

El cliente HTTP es básico: serializa todos los cuerpos como JSON. Antes de integrar la carga de archivos debe manejar `FormData`, autenticación y los errores estructurados de la API.

### Backend

Laravel, PostgreSQL y PhpSpreadsheet. Contrato local OpenAPI 1.2.0 con 51 operaciones.

Implementado:

- Acceso administrativo y permisos globales de contactos.
- Estudios, lectura XLSX/CSV y conservación privada del archivo y celdas originales.
- Evaluación de hojas, sugerencias de encabezados y perfil de columnas.
- Configuración manual de región, correspondencias y multiselecciones; perfiles guardados.
- Validación previa, incidencias, decisiones por fila e incorporación transaccional.
- Protección contra incorporar dos veces el mismo archivo y contra reintentos duplicados.
- Respuestas, correcciones con historial, distribuciones, cruces y resúmenes numéricos.
- Exportación de estadísticas en CSV/XLSX.
- Invitaciones individuales y captura del formulario adicional.

La evaluación de hojas y su documentación siguen como cambios locales sin commit. Deben integrarse en un commit revisable antes de coordinar la entrega al equipo; este documento no implica que se hayan publicado.

La última ejecución del incremento de esquemas y revisión por grupos aprobó 28 pruebas y 1770 aserciones en PostgreSQL. Incluye 240 variables editadas por lotes y una revisión de 1005 filas. La verificación de GitHub del frontend corresponde a la revisión de planificación, no a una nueva consulta durante este incremento.

Limitación central: ya se pueden registrar versiones de esquemas nuevos, pero las respuestas y estadísticas todavía dependen de las instantáneas iniciales de titulados/empleadores. La conexión con variables dinámicas es el siguiente bloque. El formulario tiene API, pero aún no tiene pantalla React.

## 3. Método actual: importación asistida por reglas

Puede describirse como un proceso ETL —extracción, transformación y carga— con una zona de preparación y confirmación humana.

1. **Extraer:** `WorkbookReader` lee hojas y celdas con PhpSpreadsheet para XLSX y un lector CSV para texto delimitado. Conserva posiciones y tipos. Las fórmulas no se ejecutan; se utiliza su resultado almacenado cuando existe.
2. **Conservar originales:** se guarda el archivo privado y las filas originales. Se puede rastrear una respuesta hasta su importación, hoja y fila.
3. **Explorar:** `SheetAssessment` examina hasta 50 filas iniciales y devuelve hasta cinco candidatos de encabezado. Prioriza coincidencias con el catálogo, cantidad de textos y posición. No expresa una probabilidad estadística de acierto.
4. **Perfilar:** al indicar el encabezado se cuentan vacíos, tipos originales y valores distintos por columna. El conteo de distintos se limita a 100 y avisa cuando es una cota inferior. En CSV los tipos originales son texto; esto todavía no es inferencia de tipos semánticos.
5. **Relacionar:** se proponen coincidencias exactas con códigos, nombres, etiquetas o alias, tras normalizar mayúsculas, acentos y puntuación. Una columna desconocida requiere decisión manual. No se usa IA ni aprendizaje automático.
6. **Configurar:** el administrador confirma encuesta, región, columnas y representación de multiselecciones. Puede guardar un perfil; hoy debe copiar y confirmar sus ajustes, no se reaplica automáticamente por semejanza.
7. **Normalizar y validar:** `Normalizer` interpreta los valores según las reglas conocidas. Se distinguen respuestas válidas, ausentes, inválidas, indeterminadas y no disponibles. Un vacío no se transforma en cero ni en «No».
8. **Revisar e incorporar:** una validación guarda una instantánea. El administrador revisa incidencias y confirma la incorporación. Los cambios de configuración obligan a validar de nuevo.
9. **Analizar:** las estadísticas cuentan respuestas incorporadas e incluidas. Los resultados identifican versión, filtros y denominador.

Ejemplo: dos archivos contienen los mismos registros. En uno, `T08` está en la columna A y el encabezado en la fila 1; en otro, la pregunta está en la columna D y el encabezado en la fila 4. Se configuran ambos hacia la misma variable. Su equivalencia debe demostrarse con resultados estadísticos iguales en estudios separados, evitando sumar dos veces a las mismas personas.

## 4. Arquitectura que debemos completar

Mantener tres capas: originales, preparación y datos publicados. Evitar crear una tabla de base de datos por cada archivo Excel.

### Esquemas configurables y versionados

Propuesta de modelo, pendiente de implementación:

- `survey_schemas`: identidad del cuestionario o esquema del estudio.
- `survey_schema_versions`: versión publicada e inmutable del esquema.
- `survey_fields`: identidad estable de cada variable dentro del esquema.
- `survey_field_definitions`: etiqueta, tipo, unidad, sensibilidad y validaciones por versión de esquema.
- `survey_field_options`: códigos y etiquetas de opciones por definición versionada.
- `dataset_schema_bindings`: versiones admitidas explícitamente por estudio.
- `restricted_response_values`: almacenamiento separado de valores sensibles de variables configurables.
- `dashboard_views`: especificaciones de gráficos y filtros que referencian definiciones de variables.
- Configuración de importación versionada: región, correspondencias, conversiones explícitas y representación de multiselecciones.

Los catálogos actuales pasarían a ser esquemas iniciales. Las respuestas y validaciones deben referenciar la versión exacta utilizada. Un cambio posterior de etiqueta, unidad o regla no debe reinterpretar silenciosamente resultados históricos. La migración será aditiva y conservará importaciones y respuestas existentes.

Los tipos iniciales propuestos son booleano, número, categoría, selección múltiple, fecha y texto; los datos de contacto necesitan una clasificación de sensibilidad independiente. Las operaciones disponibles dependen del tipo: distribución para categorías, resumen para números con unidad confirmada y filtros adecuados para cada caso. Los números deben distinguirse de identificadores como códigos con ceros iniciales.

### Correspondencias y conversiones explícitas

Agregar equivalencias configurables: por ejemplo, `1/0` hacia `sí/no` cuando el administrador lo confirme. Actualmente el normalizador booleano admite booleanos reales y textos sí/no; no admite automáticamente cualquier codificación numérica.

Agregar reglas explícitas para separador decimal, fechas y unidades. Un valor como `1,200` es ambiguo y debe requerir configuración. Guardar la regla aplicada y conservar el original.

Para encabezados repetidos se mantendrá la posición como identidad. Para columnas desconocidas se ofrecerán tres acciones: relacionar con una variable existente, crear una variable en un esquema nuevo/versionado o excluir de la publicación. El original permanece disponible.

Antes de combinar archivos en un mismo estudio, verificar compatibilidad de variables, opciones y unidades. Comparar por identificadores y versiones; no por etiquetas parecidas.

### Preparación de estructuras complejas

Primero completar tablas rectangulares y selección de región. Después incorporar encabezados de varias filas y rangos combinados, con vista previa de la interpretación. Varias tablas en una hoja y transposición deben tratarse como capacidades separadas, con pruebas propias.

No rellenar celdas vacías automáticamente: una celda combinada de encabezado y una respuesta faltante tienen significados diferentes.

## 5. Orden de trabajo y criterios de cierre

Las etapas son unidades de entrega, no estimaciones de días. La fecha final depende de la disponibilidad del equipo y de la fecha académica, que todavía no se ha indicado.

| Orden | Entrega | Trabajo backend | Trabajo frontend | Criterio para cerrar |
|---|---|---|---|---|
| 1 | Base reproducible | Revisar y guardar avance local; verificar pruebas; preparar datos sintéticos y arranque documentado | Adaptar cliente HTTP para token, FormData y errores; login y selección de estudio | Otro integrante puede arrancar ambos proyectos y acceder a un estudio |
| 2 | Primer recorrido completo | Mantener contrato actual y corregir fallos que aparezcan al integrar | Subir archivo, ver hojas, elegir encabezado/región, mapear, validar, revisar e incorporar | Un archivo sintético produce una distribución comprobable desde la web |
| 3 | Variables desconocidas | Esquemas y versiones persistentes; migración del catálogo; validación y estadísticas por tipo; contrato actualizado | Crear/elegir esquema y variables, resolver columnas desconocidas | Un archivo con preguntas nuevas se incorpora y analiza sin editar código |
| 4 | Formatos heterogéneos | Equivalencias de valores, unidades y formatos numéricos/fecha; perfiles compatibles; soporte XLS con pruebas; mejorar encabezados | Vista previa de conversiones y reutilización de perfiles con confirmación | Archivos equivalentes con diseños/codificaciones distintos producen el mismo resultado |
| 5 | Captura y reportes integrados | Ajustar captura al esquema/versionado; validar convivencia de ambos orígenes; revisar duplicados | Formulario por invitación, tablas, filtros, gráficas y descarga | Una respuesta del formulario aparece en el estudio y en el reporte correcto |
| 6 | Entrega y defensa | Pruebas de límites/rendimiento; despliegue, respaldo y recuperación según entorno elegido | Estados de carga, vacíos y errores; revisión de uso y presentación | Demostración repetible con evidencia de calidad, trazabilidad y límites |

Frontend puede trabajar en las etapas 1–2 con la API actual mientras se diseña la etapa 3. El nuevo contrato debe acordarse antes de construir las pantallas de esquemas configurables. No conviene esperar a terminar toda la generalización del backend para comenzar la integración.

Prioridad de backend: el registro y la migración aditiva de B1 ya están implementados. Sigue B2 (mapeo y validación de variables nuevas), cerrando el contrato de precisión numérica antes de almacenar sus respuestas. Frontend puede integrar el recorrido de la etapa 2 y los endpoints de registro con la API actual mientras avanza esa ampliación. XLS antiguo amplía formatos físicos, pero por sí solo no resuelve la generalización semántica.

## 6. Pruebas de aceptación y evidencia

Usar archivos sintéticos con resultados esperados calculados manualmente y conservar las configuraciones utilizadas. Cuando exista un archivo real anonimizado, añadirlo como validación externa; su ausencia no bloquea el desarrollo.

| Caso | Resultado exigido |
|---|---|
| Encabezado en otra fila y columnas reordenadas | Misma cantidad de respuestas y estadísticas que el archivo base |
| Encabezados repetidos o vacíos | Posiciones independientes; confirmación de correspondencias ambiguas |
| Preguntas completamente nuevas | Creación de variables sin cambios de código, una vez completada la etapa 3 |
| Valores sí/no, booleanos y 1/0 | Equivalencia solo cuando existe regla explícita para esa representación |
| Vacíos, cero, false e inválidos | Conteos separados y denominador correcto |
| Multiselección en celda o columnas de opciones | Mismas selecciones finales con reglas confirmadas |
| Pies de tabla y totales | Exclusión mediante región/decisión y vista previa de filas afectadas |
| CSV con delimitadores, codificación o decimales distintos | Lectura/configuración explícita; ninguna conversión ambigua silenciosa |
| Fórmulas sin resultado almacenado | Incidencia; nunca ejecución ni sustitución inventada |
| Mismo archivo o petición repetida | No duplica registros publicados |
| Misma persona en archivos distintos | Política explícita; no afirmar deduplicación personal mientras no exista |
| Cambios de esquema/configuración | Historial reproducible y rechazo de validaciones desactualizadas |
| Archivo fuera de límites o incompatible | Error comprensible y sin publicación parcial |
| Acceso sin permiso de contactos | No expone originales/contactos protegidos |

Registrar tiempos y memoria con tamaños crecientes dentro de los límites actuales: 10 MB, 10 000 filas, 200 columnas, 20 hojas y 200 000 celdas; también hay un límite de contenido XLSX descomprimido de 100 MB. No aumentar límites ni prometer escalabilidad hasta medir. Incorporar colas si las mediciones justifican procesamiento en segundo plano.

## 7. Pendientes y riesgos concretos

- El mayor hueco de producto es la interfaz: sin recorrido completo no hay demostración web, aunque existan endpoints.
- El catálogo inicial ya se conserva en versiones inmutables de PostgreSQL; las reglas operativas aún tienen identificadores específicos. Generalizar requiere conectar importación, captura, filtros, estadísticas y permisos con los nuevos esquemas.
- Los perfiles actuales se basan en posiciones. Reutilizarlos en otro orden requiere comprobar encabezados y mostrar una nueva propuesta de correspondencias.
- La evaluación actual perfila todas las filas posteriores al encabezado, incluidos totales. Falta alinearla con la región de datos elegida.
- La detección de archivo idéntico no identifica a personas repetidas en archivos diferentes. No fusionar registros por nombre o correo de forma automática.
- Revisar validación de teléfonos: se observó un salto de línea dentro de la expresión regular en `Normalizer.php`. Confirmar el fallo con un caso de prueba y corregirlo en el siguiente incremento.
- Revisar consistencia de respuestas dependientes tras correcciones administrativas y reglas de ausencia.
- Pendientes para operación: cierre de incidencias, mantenimiento de archivos vencidos, recuperación de contraseña, permisos por estudio y despliegue con respaldos. Priorizar según el entorno de entrega.
- Radar, simulación y perfiles personales siguen fuera de esta entrega; no condicionan el flujo de importación y análisis.

## 8. Cómo defender el proyecto

Explicación breve:

> La plataforma integra encuestas procedentes de archivos con distintas estructuras mediante una importación asistida. Conserva los originales, propone encabezados y correspondencias, permite configurar el significado de las variables y valida los datos antes de incorporarlos. Cada resultado estadístico se puede rastrear a sus registros, reglas y versión de datos. El formulario adicional incorpora respuestas al mismo modelo.

Al describir el estado actual, aclarar que la configuración de variables nuevas está planificada y que hoy el análisis publicado usa el catálogo existente. No presentar el diseño futuro como una función terminada.

Preguntas previsibles:

- **¿Por qué no necesitamos conocer previamente el Excel?** Porque la estructura se configura al importar y los originales se conservan. Para campos nuevos se completará el esquema configurable.
- **¿Por qué hay confirmación humana?** Porque el significado, las unidades y ciertas codificaciones no pueden deducirse de manera inequívoca a partir de una celda.
- **¿Usa inteligencia artificial?** Actualmente usa lectores de archivos y reglas deterministas. Cada transformación puede explicarse y repetirse.
- **¿Cómo sabemos que el reporte es correcto?** Con archivos de resultados conocidos, comparación entre formatos equivalentes, denominadores explícitos e historial de versiones.
- **¿Qué aporta técnicamente?** Integra lectura heterogénea, mapeo configurable, control de calidad, trazabilidad y análisis sobre un modelo común.
- **¿Puede procesar cualquier libro?** Puede adaptarse a tablas de los formatos soportados y dentro de los límites documentados. Estructuras no tabulares necesitan preparación o una capacidad adicional.

Demostración propuesta: cargar una tabla sencilla; cargar una variante de su estructura en otro estudio; mostrar correspondencias e incidencias; comprobar que ambas producen el resultado esperado; incorporar una respuesta por formulario; exportar el reporte y mostrar su versión. Incorporar el caso de variables nuevas cuando la etapa 3 esté terminada.

## 9. Seguimiento del equipo

Por cada entrega registrar responsable, estado, evidencia y bloqueo. Estados sugeridos: pendiente, en desarrollo, en revisión y terminado. «Terminado» requiere cumplir el criterio de cierre, no solo tener código.

Datos aún necesarios para convertir estas etapas en calendario: fecha de entrega/defensa, integrantes disponibles, reparto frontend/backend y requisito de publicación. Hasta contar con ellos, trabajar por el orden de entregas anterior.

## Referencias del repositorio

- [Estado y arranque](README.md)
- [Arquitectura de la primera versión y criterios de implementación](ARQUITECTURA_V1.md)
- [Contrato API](openapi.json)
- [Ejemplos HTTP](ejemplos.http)
- [Lectura de archivos](app/Domain/Imports/WorkbookReader.php)
- [Evaluación de hojas](app/Domain/Imports/SheetAssessment.php)
- [Configuración, validación e incorporación](app/Domain/Imports/ImportService.php)
- [Normalización](app/Domain/Surveys/Normalizer.php)
- [Captura directa](app/Http/Controllers/CaptureController.php)

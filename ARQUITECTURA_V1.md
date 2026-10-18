# Arquitectura de la primera versión: importación configurable y análisis

Fecha: 17 de octubre de 2026.

Estado: especificación de implementación basada en el alcance confirmado por el usuario. El contrato ejecutable actual es `openapi.json` 1.2.0. Este documento concreta las etapas 3 y 4 de [PLAN_PROYECTO.md](PLAN_PROYECTO.md) y prevalece sobre propuestas anteriores cuando define una decisión más específica.

Primer incremento implementado: `survey_schemas`, `survey_schema_versions`, `survey_fields`, `survey_field_definitions`, `survey_field_options` y `dataset_schema_bindings`; `SchemaRegistry`, migración congelada de catálogos legacy y API de edición por lotes/publicación. La edición usa `PUT /schema-versions/{version}/fields`, no reemplaza de una vez toda la definición. El resto del diseño, incluyendo referencias desde respuestas dinámicas, reglas avanzadas, `restricted_response_values` y `dashboard_views`, permanece pendiente. Se agregaron además resumen paginado de incidencias y exclusiones por grupo sobre el flujo de importación existente. El catálogo legacy ya se lee de las versiones inmutables de PostgreSQL.

## 1. Decisiones de alcance

La primera versión del producto funcionará mediante reglas deterministas y configuración del administrador. No requiere IA, servicios de interpretación externos ni suscripciones. Una posible segunda versión podrá proponer configuraciones con IA sobre la misma interfaz de sugerencias; no condiciona ninguna entrega de esta versión.

Resultado requerido: un administrador carga una tabla de estructura desconocida, configura sus variables sin editar código, revisa una preparación de los datos, confirma su incorporación y consulta un dashboard compatible con los tipos de variables y la calidad disponible.

El formulario adicional se conserva como otra entrada al mismo modelo de respuestas. Se sirve a partir de una versión publicada de un esquema.

### Compatibilidad verificable

| Entrada | Estado actual | Objetivo de esta versión |
|---|---|---|
| XLSX tabular, una o varias hojas | Lectura implementada | Importación configurable completa |
| CSV delimitado | Lectura implementada | Configuración explícita de delimitador, codificación y conversiones |
| XLS antiguo | No implementado | Adaptador y pruebas antes de anunciar su disponibilidad |
| Títulos previos, filas vacías, columnas reordenadas/repetidas | Posiciones y selección de región disponibles | Asistente con vista previa y validación de correspondencias |
| Encabezados de varias filas | No interpretados como conjunto | Selección explícita de filas de encabezado y previsualización de etiquetas compuestas; conservar coordenadas |
| Varias tablas en una hoja | Una región por hoja actualmente | V1 permite seleccionar una tabla/región; separar las demás en hojas/archivos para importarlas |
| Tablas transpuestas, fichas, imágenes, tablas dinámicas agregadas | No hay transformación específica | Explicar preparación requerida; no tratarlas silenciosamente como registros individuales |
| XLSB, libros cifrados, formatos con macros | No soportados | Rechazo controlado, sin publicación parcial |

La publicidad y `/capabilities` mostrarán únicamente formatos y operaciones cuya implementación esté probada. Un XLSX puede ser legible y, aun así, requerir reorganización de su contenido. «Estructuras diferentes» no equivale a una garantía sobre todo archivo que Excel pueda abrir.

## 2. Recorrido del administrador

1. Elegir o crear un estudio y cargar el archivo.
2. Revisar hojas, elegir la tabla, filas de encabezado y región de datos. Ver exactamente qué filas quedan dentro y fuera.
3. Elegir un esquema existente o crear un borrador de esquema. El esquema es el conjunto de variables y sus reglas.
4. Para cada columna de la región: relacionar con una variable, crear una variable o excluirla expresamente. Una columna sin decisión bloquea la preparación definitiva; se conserva siempre en el original.
5. Definir tipos, unidades, opciones, equivalencias, ausencias y reglas de lectura. Revisar ejemplos «original → resultado».
6. Publicar el esquema y validar la configuración. La publicación de un esquema por sí sola no incorpora respuestas.
7. Revisar conteos e incidencias, resolver bloqueos y decidir exclusiones con motivo. Confirmar que se ha revisado el resultado vigente.
8. Incorporar datos y abrir el dashboard. Cambiar filtros o visualización no modifica los registros.

Los borradores se guardan con revisión para poder continuar después. Guardar un perfil permite reutilizar reglas, pero otra carga debe verificar región y correspondencias antes de aplicarlas. Los encabezados por posición pueden cambiar entre archivos.

## 3. Capas y responsabilidades

Mantener Laravel/PostgreSQL y ampliar los módulos existentes; no crear una base o tabla física por cada Excel.

| Capa | Responsabilidad | Persistencia |
|---|---|---|
| Originales | Leer y conservar la fuente y sus coordenadas | Archivo privado, `imports`, `import_sheets`, `raw_rows`, `direct_submissions` |
| Definiciones | Describir variables y reglas versionadas | Esquemas, versiones, definiciones, opciones y perfiles |
| Preparación | Aplicar reglas sin alterar originales ni estadísticas | `validation_runs`, `prepared_rows`, `quality_issues`, decisiones |
| Publicación | Incorporar/corregir de forma transaccional y auditable | `survey_responses`, `response_answers`, historial y versión de datos |
| Análisis | Calcular agregaciones autorizadas y reproducibles sobre datos vigentes | Consultas, especificaciones de dashboard y exportaciones |

Servicios propuestos:

- `WorkbookReader` y adaptadores por formato: lectura, límites, preservación de coordenadas y fórmulas almacenadas.
- `SchemaRegistry`: resolver una versión exacta, editar borradores y publicar definiciones inmutables.
- `SheetAssessment`: sugerencias y perfil dentro de la región elegida; nunca confirmar significado por sí mismo.
- `MappingValidator`: cobertura de columnas, compatibilidad de variables y comprobación de reglas.
- `ValueNormalizer`: normalización por tipo y reglas declarativas; sustituye progresivamente dependencias de códigos T/X/E específicos.
- `ImportService`: orquestación, instantáneas de validación, decisiones y publicación.
- `ResponseWriter`: escritura común para importación y captura; tratamiento de campos sensibles según definición.
- `Analytics`: filtros y agregaciones según capacidades de cada definición; metadatos de calidad y denominador.

Los controladores autentican, validan el contrato HTTP y llaman a servicios. Las reglas estadísticas y de normalización no se duplican en React.

## 4. Modelo de variables y versiones

Modelo aditivo propuesto:

| Entidad nueva | Contenido y restricciones |
|---|---|
| `survey_schemas` | Identidad del esquema, nombre y creador |
| `survey_fields` | Identidad estable de variable dentro del esquema; UUID y código único por esquema |
| `survey_schema_versions` | Esquema, número de versión, estado draft/published/retired, revisión del borrador, hash de definición, autor y fecha de publicación |
| `survey_field_definitions` | Una definición por variable y versión: etiqueta, tipo, unidad, opciones de validación, sensibilidad y capacidades derivadas |
| `survey_field_options` | Código estable, etiqueta y orden de opción dentro de una definición; código único por definición |
| `dataset_schema_bindings` | Versiones publicadas admitidas por estudio; el alta es explícita, no consecuencia silenciosa de subir un archivo |
| `restricted_response_values` | Valores de variables sensibles referenciados por respuesta y definición; acceso separado del serializador general |
| `dashboard_views` | Estudio, nombre, revisión y especificación de widgets; guarda configuración, no otra copia de los datos |

Reglas de identidad:

- El nombre del encabezado no identifica una variable. La referencia es un UUID; los códigos actuales se conservan como identificadores legibles de los esquemas iniciales.
- Cambiar la etiqueta requiere otra versión de definición, conservando la identidad si el significado sigue siendo el mismo.
- Cambiar el significado, tipo o unidad canónica crea una variable nueva en V1. Convertir unidades del archivo hacia una unidad canónica conocida se hace en el mapeo.
- Agregar opciones requiere otra versión de esquema; no se amplían categorías durante una publicación de datos.
- Una versión publicada no se edita ni elimina si tiene referencias. Se puede retirar de nuevas cargas sin impedir consultar lo ya publicado.
- Un estudio puede contener varios esquemas/versiones, pero cada consulta o widget selecciona una versión exacta. V1 no combina automáticamente variables de versiones distintas por tener etiquetas iguales.
- Las invitaciones fijan su versión de esquema. Publicar una versión nueva no cambia un formulario que ya fue enviado.

Agregar referencias de versión a hojas configuradas, preparaciones, respuestas, invitaciones, perfiles, exportaciones y especificaciones de dashboard. Cada respuesta usa una única versión; cada respuesta por campo referencia una definición de esa versión.

Restricciones esperadas en PostgreSQL: unicidad de versión por esquema, de definición por variable/versión y de respuesta/definición; claves foráneas que impidan respuestas a definiciones ajenas a la versión de su registro. Si se replica `schema_version_id` en las respuestas por campo, usar claves foráneas compuestas para garantizar consistencia con respuesta y definición. Mantener la exclusividad de origen importación/formulario y las protecciones contra publicación duplicada.

Los valores sensibles se ocultan también en preparación, historial, filtros y exportaciones para quien no tiene permiso. La protección debe depender de la clasificación de la variable, no solo de una lista fija de códigos de contacto. En V1 no se habilitan gráficas de identificadores o datos de contacto.

## 5. Qué configura el administrador

| Propiedad | Ejemplo | Regla |
|---|---|---|
| Etiqueta y descripción | Tiempo sin empleo | No sustituyen el identificador estable |
| Tipo | Número | Elegir entre capacidades admitidas |
| Unidad canónica | Meses | Exigida cuando el dato representa una medida; «sin unidad» debe ser explícito cuando corresponde |
| Restricciones | Mínimo 0, entero | Sin límites globales inventados: otros números pueden admitir negativos |
| Opciones | Público, privado, independiente | Códigos estables; cambios mediante nueva versión |
| Sensibilidad | General o restringido | Aplicada en todas las rutas de lectura |
| Obligatoriedad | Requerida | Falla si no hay respuesta válida, salvo condición de no aplicación satisfecha |
| Condiciones | Sector aplica cuando tiene empleo = sí | Operadores limitados y validados; sin scripts ni ciclos |
| Reglas de importación | `1` → sí, `0` → no | Guardadas en la configuración de esa fuente |
| Marcadores de ausencia | «Sin información» → sin respuesta | Aprobados por campo; no aplicar globalmente cadenas como NA o 0 |

Tipos de V1: booleano, número entero/decimal, categoría, categoría ordenada, selección múltiple, fecha y texto. Un identificador se conserva como texto. Las escalas y rangos actuales se migran como categorías con sus propiedades; no se promedian como si fueran medidas exactas.

La persistencia numérica debe conservar la precisión declarada. Definir en implementación límites de precisión/escala con PostgreSQL y serialización consistente; rechazar desbordamientos o exceso de decimales sin redondeo silencioso. El formato de almacenamiento de números debe quedar cerrado en el contrato antes de conectar gráficas. Los identificadores grandes o con ceros iniciales no se convierten a número.

Reglas de importación permitidas: recorte y normalización de texto configurados; equivalencias exactas; formato decimal/miles; formatos de fecha explícitos; conversiones de unidad soportadas; multiselección delimitada o columnas de opciones. No se ejecuta código aportado por el usuario ni fórmulas del libro.

Dos reglas no pueden asignar resultados diferentes al mismo valor bajo su política de comparación. Un marcador de ausencia no puede solaparse con una opción válida. Las fechas ambiguas como `03/04/2026` requieren elegir formato. Las conversiones usan una lista de unidades compatibles, no una fórmula libre.

Los encabezados pueden normalizarse para sugerir correspondencias; las respuestas no heredan automáticamente esa normalización. Por ejemplo, un código que distingue mayúsculas debe conservar esa distinción.

## 6. Estados de respuesta y decisiones pendientes

«Pendiente de configurar» es un estado del trabajo del administrador, no una respuesta del encuestado. No debe aparecer como categoría estadística.

| Estado de respuesta | Significado | Ejemplo |
|---|---|---|
| `respondida` | Valor válido según la definición | Sí, no, 0, false o una categoría admitida |
| `sin_respuesta` | Vacío o marcador de ausencia confirmado | Celda vacía o «Sin información» con regla aprobada |
| `no_aplica` | Condición declarada y comprobada de no aplicación | Sector laboral vacío cuando tiene empleo = no |
| `indeterminada` | No se puede determinar la interpretación o aplicabilidad | Falta la variable que decide si la pregunta aplica |
| `invalida` | Existe contenido que incumple reglas | «muchos» en un campo numérico |
| `no_disponible` | La fuente no incluye la variable | Pregunta no presente en un archivo histórico |

Por defecto, `Sin información` no se convierte a ausencia en todos los campos: puede ser una opción válida en una pregunta. Mientras no se confirme su interpretación se muestra una incidencia con el original.

Una condición de no aplicación no borra una respuesta existente. Si alguien declara que no trabaja y también informa sector laboral, se marca contradicción y se pide resolverla. Validar el conjunto completo de respuestas tras una corrección, no solo el campo editado. Esto amplía el comportamiento actual.

## 7. Conservación, validación y publicación

### Originales

Conservar archivo y celdas por coordenadas durante su retención; las transformaciones no sobrescriben la fuente. Registrar hash del archivo y conservar nombres de hojas, índice y filas. En valores restringidos aplicar los mismos controles a la copia original y a las vistas previas.

El XLSX debe conservar metadatos suficientes para interpretar fechas, encabezados combinados y tipos, sin inventar valores para celdas vacías. Un identificador numérico que Excel ya truncó no puede recuperarse: señalar la limitación al detectar un caso sospechoso.

### Reglas de cierre de una validación

- Cada columna seleccionada tiene destino explícito o exclusión. Se permiten varias columnas para una multiselección con regla completa; no para una variable escalar ambigua.
- La región elegida se usa tanto en la evaluación de calidad como en la validación. No se cuentan pies de tabla exteriores.
- Toda ejecución conserva versión de esquema, revisión de configuración, versión de normalización, región y reglas utilizadas.
- Las incidencias estructurales o reglas incompletas bloquean toda la incorporación.
- En una fila que se quiere incluir, una variable requerida inválida/ausente bloquea esa fila hasta corrección o exclusión explícita. Excluirla requiere motivo.
- Un valor inválido opcional puede conservarse como estado inválido sin contribuir a la métrica del campo. La revisión informa su cantidad y requiere aceptación explícita del resumen vigente al incorporar.
- No hay una opción «ignorar todos los errores» que convierta inválidos en respuestas válidas.
- Una región sin filas incorporables no se publica.

Reconciliación verificable por hoja:

`filas leídas = filas fuera de región + filas vacías dentro de región + filas candidatas`

`filas candidatas = incluidas + excluidas + pendientes de decisión`

Una fila ocupa una sola categoría. Las incidencias son conteos aparte: una fila puede tener varias. El commit requiere cero pendientes y cero bloqueos efectivos sobre las filas a publicar.

### Concurrencia e idempotencia

Las escrituras reciben una revisión esperada. Dos administradores no pueden sobrescribirse silenciosamente: el segundo recibe conflicto y debe actualizar su vista.

La incorporación verifica revisión de configuración, ejecución de validación vigente y versión publicada de esquema. Bloquea el estudio y aplica registros, historial y aumento de `data_version` en una transacción. Si falla, no queda una fracción incorporada. Las escrituras siguen un orden de bloqueo consistente: estudio, importación, respuestas.

Una clave idempotente identifica una petición de publicación. Repetir la misma petición devuelve el mismo resultado; reutilizar la clave con otro contenido devuelve conflicto. El hash del archivo detecta archivos idénticos, no personas repetidas.

Los originales pueden permanecer almacenados si falla una lectura o validación, con estado de error; eso no equivale a datos publicados. Las tareas de limpieza solo eliminan archivos autorizados por retención, nunca originales todavía referenciados por datos que deban conservarse.

### Correcciones y reprocesamiento

Corregir respuestas genera historial, revalida dependencias, incrementa revisión y cambia la versión de datos del estudio. Una corrección del valor no cambia la definición del esquema.

En V1 una importación ya incorporada no se reconfigura en el mismo registro. Para una nueva interpretación se realiza otra importación con sustitución explícita y alcance compatible; se excluyen lógicamente los registros anteriores y se conservan sus fuentes/historial. No relajar la unicidad de fila publicada para permitir duplicados invisibles.

`data_version` identifica el estado observado, pero por sí solo no habilita consultas históricas completas. Las exportaciones conservan sus parámetros y archivos durante su retención. No prometer reconstrucción arbitraria de cualquier dashboard pasado sin implementar instantáneas de datos.

## 8. Dashboard dinámico y precisión

El backend devuelve las operaciones permitidas de cada definición. React ofrece widgets compatibles y permite elegir variables/filtros. Una gráfica no decide el significado de los datos.

| Tipo | Operaciones de V1 | Presentación posible |
|---|---|---|
| Booleano/categoría | Distribución y cruces categóricos | Barras, tabla o indicador |
| Categoría ordenada | Distribución con orden explícito | Barras ordenadas |
| Número | Conteo válido, mínimo, máximo, media y desviación; intervalos explícitos para histograma | Indicadores, tabla, histograma |
| Fecha | Filtro por intervalo y conteo por día/mes/año declarado | Serie temporal de cantidad de registros |
| Multiselección | Frecuencia por opción y filtros de pertenencia | Barras; no circular que implique partes excluyentes |
| Texto | Conteos de calidad, búsqueda/listado paginado autorizado | Tabla; no resumen semántico ni gráficos de contactos |

Estas operaciones son objetivos: histogramas, series temporales y variables dinámicas todavía no están implementados. Las capacidades anunciadas se habilitan por entrega probada.

Reglas de cálculo:

- Solo registros incorporados e incluidos del estudio y versión seleccionados.
- Distribución: denominador de registros con respuesta válida en la variable, después de filtros.
- Cruce: denominador de pares válidos. Informar también exclusiones por ausencia/invalidez de cualquiera de las dos variables.
- Multiselección: cada registro cuenta una vez por opción; la suma de porcentajes puede superar 100 % y se informa expresamente.
- Números: no mezclar unidades ni monedas distintas; los rangos categóricos no generan medias exactas.
- Fechas: agrupar por calendario y granularidad declarados; el conteo temporal no implica tendencia causal ni tasa de población.
- Sin respuestas válidas: métrica y porcentaje nulos con mensaje de falta de datos; no mostrar cero como resultado inventado.
- La ausencia de un campo en un archivo no es cero, no, ni una categoría nueva.
- No llamar «personas únicas» al conteo de registros sin una política de identidad implementada.

Ejemplo de referencia: diez registros, seis sí, dos no y dos sin respuesta. Mostrar ocho respuestas válidas, 75 % sí sobre esas ocho y dos sin respuesta sobre los diez registros. La visualización debe hacer visible qué población usa cada porcentaje.

Cada resultado incluye como mínimo estudio, versión de esquema, `data_version`, reglas de cálculo, variables, filtros, cantidad de registros filtrados, denominador y estados de ausencia/calidad. Una petición de dashboard ejecuta sus widgets sobre una versión coherente; si una consulta posterior no puede satisfacer la versión esperada, devuelve conflicto y el frontend refresca el conjunto.

Las especificaciones guardadas referencian UUID de definición, no posiciones de Excel. Se validan nuevamente al abrirlas; no sustituir una variable inexistente por otra de nombre parecido. Una exportación usa las mismas funciones de cálculo y la versión esperada del dashboard.

## 9. Ampliación de API prevista

Estado del contrato: el registro de esquemas/versiones, edición paginada por lotes, publicación y asociaciones a estudios ya están disponibles; consultar `openapi.json` para el detalle ejecutable. Las ampliaciones de importación, análisis y dashboards de esta tabla siguen siendo diseño y no deben anunciarse como disponibles.

| Operación | Propuesta |
|---|---|
| Listar/crear esquemas | `GET/POST /api/v1/schemas` |
| Consultar/crear versión borrador | `GET/POST /api/v1/schemas/{schema}/versions` |
| Modificar variables del borrador | `PUT /api/v1/schema-versions/{version}/fields`, hasta 100 por lote y con revisión esperada; no elimina omitidas |
| Publicar versión | `POST /api/v1/schema-versions/{version}/publish` |
| Admitir una versión en un estudio | `POST /api/v1/datasets/{dataset}/schema-bindings` |
| Evaluar/configurar hoja | Extender `assessment` y configuración con `schema_version_id`, región y reglas |
| Validar/incorporar | Extender endpoints existentes con referencias y resumen de revisión vigente |
| Analizar | Extender analytics con versión exacta, definiciones UUID y capacidades por tipo |
| Guardar dashboard | `GET/POST /api/v1/datasets/{dataset}/dashboards` y actualización con revisión |
| Calcular dashboard | Endpoint que valida toda su especificación y calcula widgets en un estado coherente |

Compatibilidad: conservar entradas legacy de titulados/empleadores mediante un adaptador al catálogo migrado. Las llamadas dinámicas usan la referencia explícita de esquema. Si se envían referencias legacy y dinámicas contradictorias, responder 422. Nunca elegir un esquema por defecto silenciosamente cuando haya más de uno posible.

Errores: 422 para reglas/valores inválidos, 409 para revisión o estado desactualizado, 413 para límites, 403 para permisos. Devolver códigos estables y ubicaciones de incidencias; el frontend no debe analizar texto libre de errores para decidir qué hacer.

## 10. Migración sin pérdida de datos

1. Crear migraciones nuevas y aditivas; no reescribir la migración inicial para instalaciones existentes.
2. Sembrar los catálogos actuales como versiones explícitas e inmutables, incluidos catálogos legacy. Conservar códigos, opciones y reglas actuales en un adaptador de compatibilidad.
3. Añadir las referencias nuevas inicialmente opcionales y completar datos existentes en lotes, de manera idempotente.
4. Verificar conteos, estados, referencias, contactos y resultados estadísticos antes/después. Cualquier registro sin correspondencia detiene la activación; no se elimina ni se asigna a un campo aproximado.
5. Adaptar importación, captura, edición, filtros, análisis y exportación; activar solo tras pasar pruebas del recorrido completo.
6. Hacer obligatorias las restricciones para registros nuevos y conservar compatibilidad documentada para clientes actuales durante la transición.

Preparar respaldo de base y almacenamiento antes de una migración con datos reales y probar restauración. Una reversión no puede consistir en borrar tablas que ya contienen datos nuevos. Aplicar cambios y despliegue primero sobre una copia de prueba.

## 11. Implementación por incrementos

| Bloque | Entregable verificable | Dependencia |
|---|---|---|
| B1 | Modelos, migración aditiva, registro de esquemas, borradores/publicación y adaptador legacy; cerrar precisión numérica y contrato | Base actual |
| B2 | Mapeo a variables nuevas, conversiones, estados y validación por región; reglas requeridas/condicionales | B1 |
| B3 | Publicación y captura contra versiones; protección dinámica de campos sensibles; correcciones consistentes | B2 |
| B4 | Distribuciones/resúmenes/filtros dinámicos, calidad explícita, dashboard y exportación coherentes | B3 |
| B5 | XLS, encabezados compuestos y reutilización segura de perfiles; pruebas de formatos equivalentes | B2, B3 |
| B6 | Integración React completa, pruebas de volumen, despliegue y ensayo de defensa | B4, B5 |

Durante B1–B5 el frontend puede integrar el flujo actual y después las ampliaciones acordadas. No esperar a B6 para empezar las pantallas. IA queda fuera de todos estos bloques.

## 12. Criterios que impiden declarar terminada la V1 antes de tiempo

- Crear y analizar una pregunta nueva desde la interfaz, sin cambiar PHP ni JSON del repositorio.
- Dos archivos equivalentes de estructuras/codificaciones distintas producen los mismos conteos y métricas en estudios separados.
- Ninguna columna queda descartada silenciosamente: hay mapeo o exclusión explícita, con original disponible.
- Una configuración ambigua, una versión equivocada o una definición de otro esquema no puede publicarse.
- Una respuesta requerida inválida se corrige o se excluye con motivo; nunca cuenta como válida por confirmar el archivo.
- Reintentos y publicaciones concurrentes no duplican ni incorporan fracciones. Probar concurrencia con procesos independientes, no solo llamadas sucesivas.
- Cambiar etiqueta, reglas o unidades no altera datos históricos de manera implícita.
- Las rutas de preparación, respuesta, historial, filtro y exportación respetan campos sensibles dinámicos.
- Formularios e importaciones producen el mismo tipo de respuesta con origen distinguible.
- Dashboard y exportación coinciden en filtros, versión, denominador y resultado; una versión desactualizada produce conflicto controlado.
- Pruebas cubren ceros, false, vacíos, categorías desconocidas, números grandes, identificadores con ceros, fechas ambiguas, decimales regionales, multiselecciones, fórmulas sin caché, formatos corruptos y límites.
- Migración sobre copia de datos y restauración de respaldo comprobadas, con resultados comparables antes/después.
- La aplicación publicada para la defensa tiene acceso controlado y datos de demostración; su URL y modo de arranque están documentados.

Robustez significa preservar datos, detectar fallos y recuperarse de forma controlada dentro de un alcance probado. No se promete ausencia absoluta de fallos ni compatibilidad ilimitada.

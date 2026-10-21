# Demo de ACREDI en Render

`render.yaml` crea un servicio web gratuito y una base PostgreSQL gratuita, ambos nuevos e independientes de otros proyectos. La base gratuita de Render caduca a los 30 días. No usar esta configuración como archivo permanente de encuestas.

El servicio usa `Dockerfile.render`: compila una revisión fija del repositorio público del frontend, instala PHP-FPM, Nginx y el lector PDF, y sirve la web y `/api` desde el mismo dominio. El Dockerfile y Compose locales siguen separados. Para actualizar la web, cambiar `FRONTEND_REF` por el commit revisado y volver a desplegar.

## Configuración inicial

1. Crear un Blueprint desde este repositorio y elegir la rama que contenga `render.yaml`.
2. Comprobar que web y PostgreSQL muestran plan Free.
3. Introducir personalmente `BOOTSTRAP_ADMIN_EMAIL` y `BOOTSTRAP_ADMIN_PASSWORD` (mínimo 12 caracteres) en los campos privados de Render. No escribir estas credenciales en Git ni en el chat.
4. Render genera `APP_SECRET` y enlaza `DB_URL` con la base nueva. El script deriva una APP_KEY estable de ese secreto; no cambiarlo después del despliegue.
5. El arranque ejecuta migraciones, crea el primer administrador únicamente si no existe ninguno y arranca Nginx/PHP-FPM. La cuenta puede importar, pero no consultar contactos.
6. Tras verificar el acceso, eliminar las dos variables BOOTSTRAP del panel de Render. Los reinicios posteriores conservan la cuenta y su contraseña.

No se copian usuarios, contraseñas ni datos de la instalación local. El usuario carga las encuestas que desea presentar desde la web desplegada.

## Persistencia y recursos

- Sesiones, catálogo y respuestas incorporadas se guardan en PostgreSQL.
- Originales y exportaciones son temporales en el disco gratuito: desaparecen al reiniciar o desplegar. Descargar los archivos necesarios y conservar los originales fuera de Render.
- No se publica PostgreSQL a internet (`ipAllowList: []`).
- La aplicación duerme cuando está inactiva; abrir antes de la exposición.
- No hay despliegue automático en esta configuración. Publicar manualmente la revisión elegida.
- La URL pública solo muestra la pantalla de acceso y formularios con invitación válida; los resultados administrativos requieren autenticación.

## Comprobaciones

Pruebas de `BootstrapDemoTest`: creación única, contraseña cifrada mediante hash, permisos mínimos, ausencia de credenciales y conservación de cuentas existentes. La imagen debe verificarse con PostgreSQL desechable y datos sintéticos antes de desplegar; no ejecutar las pruebas de integración contra la base real.

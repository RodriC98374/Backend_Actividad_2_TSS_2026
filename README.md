# actividad_2_backend

Backend API en Laravel 13.

## Requisitos

- PHP 8.3+ (extensiones: openssl, curl, mbstring, fileinfo, pdo_sqlite, zip)
- Composer 2

## Instalación

```sh
composer setup
```

## Uso

```sh
php artisan serve     # servidor en http://localhost:8000
php artisan test      # pruebas
```

Las rutas se definen en `routes/api.php` y se sirven bajo el prefijo `/api`.
Chequeo de salud: `GET /up`.

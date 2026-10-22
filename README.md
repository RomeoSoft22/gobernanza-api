# Sistema de Control de Reuniones de Gobernanza

Control de las sesiones de la reunión de **Seguimiento Operativo** (RA 315-2026): sesiones por año,
filtros de búsqueda y gestión de acta, lista de asistencia, presentación y adjuntos.

| Capa      | Tecnología                         | Carpeta     |
|-----------|------------------------------------|-------------|
| Front-end | Angular 20 (standalone + signals)  | [gobernanza-web](https://github.com/RomeoSoft22/gobernanza-web) |
| Back-end  | Laravel 13 (API REST)              | este repositorio |
| BD        | MySQL 8.4 (Laragon)                | `gobernanza_reuniones` |

## Puesta en marcha (Laragon)

```bash
# 1. Base de datos (una sola vez)
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS gobernanza_reuniones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. API  (http://127.0.0.1:8000)
cd gobernanza-api
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed          # crea tablas + tipo de reunión "Seguimiento Operativo"
php artisan serve

# 3. Front-end  (http://localhost:4200)
cd gobernanza-web
npm install
npm start
```

> Use el PHP de Laragon (`C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`), que trae `pdo_mysql`.

Datos de demostración (72 sesiones): `php artisan db:seed --class=DemoSesionesSeeder`.
Para volver a empezar limpio: `php artisan migrate:fresh --seed`.

## Modelo de datos

- **tipos_reunion**: catálogo de reuniones (código, nombre, base legal, periodicidad). Permite agregar otras reuniones.
- **sesiones**: número correlativo por reunión (no se reinicia por año), fecha, año (derivado de la fecha),
  horario, modalidad, lugar, estado (`realizada`, `programada`, `reprogramada`, `suspendida`), agenda, acuerdos, observaciones.
- **sesion_documentos**: `acta`, `asistencia`, `presentacion` (uno vigente; subir otro lo reemplaza) y `adjunto` (varios).
  Archivos en `storage/app/private/sesiones/{año}/sesion-NNN/{tipo}/`.

## API

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/tipos-reunion` | Tipos de reunión activos |
| GET | `/api/sesiones` | Listado paginado con filtros: `anio, numero, mes, fecha_desde, fecha_hasta, estado, modalidad, documentacion (completa, incompleta, sin_acta, sin_asistencia, sin_presentacion, con_adjuntos), buscar, orden, direccion, page` |
| GET | `/api/sesiones/anios` | Años con sesiones (pestañas) |
| GET | `/api/sesiones/resumen?anio=` | Indicadores |
| GET | `/api/sesiones/siguiente-numero?tipo_reunion_id=` | Correlativo sugerido |
| POST/GET/PUT/DELETE | `/api/sesiones/{id}` | CRUD de sesiones |
| POST | `/api/sesiones/{id}/documentos` | Subir (`tipo`, `archivos[]`, `descripcion`) — máx. 50 MB c/u |
| GET | `/api/documentos/{id}/ver` · `/descargar` | Ver en el navegador / descargar |
| DELETE | `/api/documentos/{id}` | Eliminar documento |

## Agregar una opción al menú

1. Añadir el ítem en `gobernanza-web/src/app/core/menu.ts`.
2. Registrar la ruta en `gobernanza-web/src/app/app.routes.ts` (hoy "Documentos clave" apunta a una página "Próximamente").

## Pendiente recomendado

- **Autenticación** (Laravel Sanctum + login): hoy la API no exige usuario.
- Registro de quién subió cada documento (auditoría).

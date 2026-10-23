# gobernanza-api

API (Laravel 13 + MySQL) del **Sistema de Control de Reuniones de Gobernanza** (RA 315-2026):
reuniones de Seguimiento **Operativo** y **Táctico**, cada una con sesiones **ordinarias** y **extraordinarias**,
documentos (acta, asistencia, presentación, adjuntos) e integración con **Google Drive**.

El front-end está en [gobernanza-web](https://github.com/RomeoSoft22/gobernanza-web).

## Puesta en marcha (Laragon)

```bash
# Base de datos (una sola vez)
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS juarezc_gobernanza CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed      # tablas + reuniones Operativo y Táctico
php artisan serve               # http://127.0.0.1:8000
```

> Use el PHP de Laragon (`C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`), que carga `pdo_mysql`.

## Conectar Google Drive

El sistema accede a Drive con **OAuth 2.0 usando su propia cuenta**: solo ve lo que su cuenta ya puede ver.

1. Entre a <https://console.cloud.google.com/> con su cuenta institucional y cree un proyecto (p. ej. *Gobernanza*).
2. **APIs y servicios › Biblioteca** → habilite **Google Drive API**.
3. **APIs y servicios › Pantalla de consentimiento OAuth** → tipo **Interno** (solo usuarios de su organización),
   nombre de la app y correo de soporte.
4. **APIs y servicios › Credenciales › Crear credenciales › ID de cliente de OAuth**
   - Tipo: **Aplicación web**
   - URI de redireccionamiento autorizado: `http://127.0.0.1:8000/drive/callback`
5. Copie el *ID de cliente* y el *Secreto* en el `.env`:
   ```
   GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=xxxxxxxx
   ```
6. Ejecute `php artisan config:clear`, abra el sistema → **Configuración › Google Drive** → **Conectar con Google Drive**.
7. En la misma página pegue el enlace de la carpeta raíz de cada reunión y clase, por ejemplo
   `…/1. Equipo Operativo/2. Reuniones Ordinarias` (la que contiene `2025`, `2026`, …).

> Si "Interno" no está disponible o el administrador de Workspace restringe apps, solicite que autorice
> el ID de cliente en la consola de administración (Seguridad › Controles de API).

### Convención de carpetas en Drive

```
<Raíz elegida>/                       p. ej. 2. Reuniones Ordinarias
└── 2026/                             se crea con "Nuevo año"
    └── 2026.10.19 - 72va Reunión/    se crea al registrar la sesión
        ├── Acta 72.pdf               acta firmada
        ├── Acta 72.docx              versión Word
        ├── Lista de asistencia.pdf
        └── Presentación.pptx
```

**Drive es el único repositorio**: el sistema no guarda copias de los archivos, solo el enlace, el tipo asignado
y los metadatos (nombre, tamaño, fecha). Al abrir una sesión su carpeta se lee **en vivo**: los archivos nuevos
aparecen con el tipo sugerido por su nombre (*acta*, *asistencia/lista*, *presentación/.pptx*; el resto como
adjunto), los que se quitaron de la carpeta desaparecen y el tipo que usted asigne (o mueva de tarjeta) se conserva.
Quitar un documento desde el sistema lo envía a la **papelera de Drive** (recuperable 30 días).

### Importar el historial

Desde **Configuración › Google Drive › Importar sesiones y documentos**, o por consola (recomendado para muchas sesiones):

```bash
php artisan drive:importar operativo                 # ordinarias
php artisan drive:importar operativo extraordinaria
php artisan drive:importar tactico
```

Las carpetas de sesión deben empezar con `AAAA.MM.DD - NN`; las que no cumplan el formato se listan como omitidas.
Desde el panel la importación corre en segundo plano (el sistema sigue respondiendo) y puede repetirse sin duplicar.
Las extraordinarias reinician su numeración cada año.

Para liberar espacio de versiones anteriores que guardaban copias: `php artisan drive:limpiar-copias`.

## Ingreso con identidad (SSO)

Todo el sistema exige iniciar sesión en `identidad.juarezcampos.net` (OAuth 2.0 Authorization Code + PKCE),
igual que ISTQB y Firma. La API valida el JWT RS256 con el JWKS de identidad (`iss`, `aud=GOBERNANZA`, `exp`)
y exige uno de los roles de `IDENTIDAD_ROLES` (por defecto `ADMIN`). El refresh token queda en una cookie HttpOnly.

En el `.env`:

```
IDENTIDAD_CLIENT_ID=...        # client_id del sistema GOBERNANZA en identidad
IDENTIDAD_CLIENT_SECRET=...    # client_secret (se muestra una sola vez al registrarlo)
IDENTIDAD_REDIRECT_URI=http://localhost:4200/callback   # en producción: https://juarezcampos.net/gobernanza/callback
IDENTIDAD_POST_LOGOUT_URI=http://localhost:4200/
IDENTIDAD_AUDIENCE=GOBERNANZA
IDENTIDAD_ROLES=ADMIN
```

En local el front-end usa el proxy de `ng serve` (`/api` → `127.0.0.1:8000`), así la cookie funciona como en producción.
Para ingresar desde local, registre también `http://localhost:4200/callback` como redirección del cliente en identidad.

## Despliegue en cPanel

```bash
bash gobernanza-api/despliegue/empaquetar-cpanel.sh                  # primer despliegue (incluye .env)
ACTUALIZACION=1 bash gobernanza-api/despliegue/empaquetar-cpanel.sh  # actualizaciones (sin .env ni storage)
```

Genera `paquetes/cpanel-gobernanza-<fecha>/` junto a los repos (lleva secretos: nunca dentro de uno) con el `.zip`,
el `.sql` y la guía `LEEME.md` (ver `despliegue/LEEME-cpanel.md`):

- `public_html/gobernanza/` → front-end en `https://juarezcampos.net/gobernanza/`
- `public_html/gobernanza-api/` → solo la entrada pública de la API (`https://juarezcampos.net/gobernanza-api/api`)
- `/home/juarezc/gobernanza-api-app/` → código Laravel y `.env`, fuera de la carpeta pública

## Pruebas

```bash
php artisan test
```

## Modelo de datos

- **tipos_reunion**: Seguimiento Operativo (`operativo`), Seguimiento Táctico (`tactico`).
- **sesiones**: reunión, clase (`ordinaria` / `extraordinaria`), número correlativo por reunión y clase
  (las extraordinarias reinician cada año),
  fecha, año, horario, modalidad, lugar, estado, agenda, acuerdos, observaciones y carpeta de Drive.
- **sesion_documentos**: tipo asignado y enlace al archivo en Drive (`drive_file_id`, `drive_url`, tamaño, fecha).
  Varios archivos por tipo; se pueden mover de un tipo a otro.
- **drive_carpetas**: carpeta raíz y carpetas de año por reunión y clase.
- **drive_conexiones**: cuenta de Google conectada (tokens cifrados con `APP_KEY`).


## API

| Método | Ruta | Descripción |
|---|---|---|
| GET / POST | `/api/acceso/config` · `canjear` · `renovar` · `salir` | Ingreso con identidad (públicas) |
| GET | `/api/tipos-reunion` | Reuniones activas (esta y todas las siguientes exigen el JWT) |
| GET | `/api/sesiones` | Listado paginado. Filtros: `tipo_reunion_id, clase, anio, numero, mes, fecha_desde, fecha_hasta, estado, modalidad, documentacion, buscar, orden, direccion, page` |
| GET / POST | `/api/sesiones/anios` | Años disponibles / crear año (carpetas en Drive) |
| GET | `/api/sesiones/resumen` | Indicadores |
| GET | `/api/sesiones/siguiente-numero?tipo_reunion_id=&clase=` | Correlativo sugerido |
| CRUD | `/api/sesiones/{id}` | Sesiones (al crear, se crea su carpeta en Drive) |
| POST | `/api/sesiones/{id}/documentos` | Subir (`tipo`, `archivos[]`) directamente a Drive |
| GET | `/api/documentos/{id}/ver` · `/descargar` | Redirige a Drive (ver / descarga directa) |
| PATCH / DELETE | `/api/documentos/{id}` | Mover de tipo / enviar a la papelera de Drive |
| POST | `/api/sesiones/{id}/drive/sincronizar?crear=0\|1` | Lee en vivo la carpeta de la sesión |
| GET | `/api/drive/estado` · `/api/drive/autorizar` | Estado de la conexión / URL de consentimiento |
| PUT | `/api/drive/carpetas` | Asignar carpeta raíz (`tipo_reunion_id`, `clase`, `enlace`) |
| POST | `/api/drive/importar-historial` | Lanza la importación en segundo plano |
| GET | `/api/drive/importacion` | Avance de la importación |

## Pendiente recomendado

- Servir la API con Apache de Laragon (multihilo) en lugar de `php artisan serve`, que atiende una petición a la vez.

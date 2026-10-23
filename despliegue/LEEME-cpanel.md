# Despliegue en cPanel — Gobernanza (`https://juarezcampos.net/gobernanza`)

Usuario de cPanel: `juarezc` · Carpeta personal: `/home/juarezc/`

| Dónde | Qué | URL |
| --- | --- | --- |
| `public_html/gobernanza/` | Pantallas (Angular) | `https://juarezcampos.net/gobernanza/` |
| `public_html/gobernanza-api/` | Solo la **entrada** de la API (`index.php` + `.htaccess`) | `https://juarezcampos.net/gobernanza-api/api/…` |
| `/home/juarezc/gobernanza-api-app/` | Código Laravel, `vendor/` y **`.env`** (privado, fuera de la web) | — |

> El código y el `.env` quedan **fuera** de `public_html` (como `istqb-api-app`): así nadie puede descargar
> el `.env`, los logs ni el código desde el navegador.

Sigue los pasos **en orden**. En el Administrador de archivos activa **Configuración → Mostrar archivos ocultos**.

---

## 1. Registrar Gobernanza en identidad (una sola vez)

En el **panel** (`https://panel.juarezcampos.net`) → Sistemas, o por consola en el servidor de identidad:

```bash
php artisan identidad:sistema GOBERNANZA "Gobernanza" --url=https://juarezcampos.net/gobernanza --redirect=https://juarezcampos.net/gobernanza/callback
php artisan identidad:rol GOBERNANZA ADMIN Administrador
php artisan identidad:matricular tu-correo@… GOBERNANZA ADMIN
```

- [ ] Anota el **client_id** y el **client_secret** (el secreto se muestra una sola vez).
- [ ] El **código** del sistema debe ser `GOBERNANZA` (o cambia `IDENTIDAD_AUDIENCE` en el `.env`).
- [ ] URI de redirección registrada: `https://juarezcampos.net/gobernanza/callback`.
- [ ] Tu usuario matriculado en GOBERNANZA con rol `ADMIN`.

## 2. Google Cloud (una sola vez)

En <https://console.cloud.google.com/> → APIs y servicios → Credenciales → tu cliente OAuth de Gobernanza:

- [ ] En **URI de redireccionamiento autorizados** agrega: `https://juarezcampos.net/gobernanza-api/drive/callback`
  (deja también el de `http://127.0.0.1:8000/drive/callback` para trabajar en local).

## 3. PHP

- [ ] **MultiPHP Manager**: `juarezcampos.net` en **PHP 8.3** (si ya lo usa ISTQB, no cambies nada).
- [ ] **Seleccionar versión de PHP → Extensiones**: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`.
- [ ] **Opciones**: `upload_max_filesize = 64M`, `post_max_size = 64M`, `max_execution_time = 300`
  (documentos de hasta 50 MB y la importación del historial).

## 4. Base de datos `juarezc_gobernanza`

- [ ] **Bases de datos MySQL**: crea la base `juarezc_gobernanza`.
- [ ] Crea un usuario (por ejemplo `juarezc_gobernanza`) con contraseña larga y **agrégalo a la base con TODOS LOS PRIVILEGIOS**.
- [ ] **phpMyAdmin** → `juarezc_gobernanza` → **Importar** → `sql/juarezc_gobernanza.sql` → Continuar.
  Trae la estructura y tus sesiones/documentos actuales (sin la conexión de Google, que se hace en el paso 7).

## 5. Subir los archivos

- [ ] **Administrador de archivos** → `/home/juarezc/` (no a `public_html`).
- [ ] **Cargar** `gobernanza-juarezcampos.net.zip` → clic derecho → **Extraer** en `/home/juarezc/`.
- [ ] Quedan: `public_html/gobernanza/`, `public_html/gobernanza-api/` y `gobernanza-api-app/`.
- [ ] Borra el `.zip` del servidor.
- [ ] Permisos `755` en `gobernanza-api-app/storage` y `gobernanza-api-app/bootstrap/cache` (si el log dice *Permission denied*).

## 6. Completar `/home/juarezc/gobernanza-api-app/.env`

Cambia **solo** estas líneas:

| Línea | Valor |
| --- | --- |
| `DB_USERNAME=` | el usuario MySQL del paso 4 (ej. `juarezc_gobernanza`) |
| `DB_PASSWORD=` | su contraseña |
| `IDENTIDAD_CLIENT_ID=` | el **client_id** del paso 1 (reemplaza `PEGAR_CLIENT_ID`) |
| `IDENTIDAD_CLIENT_SECRET=` | el **client_secret** del paso 1 (reemplaza `PEGAR_CLIENT_SECRET`) |

No toques `APP_KEY`. `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET` ya vienen llenos.

Si después cambias el `.env`, vacía `gobernanza-api-app/bootstrap/cache/` (borra todo menos `.gitignore`).

## 7. Comprobar y conectar Drive

| Abre | Debes ver |
| --- | --- |
| `https://juarezcampos.net/gobernanza-api/api/estado` | `{"estado":"ok"}` |
| `https://juarezcampos.net/gobernanza-api/api/acceso/config` | JSON con `authorize_endpoint` y tu `client_id` |
| `https://juarezcampos.net/gobernanza-api/api/sesiones` | `{"codigo":"no_autenticado",…}` (protegido) |
| `https://juarezcampos.net/gobernanza-api/.env` | Error 403 o 404 |
| `https://juarezcampos.net/gobernanza/` | Te lleva a identidad y vuelve al inicio de Gobernanza |

- [ ] En Gobernanza → **Configuración › Google Drive** → **Conectar con Google Drive** (una vez).
  Las carpetas de cada reunión ya vienen asignadas desde la base importada.

## 8. Actualizaciones posteriores

Genera el paquete con `ACTUALIZACION=1 bash gobernanza-api/despliegue/empaquetar-cpanel.sh`: no trae `.env`
ni `storage/`, así que tu configuración y logs se conservan. Extráelo en `/home/juarezc/` sobrescribiendo,
vacía `gobernanza-api-app/bootstrap/cache/` y, si hay migraciones nuevas, ejecútalas desde **Terminal** de cPanel:

```bash
cd ~/gobernanza-api-app && php artisan migrate --force
```

## Si algo falla

Revisa `/home/juarezc/gobernanza-api-app/storage/logs/laravel.log`.

| Síntoma | Causa habitual |
| --- | --- |
| La API responde «El servicio no está disponible» | `gobernanza-api-app/` no está en `/home/juarezc/` o le falta `vendor/` |
| `Access denied for user` en el log | `DB_USERNAME` / `DB_PASSWORD` o el usuario no está agregado a la base |
| Identidad dice que el cliente o la redirección no son válidos | `IDENTIDAD_CLIENT_ID` o la URI `https://juarezcampos.net/gobernanza/callback` no registrada |
| «Tu cuenta no tiene permiso…» | Tu usuario no está matriculado en GOBERNANZA con rol `ADMIN` |
| Google: `redirect_uri_mismatch` | Falta la URI del paso 2 en Google Cloud |
| Al recargar una página sale 404 | Falta `public_html/gobernanza/.htaccess` (archivo oculto) |

#!/usr/bin/env bash
# Genera el paquete de despliegue en cPanel del sistema de gobernanza:
#
#   https://juarezcampos.net/gobernanza       gobernanza-web (Angular)        → public_html/gobernanza/
#   https://juarezcampos.net/gobernanza-api   entrada pública de la API       → public_html/gobernanza-api/
#                                             código Laravel + .env (privado) → /home/juarezc/gobernanza-api-app/
#
# Uso (Git Bash, desde la carpeta que contiene gobernanza-api y gobernanza-web):
#   bash gobernanza-api/despliegue/empaquetar-cpanel.sh                 # primer despliegue (incluye .env)
#   ACTUALIZACION=1 bash gobernanza-api/despliegue/empaquetar-cpanel.sh # actualización (sin .env ni storage)
#
# El resultado queda en paquetes/cpanel-gobernanza-<fecha>/ JUNTO a los repos (nunca dentro de uno:
# el .env lleva secretos).
set -euo pipefail

BASE="$(cd "$(dirname "$0")/../.." && pwd)"
API="$BASE/gobernanza-api"
WEB="$BASE/gobernanza-web"
FECHA="$(date +%Y%m%d-%H%M)"
SALIDA="$BASE/paquetes/cpanel-gobernanza-$FECHA"
C="$SALIDA/contenido"

CPANEL_USUARIO="${CPANEL_USUARIO:-juarezc}"
DOMINIO="${DOMINIO:-https://juarezcampos.net}"
URL_WEB="$DOMINIO/gobernanza"
URL_API="$DOMINIO/gobernanza-api"
ACTUALIZACION="${ACTUALIZACION:-0}"
PHP="${PHP:-/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe}"
MYSQL_BIN="${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin}"
BD_LOCAL="${BD_LOCAL:-juarezc_gobernanza}"
COMPOSER_CMD="${COMPOSER_CMD:-$(command -v composer || echo composer.bat)}"
TAR="${TAR:-/c/Windows/System32/tar.exe}"
export NG_CLI_ANALYTICS=false

paso() { printf '\n\033[36m== %s\033[0m\n' "$*"; }
valor_env() { grep -E "^$1=" "$API/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true; }
# tar.exe (bsdtar de Windows) genera .zip con rutas "/", compatibles con el extractor de cPanel.
zip_de() { local zip; zip="$(cygpath -w "$1" 2>/dev/null || echo "$1")"; shift; (cd "$C" && "$TAR" -a -c -f "$zip" "$@"); }

mkdir -p "$C" "$SALIDA/sql"

# ------------------------------------------------------------------ 1. Front-end
paso "1/5 gobernanza-web → public_html/gobernanza ($URL_WEB)"
(cd "$WEB" && { [ -d node_modules ] || npm ci --no-audit --no-fund; } \
  && MSYS_NO_PATHCONV=1 node node_modules/@angular/cli/bin/ng.js build --configuration production --base-href /gobernanza/ >/dev/null)
mkdir -p "$C/public_html/gobernanza"
cp -r "$WEB/dist/gobernanza-web/browser/." "$C/public_html/gobernanza/"
cp "$API/despliegue/cpanel/web.htaccess" "$C/public_html/gobernanza/.htaccess"

# ------------------------------------------------------------------ 2. API: entrada pública
paso "2/5 Entrada pública de la API → public_html/gobernanza-api ($URL_API)"
mkdir -p "$C/public_html/gobernanza-api"
cp "$API/despliegue/cpanel/index.php" "$C/public_html/gobernanza-api/index.php"
cp "$API/despliegue/cpanel/api-publico.htaccess" "$C/public_html/gobernanza-api/.htaccess"
cp "$API/public/favicon.ico" "$API/public/robots.txt" "$C/public_html/gobernanza-api/" 2>/dev/null || true

# ------------------------------------------------------------------ 3. API: código (privado)
paso "3/5 Código de la API → /home/$CPANEL_USUARIO/gobernanza-api-app"
D="$C/gobernanza-api-app"
mkdir -p "$D"
(cd "$API" && git ls-files -z --cached --others --exclude-standard \
  | grep -zv -E '^(tests/|public/|despliegue/|\.github/|phpunit\.xml|\.editorconfig|\.gitattributes|README\.md|AGENTS\.md|CLAUDE\.md)' \
  | xargs -0 -I{} cp --parents "{}" "$D/")
find "$D/storage" -type f ! -name .gitignore -delete 2>/dev/null || true
find "$D/bootstrap/cache" -type f ! -name .gitignore -delete 2>/dev/null || true
cp "$API/despliegue/cpanel/app.htaccess" "$D/.htaccess"
(cd "$D" && $COMPOSER_CMD install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts >/dev/null)

if [ "$ACTUALIZACION" = "1" ]; then
  # En una actualización se conservan el .env y storage/ del servidor (logs, sesiones, caché).
  rm -rf "$D/storage"
else
  paso "    .env de producción"
  # Las llaves de identidad y de Google se toman del .env local (si están llenas).
  IDENTIDAD_ID="$(valor_env IDENTIDAD_CLIENT_ID)"
  IDENTIDAD_SECRETO="$(valor_env IDENTIDAD_CLIENT_SECRET)"
  APP_KEY_NUEVA="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  sed -e "s|^APP_NAME=.*|APP_NAME=\"Gobernanza - Reuniones\"|" \
      -e "s|^APP_ENV=.*|APP_ENV=production|" \
      -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY_NUEVA|" \
      -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
      -e "s|^APP_URL=.*|APP_URL=$URL_API|" \
      -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
      -e "s|^DB_DATABASE=.*|DB_DATABASE=${CPANEL_USUARIO}_gobernanza|" \
      -e "s|^DB_USERNAME=.*|DB_USERNAME=${CPANEL_USUARIO}_CAMBIAR|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=CAMBIAR|" \
      -e "s|^SESSION_DRIVER=.*|SESSION_DRIVER=file|" \
      -e "s|^GOOGLE_CLIENT_ID=.*|GOOGLE_CLIENT_ID=$(valor_env GOOGLE_CLIENT_ID)|" \
      -e "s|^GOOGLE_CLIENT_SECRET=.*|GOOGLE_CLIENT_SECRET=$(valor_env GOOGLE_CLIENT_SECRET)|" \
      -e "s|^GOOGLE_REDIRECT_URI=.*|GOOGLE_REDIRECT_URI=$URL_API/drive/callback|" \
      -e "s|^FRONTEND_URL=.*|FRONTEND_URL=$URL_WEB|" \
      -e "s|^IDENTIDAD_CLIENT_ID=.*|IDENTIDAD_CLIENT_ID=${IDENTIDAD_ID:-PEGAR_CLIENT_ID}|" \
      -e "s|^IDENTIDAD_CLIENT_SECRET=.*|IDENTIDAD_CLIENT_SECRET=${IDENTIDAD_SECRETO:-PEGAR_CLIENT_SECRET}|" \
      -e "s|^IDENTIDAD_REDIRECT_URI=.*|IDENTIDAD_REDIRECT_URI=$URL_WEB/callback|" \
      -e "s|^IDENTIDAD_POST_LOGOUT_URI=.*|IDENTIDAD_POST_LOGOUT_URI=$URL_WEB/|" \
      "$API/.env.example" > "$D/.env"
  printf '\n# HTTPS en producción\nSESSION_SECURE_COOKIE=true\n' >> "$D/.env"
fi

# ------------------------------------------------------------------ 4. Base de datos
paso "4/5 Base de datos (${CPANEL_USUARIO}_gobernanza)"
# Datos actuales (sesiones, documentos vinculados, carpetas de Drive). Se omiten la conexión de Google
# (tokens del equipo local: en el servidor se vuelve a conectar) y las tablas temporales.
# La auditoría empieza vacía en producción (sin las pruebas hechas en local).
OMITIR_DATOS=(drive_conexiones auditoria cache cache_locks sessions jobs job_batches failed_jobs password_reset_tokens)
IGNORAR=(); for t in "${OMITIR_DATOS[@]}"; do IGNORAR+=("--ignore-table=$BD_LOCAL.$t"); done
DUMP_OPC=(-uroot --host=127.0.0.1 --default-character-set=utf8mb4 --no-tablespaces --set-gtid-purged=OFF --skip-comments)
{
  echo "-- Sistema de gobernanza: estructura y datos actuales. Importar en ${CPANEL_USUARIO}_gobernanza (phpMyAdmin)."
  echo "SET FOREIGN_KEY_CHECKS=0;"
  "$MYSQL_BIN/mysqldump" "${DUMP_OPC[@]}" "${IGNORAR[@]}" "$BD_LOCAL"
  "$MYSQL_BIN/mysqldump" "${DUMP_OPC[@]}" --no-data "$BD_LOCAL" "${OMITIR_DATOS[@]}"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} | sed -E 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' > "$SALIDA/sql/${CPANEL_USUARIO}_gobernanza.sql"

# ------------------------------------------------------------------ 5. Comprimir
paso '5/5 Comprimiendo'
zip_de "$SALIDA/gobernanza-juarezcampos.net.zip" public_html gobernanza-api-app
cp "$API/despliegue/LEEME-cpanel.md" "$SALIDA/LEEME.md"
rm -rf "$C"

printf '\n\033[32mPaquete listo:\033[0m %s\n' "$SALIDA"
ls -la "$SALIDA" "$SALIDA/sql"

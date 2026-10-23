<?php

namespace App\Services;

use App\Models\DriveConexion;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use App\Exceptions\DriveException;

/**
 * Cliente mínimo de Google Drive API v3 usando OAuth 2.0 con la cuenta del usuario.
 * Soporta "Mi unidad", carpetas compartidas y unidades compartidas.
 */
class GoogleDrive
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    /** Acceso completo a Drive: necesario para leer carpetas que no creó el sistema. */
    private const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/drive'];

    public const MIME_CARPETA = 'application/vnd.google-apps.folder';

    private const CAMPOS = 'id,name,mimeType,webViewLink,modifiedTime,size,trashed,lastModifyingUser(displayName,emailAddress)';

    /** Formato de descarga de los documentos nativos de Google (Docs, Sheets, Slides). */
    private const EXPORTAR = [
        'application/vnd.google-apps.document' => ['document', 'docx'],
        'application/vnd.google-apps.spreadsheet' => ['spreadsheets', 'xlsx'],
        'application/vnd.google-apps.presentation' => ['presentation', 'pptx'],
    ];

    // ---------------------------------------------------------------- OAuth

    public function configurado(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function conectado(): bool
    {
        return DriveConexion::actual() !== null;
    }

    public function urlAutorizacion(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function conectarConCodigo(string $code): DriveConexion
    {
        $token = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        if (empty($token['refresh_token'])) {
            throw new DriveException('Google no devolvió un refresh token. Revoque el acceso en su cuenta y vuelva a conectar.');
        }

        $email = Http::withToken($token['access_token'])->get(self::USERINFO_URL)->throw()->json('email');

        DriveConexion::query()->delete();

        return DriveConexion::create([
            'email' => $email,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expira_at' => now()->addSeconds($token['expires_in'] ?? 3600),
        ]);
    }

    public function desconectar(): void
    {
        if ($conexion = DriveConexion::actual()) {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $conexion->refresh_token]);
        }
        DriveConexion::query()->delete();
    }

    private function token(): string
    {
        $conexion = DriveConexion::actual() ?? throw new DriveException('Google Drive no está conectado.');

        if ($conexion->expira_at && $conexion->expira_at->isAfter(now()->addMinute())) {
            return $conexion->access_token;
        }

        $token = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $conexion->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw()->json();

        $conexion->update([
            'access_token' => $token['access_token'],
            'expira_at' => now()->addSeconds($token['expires_in'] ?? 3600),
        ]);

        return $token['access_token'];
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(120)->throw();
    }

    // ---------------------------------------------------------------- Archivos

    /** Acepta un enlace de Drive (…/folders/ID, …/file/d/ID, ?id=ID) o el ID directo. */
    public static function idDesdeEnlace(string $valor): string
    {
        $valor = trim($valor);
        foreach (['~/folders/([\w-]+)~', '~/d/([\w-]+)~', '~[?&]id=([\w-]+)~'] as $patron) {
            if (preg_match($patron, $valor, $m)) {
                return $m[1];
            }
        }

        return $valor;
    }

    public function obtener(string $id): array
    {
        return $this->api()->get(self::API."/files/{$id}", [
            'fields' => self::CAMPOS,
            'supportsAllDrives' => 'true',
        ])->json();
    }

    /** Lista el contenido (no eliminado) de una carpeta, recorriendo todas las páginas. */
    public function listar(string $carpetaId, ?bool $soloCarpetas = null): array
    {
        $q = "'{$carpetaId}' in parents and trashed = false";
        if ($soloCarpetas === true) {
            $q .= " and mimeType = '".self::MIME_CARPETA."'";
        } elseif ($soloCarpetas === false) {
            $q .= " and mimeType != '".self::MIME_CARPETA."'";
        }

        $items = [];
        $pagina = null;
        do {
            $r = $this->api()->get(self::API.'/files', array_filter([
                'q' => $q,
                'fields' => 'nextPageToken,files('.self::CAMPOS.')',
                'pageSize' => 1000,
                'orderBy' => 'name',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'pageToken' => $pagina,
            ]))->json();
            array_push($items, ...($r['files'] ?? []));
            $pagina = $r['nextPageToken'] ?? null;
        } while ($pagina);

        return $items;
    }

    public function crearCarpeta(string $nombre, string $padreId): array
    {
        return $this->api()->post(self::API.'/files?'.http_build_query([
            'fields' => self::CAMPOS,
            'supportsAllDrives' => 'true',
        ]), [
            'name' => $nombre,
            'mimeType' => self::MIME_CARPETA,
            'parents' => [$padreId],
        ])->json();
    }

    /** Devuelve la subcarpeta con ese nombre exacto o la crea si no existe. */
    public function carpetaHija(string $nombre, string $padreId): array
    {
        foreach ($this->listar($padreId, true) as $c) {
            if (trim($c['name']) === $nombre) {
                return $c;
            }
        }

        return $this->crearCarpeta($nombre, $padreId);
    }

    /** Sube un archivo local a una carpeta (subida reanudable, apta para archivos grandes). */
    public function subir(string $rutaLocal, string $nombre, ?string $mime, string $carpetaId): array
    {
        $sesion = $this->api()->post(self::UPLOAD.'?'.http_build_query([
            'uploadType' => 'resumable',
            'supportsAllDrives' => 'true',
            'fields' => self::CAMPOS,
        ]), ['name' => $nombre, 'parents' => [$carpetaId]]);

        $destino = $sesion->header('Location');

        return Http::withToken($this->token())->timeout(600)->throw()
            ->withBody(Utils::streamFor(fopen($rutaLocal, 'r')), $mime ?: 'application/octet-stream')
            ->put($destino)
            ->json();
    }

    /** Envía el archivo a la papelera de Drive (recuperable durante 30 días). */
    public function enviarAPapelera(string $id): void
    {
        $this->api()->patch(self::API."/files/{$id}?supportsAllDrives=true", ['trashed' => true]);
    }

    /** Enlace de descarga directa desde Drive (los documentos de Google se exportan a Office). */
    public static function urlDescarga(string $id, ?string $mime): string
    {
        if ($mime && isset(self::EXPORTAR[$mime])) {
            [$app, $formato] = self::EXPORTAR[$mime];

            return "https://docs.google.com/{$app}/d/{$id}/export?format={$formato}";
        }

        return "https://drive.google.com/uc?export=download&id={$id}";
    }
}

<?php

namespace App\Services;

use App\Exceptions\DriveException;
use App\Models\DriveCarpeta;
use App\Models\Sesion;
use App\Models\SesionDocumento;
use App\Models\TipoReunion;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

/**
 * Reglas de negocio entre el sistema y Google Drive:
 *   Raíz (elegida por el usuario) / AAAA / AAAA.MM.DD - NNva Reunión / archivos
 *
 * Drive es el único repositorio de archivos: el sistema solo guarda el enlace,
 * el tipo asignado (acta, asistencia…) y los metadatos para listar y filtrar.
 */
class DriveSincronizador
{
    public function __construct(private GoogleDrive $drive) {}

    public function disponible(): bool
    {
        return $this->drive->configurado() && $this->drive->conectado();
    }

    // ---------------------------------------------------------------- Carpetas

    /** Registra la carpeta raíz de una reunión/clase a partir de un enlace de Drive. */
    public function definirRaiz(TipoReunion $tipo, string $clase, string $enlace): DriveCarpeta
    {
        $carpeta = $this->drive->obtener(GoogleDrive::idDesdeEnlace($enlace));
        if ($carpeta['mimeType'] !== GoogleDrive::MIME_CARPETA) {
            throw new DriveException('El enlace no corresponde a una carpeta de Drive.');
        }

        // Las carpetas de año dependen de la raíz: se vuelven a resolver.
        DriveCarpeta::where(['tipo_reunion_id' => $tipo->id, 'clase' => $clase])->whereNotNull('anio')->delete();

        return DriveCarpeta::updateOrCreate(
            ['tipo_reunion_id' => $tipo->id, 'clase' => $clase, 'anio' => null],
            ['folder_id' => $carpeta['id'], 'nombre' => $carpeta['name'], 'url' => $carpeta['webViewLink']],
        );
    }

    public function carpetaAnio(int $tipoId, string $clase, int $anio): DriveCarpeta
    {
        if ($existente = DriveCarpeta::delAnio($tipoId, $clase, $anio)) {
            return $existente;
        }

        $raiz = DriveCarpeta::raiz($tipoId, $clase)
            ?? throw new DriveException("No se ha configurado la carpeta de Drive para las reuniones {$clase}s.");

        $c = $this->drive->carpetaHija((string) $anio, $raiz->folder_id);

        return DriveCarpeta::create([
            'tipo_reunion_id' => $tipoId, 'clase' => $clase, 'anio' => $anio,
            'folder_id' => $c['id'], 'nombre' => $c['name'], 'url' => $c['webViewLink'],
        ]);
    }

    /** Crea (o reutiliza) la carpeta del año para las reuniones ordinarias y extraordinarias. */
    public function crearAnio(TipoReunion $tipo, int $anio): array
    {
        $resultado = [];
        foreach (Sesion::CLASES as $clase) {
            if (DriveCarpeta::raiz($tipo->id, $clase)) {
                $resultado[$clase] = $this->carpetaAnio($tipo->id, $clase, $anio);
            }
        }

        if (! $resultado) {
            throw new DriveException('Configure primero las carpetas de Drive de esta reunión.');
        }

        return $resultado;
    }

    /** Devuelve la carpeta de la sesión; si no existe la busca por número dentro del año o la crea. */
    public function carpetaSesion(Sesion $sesion): string
    {
        if ($sesion->drive_folder_id) {
            return $sesion->drive_folder_id;
        }

        $anio = $this->carpetaAnio($sesion->tipo_reunion_id, $sesion->clase, $sesion->anio);

        $carpeta = collect($this->drive->listar($anio->folder_id, true))
            ->first(fn ($c) => preg_match(Sesion::PATRON_CARPETA, $c['name'], $m) && (int) $m[4] === $sesion->numero)
            ?? $this->drive->crearCarpeta($sesion->nombreCarpetaDrive(), $anio->folder_id);

        $sesion->update(['drive_folder_id' => $carpeta['id'], 'drive_folder_url' => $carpeta['webViewLink']]);

        return $carpeta['id'];
    }

    // ---------------------------------------------------------------- Documentos

    /** Sube el archivo directamente a la carpeta de la sesión en Drive (sin copia en el servidor). */
    public function subirDocumento(Sesion $sesion, UploadedFile $archivo, string $tipo, ?string $descripcion): SesionDocumento
    {
        if (! $this->disponible()) {
            throw new DriveException('Conecte Google Drive para subir documentos (Configuración › Google Drive).');
        }

        $subido = $this->drive->subir(
            $archivo->getRealPath(),
            $archivo->getClientOriginalName(),
            $archivo->getMimeType(),
            $this->carpetaSesion($sesion),
        );

        return $this->vincular($sesion, $subido, $tipo, $descripcion);
    }

    /**
     * Lista en vivo la carpeta de la sesión en Drive y deja la sesión igual a ella:
     *  - archivos nuevos en Drive se agregan con el tipo sugerido por su nombre,
     *  - los ya vinculados conservan el tipo asignado (aunque se hayan movido de tarjeta),
     *  - los que ya no están en la carpeta se desvinculan.
     * Con $crearCarpeta = false no crea nada en Drive si la sesión aún no tiene carpeta.
     */
    public function sincronizarSesion(Sesion $sesion, bool $crearCarpeta = true): array
    {
        if (! $sesion->drive_folder_id && ! $crearCarpeta) {
            return ['agregados' => 0, 'desvinculados' => 0, 'total' => 0];
        }

        $archivos = $this->archivosDe($this->carpetaSesion($sesion));
        $vinculados = $sesion->documentos()->whereNotNull('drive_file_id')->pluck('tipo', 'drive_file_id');

        $agregados = 0;
        foreach ($archivos as $archivo) {
            $existe = isset($vinculados[$archivo['id']]);
            $this->vincular($sesion, $archivo, $vinculados[$archivo['id']] ?? SesionDocumento::clasificar($archivo['name']));
            $agregados += $existe ? 0 : 1;
        }

        $desvinculados = 0;
        $sesion->documentos()
            ->whereNotNull('drive_file_id')
            ->whereNotIn('drive_file_id', array_column($archivos, 'id'))
            ->get()
            ->each(function (SesionDocumento $doc) use (&$desvinculados) {
                $doc->delete();
                $desvinculados++;
            });

        $sesion->update(['drive_sincronizado_at' => now()]);

        return ['agregados' => $agregados, 'desvinculados' => $desvinculados, 'total' => count($archivos)];
    }

    /** Guarda o actualiza el enlace al archivo de Drive con sus metadatos (no descarga nada). */
    private function vincular(Sesion $sesion, array $archivo, string $tipo, ?string $descripcion = null): SesionDocumento
    {
        $doc = $sesion->documentos()->firstOrNew(['drive_file_id' => $archivo['id']]);

        $doc->fill([
            'tipo' => $tipo,
            'nombre_original' => $archivo['name'],
            'mime' => $archivo['mimeType'] ?? null,
            'tamano' => (int) ($archivo['size'] ?? 0),
            'drive_url' => $archivo['webViewLink'] ?? null,
            'drive_modificado_at' => isset($archivo['modifiedTime']) ? Carbon::parse($archivo['modifiedTime']) : null,
        ]);
        if ($descripcion !== null) {
            $doc->descripcion = $descripcion;
        }
        $doc->save();

        return $doc;
    }

    // ---------------------------------------------------------------- Historial

    /**
     * Recorre Raíz / AAAA / "AAAA.MM.DD - NNva Reunión" y crea o actualiza las sesiones
     * con sus documentos (clasificación automática por nombre).
     */
    public function importarHistorial(TipoReunion $tipo, string $clase, ?callable $log = null): array
    {
        $log ??= fn () => null;
        $raiz = DriveCarpeta::raiz($tipo->id, $clase)
            ?? throw new DriveException("No se ha configurado la carpeta de Drive para las reuniones {$clase}s.");

        $stats = ['anios' => 0, 'sesiones' => 0, 'documentos' => 0, 'desvinculados' => 0, 'omitidas' => []];

        foreach ($this->drive->listar($raiz->folder_id, true) as $carpetaAnio) {
            if (! preg_match('/^\d{4}$/', trim($carpetaAnio['name']))) {
                continue;
            }
            $anio = (int) trim($carpetaAnio['name']);
            DriveCarpeta::updateOrCreate(
                ['tipo_reunion_id' => $tipo->id, 'clase' => $clase, 'anio' => $anio],
                ['folder_id' => $carpetaAnio['id'], 'nombre' => $carpetaAnio['name'], 'url' => $carpetaAnio['webViewLink']],
            );
            $stats['anios']++;
            $log("Año {$anio}");

            foreach ($this->drive->listar($carpetaAnio['id'], true) as $carpeta) {
                if (! preg_match(Sesion::PATRON_CARPETA, $carpeta['name'], $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $stats['omitidas'][] = "{$anio}/{$carpeta['name']}";
                    continue;
                }

                $sesion = Sesion::withTrashed()->updateOrCreate(
                    ['tipo_reunion_id' => $tipo->id, 'clase' => $clase, 'anio' => (int) $m[1], 'numero' => (int) $m[4]],
                    [
                        'fecha' => "{$m[1]}-{$m[2]}-{$m[3]}",
                        'drive_folder_id' => $carpeta['id'],
                        'drive_folder_url' => $carpeta['webViewLink'],
                    ],
                );
                if ($sesion->trashed()) {
                    $sesion->restore();
                }
                if ($sesion->wasRecentlyCreated) {
                    $sesion->update(['estado' => 'realizada', 'modalidad' => 'presencial']);
                }
                $stats['sesiones']++;

                $r = $this->sincronizarSesion($sesion);
                $stats['documentos'] += $r['total'];
                $stats['desvinculados'] += $r['desvinculados'];
                $log("  Sesión N° {$sesion->numero} ({$sesion->fecha->format('d/m/Y')})");
            }
        }

        return $stats;
    }

    // ---------------------------------------------------------------- Utilidades

    /** Archivos de la carpeta (incluye un nivel de subcarpetas). */
    private function archivosDe(string $carpetaId): array
    {
        $archivos = $this->drive->listar($carpetaId, false);

        foreach ($this->drive->listar($carpetaId, true) as $sub) {
            array_push($archivos, ...$this->drive->listar($sub['id'], false));
        }

        return $archivos;
    }
}

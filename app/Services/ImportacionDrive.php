<?php

namespace App\Services;

use App\Exceptions\DriveException;
use App\Models\TipoReunion;
use App\Support\Auditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Lanza la importación del historial como proceso independiente (php artisan drive:importar)
 * y guarda su avance en caché, para que la API siga respondiendo mientras se descargan
 * los documentos de Drive.
 */
class ImportacionDrive
{
    private const CLAVE = 'drive:importacion';

    /** Si no hay noticias del proceso en este tiempo, se considera interrumpido. */
    private const SIN_LATIDO_MINUTOS = 10;

    public function estado(): ?array
    {
        $estado = Cache::get(self::CLAVE);

        if ($estado && $estado['estado'] === 'en_curso'
            && now()->diffInMinutes($estado['actualizado'], true) > self::SIN_LATIDO_MINUTOS) {
            $estado['estado'] = 'interrumpido';
            $estado['mensaje'] = 'La importación dejó de responder. Puede volver a ejecutarla: continúa donde quedó.';
            Cache::forever(self::CLAVE, $estado);
        }

        return $estado;
    }

    public function enCurso(): bool
    {
        return ($this->estado()['estado'] ?? null) === 'en_curso';
    }

    /**
     * Inicia la importación y responde de inmediato.
     *  - Con `php artisan serve` (desarrollo) atiende una petición a la vez: se lanza un proceso aparte.
     *  - En el servidor (Apache/LiteSpeed/FPM) se ejecuta después de enviar la respuesta al navegador,
     *    sin depender de exec() ni popen(), que los hostings compartidos suelen deshabilitar.
     */
    public function lanzar(TipoReunion $tipo, string $clase): void
    {
        if ($this->enCurso()) {
            throw new DriveException('Ya hay una importación en curso. Espere a que termine.');
        }

        $this->iniciar($tipo, $clase, 'Iniciando…');

        if (PHP_SAPI === 'cli-server') {
            $this->procesoAparte($tipo, $clase);

            return;
        }

        app()->terminating(function () use ($tipo, $clase) {
            ignore_user_abort(true);
            set_time_limit(0);
            Artisan::call('drive:importar', ['reunion' => $tipo->slug, 'clase' => $clase]);
        });
    }

    private function procesoAparte(TipoReunion $tipo, string $clase): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $log = escapeshellarg(storage_path('logs/drive-importacion.log'));
        $args = escapeshellarg($tipo->slug).' '.escapeshellarg($clase);

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B {$php} {$artisan} drive:importar {$args} > {$log} 2>&1", 'r'));
        } else {
            exec("{$php} {$artisan} drive:importar {$args} > {$log} 2>&1 &");
        }
    }

    public function iniciar(TipoReunion $tipo, string $clase, string $linea = 'Leyendo carpetas de Drive…'): void
    {
        Cache::forever(self::CLAVE, [
            'estado' => 'en_curso',
            'tipo_reunion_id' => $tipo->id,
            'reunion' => $tipo->nombre,
            'clase' => $clase,
            'inicio' => now()->toIso8601String(),
            'actualizado' => now()->toIso8601String(),
            'linea' => $linea,
            'sesiones' => 0,
            'resultado' => null,
            'mensaje' => null,
            'usuario' => Auditor::usuario(),
        ]);
    }

    /** Usuario que lanzó la importación (para auditar desde el proceso en segundo plano). */
    public function usuario(): ?array
    {
        return Cache::get(self::CLAVE)['usuario'] ?? null;
    }

    public function avance(string $linea): void
    {
        $estado = Cache::get(self::CLAVE, []);
        $estado['linea'] = $linea;
        $estado['actualizado'] = now()->toIso8601String();
        if (str_starts_with($linea, 'Sesión')) {
            $estado['sesiones'] = ($estado['sesiones'] ?? 0) + 1;
        }
        Cache::forever(self::CLAVE, $estado);
    }

    public function terminar(array $resultado): void
    {
        Cache::forever(self::CLAVE, array_merge(Cache::get(self::CLAVE, []), [
            'estado' => 'terminado',
            'actualizado' => now()->toIso8601String(),
            'resultado' => $resultado,
        ]));
    }

    public function fallar(string $mensaje): void
    {
        Cache::forever(self::CLAVE, array_merge(Cache::get(self::CLAVE, []), [
            'estado' => 'error',
            'actualizado' => now()->toIso8601String(),
            'mensaje' => $mensaje,
        ]));
    }
}

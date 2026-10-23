<?php

namespace App\Services;

use App\Exceptions\DriveException;
use App\Models\TipoReunion;
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

    /** Inicia el proceso en segundo plano y responde de inmediato. */
    public function lanzar(TipoReunion $tipo, string $clase): void
    {
        if ($this->enCurso()) {
            throw new DriveException('Ya hay una importación en curso. Espere a que termine.');
        }

        $this->iniciar($tipo, $clase, 'Iniciando…');

        $php = escapeshellarg($this->binarioPhp());
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
        ]);
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

    /** Bajo `php artisan serve`, PHP_BINARY es el mismo php.exe; en Apache/FPM puede no serlo. */
    private function binarioPhp(): string
    {
        $binario = PHP_BINARY;
        if (str_contains(strtolower(basename($binario)), 'php') && ! str_contains(strtolower($binario), 'fpm')
            && ! str_contains(strtolower($binario), 'httpd')) {
            return $binario;
        }

        return config('services.php_cli', 'php');
    }
}

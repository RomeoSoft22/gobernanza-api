<?php

use App\Models\Sesion;
use App\Models\SesionDocumento;
use App\Models\TipoReunion;
use App\Services\DriveSincronizador;
use App\Services\ImportacionDrive;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Importa desde Google Drive las sesiones y documentos existentes.
 *   php artisan drive:importar operativo
 *   php artisan drive:importar tactico extraordinaria
 * El panel lo lanza en segundo plano y muestra el avance (ImportacionDrive).
 */
Artisan::command('drive:importar {reunion : slug de la reunión (operativo, tactico)} {clase=ordinaria}', function (DriveSincronizador $sync, ImportacionDrive $estado) {
    $tipo = TipoReunion::where('slug', $this->argument('reunion'))->firstOrFail();
    $clase = $this->argument('clase');
    if (! in_array($clase, Sesion::CLASES, true)) {
        $this->error('La clase debe ser ordinaria o extraordinaria.');

        return 1;
    }

    $estado->iniciar($tipo, $clase);
    $this->info("Importando {$tipo->nombre} ({$clase}s) desde Google Drive…");

    try {
        $stats = $sync->importarHistorial($tipo, $clase, function (string $linea) use ($estado) {
            $this->line($linea);
            $estado->avance(trim($linea));
        });
    } catch (Throwable $e) {
        $estado->fallar($e->getMessage());
        $this->error($e->getMessage());

        return 1;
    }

    $estado->terminar($stats);
    $this->newLine();
    $this->info("Años: {$stats['anios']} · Sesiones: {$stats['sesiones']} · Documentos: {$stats['documentos']} · Desvinculados: {$stats['desvinculados']}");
    foreach ($stats['omitidas'] as $omitida) {
        $this->warn("Carpeta omitida (nombre fuera del formato AAAA.MM.DD - NN…): {$omitida}");
    }
})->purpose('Importa sesiones y documentos desde las carpetas de Google Drive');

/*
 * Libera espacio: elimina las copias locales de documentos que ya están en Google Drive
 * (el sistema ahora trabaja solo con Drive). Los documentos sin enlace a Drive se conservan.
 */
Artisan::command('drive:limpiar-copias', function () {
    $disco = Illuminate\Support\Facades\Storage::disk(SesionDocumento::DISCO);
    $bytes = 0;
    $total = 0;

    SesionDocumento::whereNotNull('ruta')->whereNotNull('drive_file_id')->each(function (SesionDocumento $doc) use ($disco, &$bytes, &$total) {
        if ($disco->exists($doc->ruta)) {
            $bytes += $disco->size($doc->ruta);
            $disco->delete($doc->ruta);
        }
        $doc->updateQuietly(['ruta' => null]);
        $total++;
    });

    // Carpetas que quedaron vacías
    foreach (array_reverse($disco->allDirectories('sesiones')) as $dir) {
        if (! $disco->allFiles($dir)) {
            $disco->deleteDirectory($dir);
        }
    }

    $this->info(sprintf('Copias eliminadas: %d · Espacio liberado: %.1f MB', $total, $bytes / 1048576));
})->purpose('Elimina las copias locales de documentos que ya están en Google Drive');

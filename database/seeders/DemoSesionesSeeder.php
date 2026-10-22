<?php

namespace Database\Seeders;

use App\Models\Sesion;
use App\Models\TipoReunion;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Datos de DEMOSTRACIÓN: 72 sesiones semanales con algunas semanas sin reunión.
 * Ejecutar solo para pruebas:  php artisan db:seed --class=DemoSesionesSeeder
 * Para limpiar:                php artisan migrate:fresh --seed
 */
class DemoSesionesSeeder extends Seeder
{
    public function run(): void
    {
        $tipo = TipoReunion::where('codigo', 'SEG-OPER')->firstOrFail();

        $fechas = [];
        $fecha = Carbon::parse('2026-10-20');
        $semana = 0;
        while (count($fechas) < 72) {
            // Cada 7 semanas no hubo reunión (feriados, agenda institucional, etc.)
            if ($semana % 7 !== 3) {
                $fechas[] = $fecha->copy();
            }
            $fecha->subWeek();
            $semana++;
        }

        foreach (array_reverse($fechas) as $i => $f) {
            Sesion::updateOrCreate(
                ['tipo_reunion_id' => $tipo->id, 'numero' => $i + 1],
                [
                    'fecha' => $f->toDateString(),
                    'hora_inicio' => '09:00',
                    'hora_fin' => '10:30',
                    'modalidad' => $i % 3 === 0 ? 'virtual' : 'presencial',
                    'lugar' => $i % 3 === 0 ? 'Microsoft Teams' : 'Sala de reuniones - Gerencia',
                    'estado' => 'realizada',
                    'agenda' => "1. Revisión de acuerdos de la sesión anterior\n2. Avance de indicadores operativos\n3. Puntos varios",
                ]
            );
        }
    }
}

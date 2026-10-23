<?php

namespace Database\Seeders;

use App\Models\TipoReunion;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $reuniones = [
            [
                'codigo' => 'SEG-OPER',
                'slug' => 'operativo',
                'nombre' => 'Seguimiento Operativo',
                'periodicidad' => 'semanal',
                'orden' => 1,
                'descripcion' => 'Reunión de seguimiento operativo conforme a los lineamientos de gobernanza de la RA 315-2026.',
            ],
            [
                'codigo' => 'SEG-TACT',
                'slug' => 'tactico',
                'nombre' => 'Seguimiento Táctico',
                'periodicidad' => null,
                'orden' => 2,
                'descripcion' => 'Reunión de seguimiento táctico conforme a los lineamientos de gobernanza de la RA 315-2026.',
            ],
        ];

        foreach ($reuniones as $r) {
            TipoReunion::updateOrCreate(['codigo' => $r['codigo']], $r + ['base_legal' => 'RA 315-2026', 'activo' => true]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\TipoReunion;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        TipoReunion::updateOrCreate(
            ['codigo' => 'SEG-OPER'],
            [
                'nombre' => 'Seguimiento Operativo',
                'base_legal' => 'RA 315-2026',
                'periodicidad' => 'semanal',
                'descripcion' => 'Reunión de seguimiento operativo conforme a los lineamientos de gobernanza establecidos en la RA 315-2026.',
                'activo' => true,
            ]
        );
    }
}

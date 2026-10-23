<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las extraordinarias reinician su numeración cada año, por lo que la unicidad
 * en BD pasa a ser (reunión, clase, año, número). La regla adicional de que las
 * ordinarias no repitan número entre años se valida en SesionRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            // Primero el nuevo índice: también sirve a la llave foránea de tipo_reunion_id.
            $table->unique(['tipo_reunion_id', 'clase', 'anio', 'numero']);
            $table->dropUnique(['tipo_reunion_id', 'clase', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->unique(['tipo_reunion_id', 'clase', 'numero']);
            $table->dropUnique(['tipo_reunion_id', 'clase', 'anio', 'numero']);
        });
    }
};

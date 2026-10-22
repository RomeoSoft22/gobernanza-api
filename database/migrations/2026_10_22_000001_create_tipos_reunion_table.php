<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de reunión (p. ej. "Seguimiento Operativo" - RA 315-2026).
 * Permite incorporar otras reuniones de gobernanza sin cambiar la estructura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_reunion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->string('base_legal', 150)->nullable();
            $table->string('periodicidad', 30)->default('semanal');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_reunion');
    }
};

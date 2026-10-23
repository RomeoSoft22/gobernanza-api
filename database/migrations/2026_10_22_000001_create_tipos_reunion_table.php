<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de reuniones de gobernanza (Seguimiento Operativo, Seguimiento Táctico…).
 * El slug se usa en las rutas del front-end: /reuniones/{slug}/sesiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_reunion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('slug', 50)->unique();
            $table->string('nombre', 150);
            $table->string('base_legal', 150)->nullable();
            $table->string('periodicidad', 30)->nullable();
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_reunion');
    }
};

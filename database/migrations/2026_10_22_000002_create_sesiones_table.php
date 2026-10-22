<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de una reunión. El número es correlativo por tipo de reunión
 * (no se reinicia por año) y el año se guarda para filtrar/agrupar rápido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_reunion_id')->constrained('tipos_reunion')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->unsignedSmallInteger('anio');
            $table->date('fecha');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->enum('modalidad', ['presencial', 'virtual', 'mixta'])->default('presencial');
            $table->string('lugar', 200)->nullable();
            $table->enum('estado', ['programada', 'realizada', 'reprogramada', 'suspendida'])->default('realizada');
            $table->text('agenda')->nullable();
            $table->text('acuerdos')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_reunion_id', 'numero']);
            $table->index(['anio', 'fecha']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};

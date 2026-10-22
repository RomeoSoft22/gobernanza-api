<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de cada sesión: acta, lista de asistencia, presentación
 * (uno vigente por tipo) y adjuntos adicionales (varios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesion_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $table->enum('tipo', ['acta', 'asistencia', 'presentacion', 'adjunto']);
            $table->string('nombre_original', 255);
            $table->string('ruta', 500);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();

            $table->index(['sesion_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesion_documentos');
    }
};

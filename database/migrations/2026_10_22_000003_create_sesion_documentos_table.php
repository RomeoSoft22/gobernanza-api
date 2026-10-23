<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de cada sesión. El repositorio oficial es Google Drive
 * (drive_file_id / drive_url) y el servidor guarda una copia (ruta).
 * Se admiten varios archivos por tipo (p. ej. acta en PDF firmado y en Word).
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
            $table->string('ruta', 500)->nullable();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('descripcion', 255)->nullable();

            $table->string('drive_file_id', 100)->nullable();
            $table->string('drive_url', 255)->nullable();
            $table->timestamp('drive_modificado_at')->nullable();

            $table->timestamps();

            $table->index(['sesion_id', 'tipo']);
            $table->unique(['sesion_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesion_documentos');
    }
};

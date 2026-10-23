<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de una reunión. El número es correlativo por reunión y clase
 * (ordinaria / extraordinaria) y no se reinicia por año.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_reunion_id')->constrained('tipos_reunion')->restrictOnDelete();
            $table->enum('clase', ['ordinaria', 'extraordinaria'])->default('ordinaria');
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

            // Carpeta de la sesión en Google Drive
            $table->string('drive_folder_id', 100)->nullable();
            $table->string('drive_folder_url', 255)->nullable();
            $table->timestamp('drive_sincronizado_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_reunion_id', 'clase', 'numero']);
            $table->index(['anio', 'fecha']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuenta de Google conectada por OAuth (tokens cifrados).
        Schema::create('drive_conexiones', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expira_at')->nullable();
            $table->timestamps();
        });

        // Carpetas de Drive por reunión y clase:
        //   anio NULL -> carpeta raíz elegida por el usuario (p. ej. "2. Reuniones Ordinarias")
        //   anio N    -> carpeta del año dentro de la raíz (p. ej. "2026")
        Schema::create('drive_carpetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_reunion_id')->constrained('tipos_reunion')->cascadeOnDelete();
            $table->enum('clase', ['ordinaria', 'extraordinaria']);
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('folder_id', 100);
            $table->string('nombre', 255);
            $table->string('url', 255);
            $table->timestamps();

            $table->unique(['tipo_reunion_id', 'clase', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_carpetas');
        Schema::dropIfExists('drive_conexiones');
    }
};

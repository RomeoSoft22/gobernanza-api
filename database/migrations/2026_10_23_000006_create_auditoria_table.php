<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de la gobernanza: cada movimiento con quién lo hizo (usuario de identidad),
 * cuándo, desde dónde y qué cambió. Solo se inserta; nunca se edita ni se borra desde el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent();

            // Quién (claims del JWT de identidad; "Sistema" en procesos automáticos)
            $table->string('usuario_sub', 64)->nullable();
            $table->string('usuario_nombre', 150)->nullable();
            $table->string('usuario_correo', 150)->nullable();
            $table->string('usuario_roles', 150)->nullable();

            // Qué
            $table->string('modulo', 30);          // sesiones, documentos, drive, anios
            $table->string('accion', 50);          // sesion.creada, documento.movido…
            $table->string('entidad_tipo', 30)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->unsignedBigInteger('sesion_id')->nullable();
            $table->string('descripcion', 500);
            $table->json('datos')->nullable();     // antes / después, nombres de archivo, etc.

            // Desde dónde
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 255)->nullable();

            $table->index('created_at');
            $table->index(['modulo', 'accion']);
            $table->index('sesion_id');
            $table->index('usuario_correo');
        });

        Schema::table('sesiones', function (Blueprint $table) {
            $table->string('registrado_por', 150)->nullable()->after('observaciones');
            $table->string('actualizado_por', 150)->nullable()->after('registrado_por');
        });

        Schema::table('sesion_documentos', function (Blueprint $table) {
            $table->string('registrado_por', 150)->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('sesion_documentos', fn (Blueprint $table) => $table->dropColumn('registrado_por'));
        Schema::table('sesiones', fn (Blueprint $table) => $table->dropColumn(['registrado_por', 'actualizado_por']));
        Schema::dropIfExists('auditoria');
    }
};

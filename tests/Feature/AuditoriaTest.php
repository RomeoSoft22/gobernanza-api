<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Sesion;
use App\Models\SesionDocumento;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaIdentidad;
use Tests\TestCase;

/** Cada movimiento queda registrado con quién lo hizo; Drive y Auditoría solo para ADMIN. */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase, SimulaIdentidad;

    private string $operador;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->prepararIdentidad();
        $this->operador = $this->tokenIdentidad(['OPERADOR'], ['name' => 'Luis Operador', 'email' => 'luis@juarezcampos.net']);
        $this->admin = $this->tokenIdentidad(['ADMIN']);
    }

    private function sesion(array $datos = []): array
    {
        return array_merge([
            'tipo_reunion_id' => 1, 'clase' => 'ordinaria', 'numero' => 73, 'fecha' => '2026-10-26',
            'modalidad' => 'presencial', 'estado' => 'programada',
        ], $datos);
    }

    public function test_drive_y_auditoria_solo_para_administradores(): void
    {
        foreach (['/api/drive/estado', '/api/drive/importacion', '/api/auditoria', '/api/auditoria/catalogos'] as $ruta) {
            $this->withToken($this->operador)->getJson($ruta)->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
            $this->withToken($this->admin)->getJson($ruta)->assertOk();
        }
        $this->withToken($this->operador)->putJson('/api/drive/carpetas', [])->assertForbidden();
        $this->withToken($this->operador)->postJson('/api/drive/importar-historial', [])->assertForbidden();
    }

    public function test_registrar_editar_y_eliminar_sesion_queda_auditado(): void
    {
        $id = $this->withToken($this->operador)->postJson('/api/sesiones', $this->sesion())->assertCreated()
            ->assertJsonPath('registrado_por', 'Luis Operador')->json('id');

        $this->withToken($this->admin)->putJson("/api/sesiones/{$id}", $this->sesion(['estado' => 'realizada', 'lugar' => 'Sala 2']))
            ->assertOk()->assertJsonPath('actualizado_por', 'Ana Pérez');

        $this->withToken($this->operador)->deleteJson("/api/sesiones/{$id}")->assertNoContent();

        $eventos = Auditoria::orderBy('id')->get();
        $this->assertSame(['sesion.creada', 'sesion.actualizada', 'sesion.eliminada'], $eventos->pluck('accion')->all());
        $this->assertSame(['Luis Operador', 'Ana Pérez', 'Luis Operador'], $eventos->pluck('usuario_nombre')->all());
        $this->assertSame('luis@juarezcampos.net', $eventos[0]->usuario_correo);
        $this->assertSame((string) $id, (string) $eventos[2]->sesion_id);
        $this->assertEquals(
            ['estado' => ['antes' => 'programada', 'despues' => 'realizada'], 'lugar' => ['antes' => null, 'despues' => 'Sala 2']],
            $eventos[1]->datos['cambios'],
        );
    }

    public function test_mover_documento_de_tipo_queda_auditado(): void
    {
        $sesion = Sesion::create($this->sesion());
        $doc = $sesion->documentos()->create(['tipo' => 'acta', 'nombre_original' => 'lista.pdf', 'registrado_por' => 'Luis Operador']);

        $this->withToken($this->operador)->patchJson("/api/documentos/{$doc->id}", ['tipo' => 'asistencia'])
            ->assertOk()->assertJsonPath('tipo', 'asistencia');

        $evento = Auditoria::where('accion', 'documento.movido')->sole();
        $this->assertSame('Luis Operador', $evento->usuario_nombre);
        $this->assertSame(['archivo' => 'lista.pdf', 'de' => 'acta', 'a' => 'asistencia'], $evento->datos);
        $this->assertStringContainsString('movido de Acta a Lista de asistencia', $evento->descripcion);
    }

    public function test_quitar_documento_queda_auditado(): void
    {
        $sesion = Sesion::create($this->sesion());
        $doc = $sesion->documentos()->create(['tipo' => 'presentacion', 'nombre_original' => 'avance.pptx']);

        $this->withToken($this->operador)->deleteJson("/api/documentos/{$doc->id}")->assertNoContent();

        $this->assertNull(SesionDocumento::find($doc->id));
        $evento = Auditoria::where('accion', 'documento.eliminado')->sole();
        $this->assertSame('Luis Operador', $evento->usuario_nombre);
        $this->assertSame($sesion->id, (int) $evento->sesion_id);
    }

    public function test_el_administrador_consulta_filtra_y_exporta(): void
    {
        $this->withToken($this->operador)->postJson('/api/sesiones', $this->sesion())->assertCreated();
        $this->withToken($this->admin)->postJson('/api/sesiones', $this->sesion(['numero' => 74]))->assertCreated();

        $this->withToken($this->admin)->getJson('/api/auditoria')->assertOk()->assertJsonPath('total', 2);
        $this->withToken($this->admin)->getJson('/api/auditoria?usuario=luis')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.usuario_nombre', 'Luis Operador');
        $this->withToken($this->admin)->getJson('/api/auditoria?accion=sesion.eliminada')->assertJsonPath('total', 0);

        $csv = $this->withToken($this->admin)->get('/api/auditoria/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('Luis Operador', $csv);
        $this->assertStringContainsString('Sesión registrada', $csv);
    }
}

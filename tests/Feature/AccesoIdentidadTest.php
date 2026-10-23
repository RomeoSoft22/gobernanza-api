<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulaIdentidad;
use Tests\TestCase;

/** Todo el sistema exige el JWT de identidad (aud = GOBERNANZA, rol ADMIN). */
class AccesoIdentidadTest extends TestCase
{
    use RefreshDatabase, SimulaIdentidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->prepararIdentidad();
    }

    public function test_las_rutas_del_sistema_exigen_token(): void
    {
        $this->getJson('/api/tipos-reunion')->assertUnauthorized()->assertJsonPath('codigo', 'no_autenticado');
        $this->getJson('/api/sesiones')->assertUnauthorized();
        $this->getJson('/api/drive/estado')->assertUnauthorized();
        $this->postJson('/api/sesiones', [])->assertUnauthorized();
    }

    public function test_el_flujo_de_ingreso_es_publico(): void
    {
        $this->getJson('/api/estado')->assertOk();
        $this->getJson('/api/acceso/config')->assertOk()
            ->assertJsonPath('authorize_endpoint', self::IDENTIDAD.'/authorize')
            ->assertJsonPath('client_id', 'cliente-gobernanza')
            ->assertJsonPath('redirect_uri', 'https://gobernanza.test/callback');
    }

    public function test_token_valido_da_acceso(): void
    {
        $jwt = $this->tokenIdentidad(['ADMIN']);

        $this->withToken($jwt)->getJson('/api/acceso/yo')->assertOk()
            ->assertJsonPath('usuario.correo', 'ana@juarezcampos.net')
            ->assertJsonPath('usuario.admin', true);
        $this->withToken($this->tokenIdentidad(['OPERADOR']))->getJson('/api/acceso/yo')
            ->assertJsonPath('usuario.admin', false);
        $this->withToken($jwt)->getJson('/api/tipos-reunion')->assertOk()->assertJsonCount(2);
        $this->withToken($jwt)->getJson('/api/sesiones?tipo_reunion_id=1')->assertOk();
    }

    public function test_token_sin_ningun_rol_recibe_403(): void
    {
        $this->withToken($this->tokenIdentidad([]))->getJson('/api/sesiones')
            ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    }

    public function test_cualquier_rol_de_gobernanza_puede_operar(): void
    {
        $this->withToken($this->tokenIdentidad(['OPERADOR']))->getJson('/api/sesiones')->assertOk();
    }

    public function test_rechaza_tokens_invalidos(): void
    {
        $otraLlave = file_get_contents(base_path('tests/fixtures/otra-llave-prueba.key'));
        $invalidos = [
            'otro sistema' => $this->tokenIdentidad(claims: ['aud' => 'FIRMA']),
            'otro emisor' => $this->tokenIdentidad(claims: ['iss' => 'https://falso.test']),
            'vencido' => $this->tokenIdentidad(claims: ['exp' => time() - 120, 'iat' => time() - 1200, 'nbf' => time() - 1200]),
            'firma ajena' => $this->tokenIdentidad(llave: $otraLlave),
            'basura' => 'no.es.jwt',
        ];

        foreach ($invalidos as $caso => $token) {
            $this->withToken($token)->getJson('/api/sesiones')->assertUnauthorized();
        }
    }

    public function test_canje_del_codigo_entrega_token_y_cookie_de_renovacion(): void
    {
        $this->respuestaToken = [
            'access_token' => $this->tokenIdentidad(['ADMIN']),
            'refresh_token' => 'refresh-secreto',
            'expires_in' => 900,
        ];

        $respuesta = $this->postJson('/api/acceso/canjear', ['code' => 'codigo', 'code_verifier' => str_repeat('v', 43)])
            ->assertOk()
            ->assertJsonPath('usuario.nombre', 'Ana Pérez')
            ->assertCookie('gobernanza_refresh');

        $this->assertStringNotContainsString('refresh-secreto', $respuesta->getContent());

        // El canje viaja servidor a servidor con el secreto del cliente.
        Http::assertSent(fn (PeticionHttp $r) => str_ends_with($r->url(), '/oauth/token')
            && $r['client_secret'] === 'secreto-gobernanza'
            && $r['grant_type'] === 'authorization_code');
    }

    public function test_renovar_sin_cookie_responde_401(): void
    {
        $this->postJson('/api/acceso/renovar')->assertUnauthorized();
    }

    public function test_salir_devuelve_la_url_de_logout_de_identidad(): void
    {
        $this->postJson('/api/acceso/salir')->assertOk()
            ->assertJsonPath('logout_url', self::IDENTIDAD.'/logout?post_logout_redirect_uri='.urlencode('https://gobernanza.test/'));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriveCarpeta;
use App\Models\DriveConexion;
use App\Models\Sesion;
use App\Models\TipoReunion;
use App\Services\DriveSincronizador;
use App\Services\GoogleDrive;
use App\Services\ImportacionDrive;
use App\Support\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class DriveController extends Controller
{
    public function __construct(
        private GoogleDrive $drive,
        private DriveSincronizador $sync,
        private ImportacionDrive $importacion,
    ) {}

    public function estado()
    {
        return [
            'configurado' => $this->drive->configurado(),
            'conectado' => $this->drive->conectado(),
            'email' => DriveConexion::actual()?->email,
            'redirect_uri' => config('services.google.redirect'),
            'carpetas' => DriveCarpeta::orderBy('tipo_reunion_id')->orderBy('clase')->orderByDesc('anio')->get(),
            'importacion' => $this->importacion->estado(),
        ];
    }

    public function autorizar()
    {
        abort_unless($this->drive->configurado(), 422, 'Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env de la API.');

        $state = Str::random(40);
        // Se guarda quién inició la conexión: el regreso de Google no trae el JWT del usuario.
        Cache::put("drive_oauth_state:{$state}", Auditor::usuario(), now()->addMinutes(10));

        return ['url' => $this->drive->urlAutorizacion($state)];
    }

    /** Ruta web a la que Google redirige después del consentimiento. */
    public function callback(Request $request)
    {
        $volver = rtrim(config('services.frontend_url'), '/').'/configuracion/drive';

        $usuario = Cache::pull('drive_oauth_state:'.$request->input('state'));
        if ($request->filled('error') || ! $usuario) {
            return redirect()->away($volver.'?error='.urlencode($request->input('error', 'Solicitud no válida o expirada.')));
        }

        try {
            $conexion = $this->drive->conectarConCodigo($request->input('code'));
        } catch (Throwable $e) {
            report($e);

            return redirect()->away($volver.'?error='.urlencode($e->getMessage()));
        }

        Auditor::comoUsuario(is_array($usuario) ? $usuario : null);
        Auditor::registrar('drive.conectado', "Google Drive conectado con la cuenta {$conexion->email}", null, ['cuenta_google' => $conexion->email]);
        Auditor::comoUsuario(null);

        return redirect()->away($volver.'?conectado=1');
    }

    public function desconectar()
    {
        $cuenta = DriveConexion::actual()?->email;
        $this->drive->desconectar();
        Auditor::registrar('drive.desconectado', 'Google Drive desconectado'.($cuenta ? " (cuenta {$cuenta})" : ''), null, ['cuenta_google' => $cuenta]);

        return response()->noContent();
    }

    /** Define la carpeta raíz de una reunión/clase pegando el enlace de Drive. */
    public function definirCarpeta(Request $request)
    {
        $data = $request->validate([
            'tipo_reunion_id' => ['required', 'exists:tipos_reunion,id'],
            'clase' => ['required', Rule::in(Sesion::CLASES)],
            'enlace' => ['required', 'string', 'max:500'],
        ]);

        $tipo = TipoReunion::findOrFail($data['tipo_reunion_id']);
        $anterior = DriveCarpeta::raiz($tipo->id, $data['clase']);
        $carpeta = $this->sync->definirRaiz($tipo, $data['clase'], $data['enlace']);

        Auditor::registrar('drive.carpeta_asignada', "Carpeta \"{$carpeta->nombre}\" asignada a {$tipo->nombre} ({$data['clase']}s)", $carpeta, [
            'reunion' => $tipo->nombre,
            'clase' => $data['clase'],
            'antes' => $anterior ? ['nombre' => $anterior->nombre, 'url' => $anterior->url] : null,
            'despues' => ['nombre' => $carpeta->nombre, 'url' => $carpeta->url],
        ]);

        return $carpeta;
    }

    public function quitarCarpeta(DriveCarpeta $carpeta)
    {
        DriveCarpeta::where(['tipo_reunion_id' => $carpeta->tipo_reunion_id, 'clase' => $carpeta->clase])->delete();
        $tipo = $carpeta->tipoReunion;
        Auditor::registrar('drive.carpeta_quitada', "Carpeta \"{$carpeta->nombre}\" quitada de {$tipo?->nombre} ({$carpeta->clase}s)", $carpeta, [
            'reunion' => $tipo?->nombre, 'clase' => $carpeta->clase, 'url' => $carpeta->url,
        ]);

        return response()->noContent();
    }

    /**
     * Lanza en segundo plano la importación de sesiones y documentos de una reunión/clase.
     * Responde de inmediato; el avance se consulta en GET /api/drive/importacion.
     */
    public function importarHistorial(Request $request)
    {
        $data = $request->validate([
            'tipo_reunion_id' => ['required', 'exists:tipos_reunion,id'],
            'clase' => ['required', Rule::in(Sesion::CLASES)],
        ]);

        abort_unless($this->drive->conectado(), 422, 'Google Drive no está conectado.');
        abort_unless(DriveCarpeta::raiz($data['tipo_reunion_id'], $data['clase']), 422, 'Asigne primero la carpeta de Drive.');

        $tipo = TipoReunion::findOrFail($data['tipo_reunion_id']);
        $this->importacion->lanzar($tipo, $data['clase']);
        Auditor::registrar('drive.importacion_iniciada', "Importación desde Drive de {$tipo->nombre} ({$data['clase']}s) iniciada", null, [
            'reunion' => $tipo->nombre, 'clase' => $data['clase'],
        ]);

        return response()->json($this->importacion->estado(), 202);
    }

    public function estadoImportacion()
    {
        return response()->json($this->importacion->estado());
    }

    // ------------------------------------------------------------ Por sesión

    /**
     * Lista en vivo la carpeta de la sesión en Drive y actualiza sus documentos.
     * ?crear=1 crea (o busca) la carpeta si la sesión aún no la tiene.
     */
    public function sincronizarSesion(Request $request, Sesion $sesion)
    {
        abort_unless($this->drive->configurado() && $this->drive->conectado(), 422, 'Google Drive no está conectado.');

        $resultado = $this->sync->sincronizarSesion($sesion, $request->boolean('crear'));

        return [
            'resultado' => $resultado,
            'sesion' => $sesion->refresh()->load([
                'tipoReunion',
                'documentos' => fn ($q) => $q->orderBy('tipo')->orderBy('nombre_original'),
            ]),
        ];
    }
}

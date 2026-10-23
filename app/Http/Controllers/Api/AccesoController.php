<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Identidad;
use Illuminate\Http\Client\Response as RespuestaIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Ingreso con identidad.juarezcampos.net (SSO). El login ocurre en identidad; aquí solo se
 * canjea el código (servidor a servidor, con el secreto del cliente), se renueva el access token
 * con el refresh token guardado en una cookie HttpOnly y se arma la URL para cerrar la sesión.
 */
class AccesoController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'authorize_endpoint' => Identidad::config('url').'/authorize',
            'client_id' => Identidad::config('client_id'),
            'redirect_uri' => Identidad::config('redirect_uri'),
        ]);
    }

    public function canjear(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ]);

        return $this->responderTokens($request, Identidad::canjearCodigo($datos['code'], $datos['code_verifier']));
    }

    public function renovar(Request $request): JsonResponse
    {
        $refresh = $request->cookie(Identidad::config('refresh_cookie'));
        if (! $refresh) {
            return response()->json(['codigo' => 'no_autenticado', 'message' => 'No hay una sesión abierta.'], 401);
        }

        return $this->responderTokens($request, Identidad::renovar($refresh));
    }

    /** Quién está conectado (la ruta está protegida por RequiereAcceso). */
    public function yo(Request $request): JsonResponse
    {
        return response()->json(['usuario' => $this->usuario($request->attributes->get('identidad'))]);
    }

    public function salir(Request $request): JsonResponse
    {
        $destino = Identidad::config('post_logout_redirect_uri');
        $url = Identidad::config('url').'/logout'.($destino ? '?post_logout_redirect_uri='.urlencode($destino) : '');

        return response()->json(['logout_url' => $url])->withCookie($this->olvidarCookie($request));
    }

    private function responderTokens(Request $request, RespuestaIdentidad $respuesta): JsonResponse
    {
        $tokens = $respuesta->json();
        if (! $respuesta->successful() || empty($tokens['access_token'])) {
            Log::info('identidad rechazó la emisión de token', ['status' => $respuesta->status(), 'error' => $tokens['error'] ?? null]);

            return response()->json(['codigo' => 'no_autenticado', 'message' => 'Tu sesión no es válida o venció. Vuelve a iniciar sesión.'], 401)
                ->withCookie($this->olvidarCookie($request));
        }

        try {
            $claims = Identidad::verificar($tokens['access_token']);
        } catch (Throwable $e) {
            Log::warning('token de identidad inválido', ['error' => $e->getMessage()]);

            return response()->json(['codigo' => 'no_autenticado', 'message' => 'No pudimos validar tu sesión. Vuelve a iniciar sesión.'], 401);
        }

        if (! Identidad::tieneAcceso($claims)) {
            return response()->json(['codigo' => 'sin_permiso', 'message' => 'Tu cuenta no tiene permiso para usar el sistema de gobernanza.'], 403)
                ->withCookie($this->olvidarCookie($request));
        }

        $json = response()->json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) ($tokens['expires_in'] ?? 900),
            'usuario' => $this->usuario($claims),
        ]);

        return empty($tokens['refresh_token'])
            ? $json
            : $json->withCookie($this->cookie($request, $tokens['refresh_token'], (int) Identidad::config('refresh_minutes')));
    }

    private function usuario(array $claims): array
    {
        return ['nombre' => $claims['name'] ?? null, 'correo' => $claims['email'] ?? null, 'roles' => (array) ($claims['roles'] ?? [])];
    }

    /**
     * La cookie solo viaja a las rutas de acceso de esta API. En producción la API vive en
     * /gobernanza-api, por eso la ruta se toma de la petición (base + /api/acceso).
     */
    private function cookie(Request $request, string $valor, int $minutos): Cookie
    {
        $ruta = rtrim($request->getBaseUrl(), '/').'/api/acceso';

        return cookie(Identidad::config('refresh_cookie'), $valor, $minutos, $ruta, null, $request->isSecure(), true, false, 'strict');
    }

    private function olvidarCookie(Request $request): Cookie
    {
        return $this->cookie($request, '', -2628000);
    }
}

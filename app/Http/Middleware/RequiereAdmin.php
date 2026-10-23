<?php

namespace App\Http\Middleware;

use App\Support\Identidad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuración de Google Drive y Auditoría: solo perfiles de administración (IDENTIDAD_ROLES_ADMIN).
 * Va después de RequiereAcceso, que ya validó el token y dejó los claims en la petición.
 */
class RequiereAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Identidad::esAdministrador((array) $request->attributes->get('identidad', []))) {
            return response()->json(['codigo' => 'sin_permiso', 'message' => 'Esta sección solo está disponible para el perfil administrador.'], 403);
        }

        return $next($request);
    }
}

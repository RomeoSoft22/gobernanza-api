<?php

use App\Exceptions\DriveException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (DriveException $e) => response()->json(['message' => $e->getMessage()], 422));

        // Errores devueltos por la API de Google (carpeta inexistente, sin permisos, etc.)
        $exceptions->render(function (RequestException $e, Request $request) {
            if (! str_contains((string) $e->response->effectiveUri(), 'googleapis.com')) {
                return null;
            }
            $mensaje = $e->response->json('error.message') ?? $e->response->json('error_description') ?? 'Error al comunicarse con Google Drive.';

            return response()->json(['message' => 'Google Drive: '.$mensaje], $e->response->status() === 401 ? 401 : 422);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

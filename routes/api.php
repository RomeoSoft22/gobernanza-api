<?php

use App\Http\Controllers\Api\AccesoController;
use App\Http\Controllers\Api\DriveController;
use App\Http\Controllers\Api\SesionController;
use App\Http\Controllers\Api\SesionDocumentoController;
use App\Http\Controllers\Api\TipoReunionController;
use App\Http\Middleware\RequiereAcceso;
use Illuminate\Support\Facades\Route;

Route::get('estado', fn () => ['estado' => 'ok']);

// ---------------------------------------------------------------- Ingreso (identidad.juarezcampos.net)
// El login ocurre en identidad; aquí solo se canjea el código, se renueva el token con la
// cookie HttpOnly y se arma la URL de salida.
Route::get('acceso/config', [AccesoController::class, 'config']);
Route::post('acceso/canjear', [AccesoController::class, 'canjear'])->middleware('throttle:20,1');
Route::post('acceso/renovar', [AccesoController::class, 'renovar'])->middleware('throttle:60,1');
Route::post('acceso/salir', [AccesoController::class, 'salir']);

// ---------------------------------------------------------------- Todo lo demás exige sesión de identidad
Route::middleware(RequiereAcceso::class)->group(function () {
    Route::get('acceso/yo', [AccesoController::class, 'yo']);

    Route::get('tipos-reunion', [TipoReunionController::class, 'index']);

    // Sesiones (Seguimiento Operativo, Seguimiento Táctico…)
    Route::get('sesiones/anios', [SesionController::class, 'anios']);
    Route::post('sesiones/anios', [SesionController::class, 'crearAnio']);
    Route::get('sesiones/resumen', [SesionController::class, 'resumen']);
    Route::get('sesiones/siguiente-numero', [SesionController::class, 'siguienteNumero']);
    Route::apiResource('sesiones', SesionController::class)->parameters(['sesiones' => 'sesion']);

    // Documentos de cada sesión
    Route::post('sesiones/{sesion}/documentos', [SesionDocumentoController::class, 'store']);
    Route::get('documentos/{documento}/ver', [SesionDocumentoController::class, 'ver']);
    Route::get('documentos/{documento}/descargar', [SesionDocumentoController::class, 'descargar']);
    Route::patch('documentos/{documento}', [SesionDocumentoController::class, 'update']);
    Route::delete('documentos/{documento}', [SesionDocumentoController::class, 'destroy']);

    // Google Drive
    Route::prefix('drive')->group(function () {
        Route::get('estado', [DriveController::class, 'estado']);
        Route::get('autorizar', [DriveController::class, 'autorizar']);
        Route::delete('conexion', [DriveController::class, 'desconectar']);
        Route::put('carpetas', [DriveController::class, 'definirCarpeta']);
        Route::delete('carpetas/{carpeta}', [DriveController::class, 'quitarCarpeta']);
        Route::post('importar-historial', [DriveController::class, 'importarHistorial']);
        Route::get('importacion', [DriveController::class, 'estadoImportacion']);
    });
    Route::post('sesiones/{sesion}/drive/sincronizar', [DriveController::class, 'sincronizarSesion']);
});

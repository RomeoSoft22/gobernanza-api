<?php

use App\Http\Controllers\Api\DriveController;
use App\Http\Controllers\Api\SesionController;
use App\Http\Controllers\Api\SesionDocumentoController;
use App\Http\Controllers\Api\TipoReunionController;
use Illuminate\Support\Facades\Route;

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

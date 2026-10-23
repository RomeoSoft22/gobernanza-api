<?php

use App\Http\Controllers\Api\DriveController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Google redirige aquí después de autorizar el acceso a Drive.
Route::get('drive/callback', [DriveController::class, 'callback']);

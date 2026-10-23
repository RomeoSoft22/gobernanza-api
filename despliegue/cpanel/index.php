<?php

/*
 * Controlador frontal de la API de gobernanza publicado en https://juarezcampos.net/gobernanza-api.
 * Vive en /home/juarezc/public_html/gobernanza-api; el código de Laravel está FUERA de la carpeta
 * pública (/home/juarezc/gobernanza-api-app), así .env, logs y código nunca son accesibles desde la web.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app = getenv('GOBERNANZA_API_PATH') ?: dirname(__DIR__, 2).'/gobernanza-api-app';

if (! is_file($app.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'El servicio no está disponible en este momento.']);
    exit;
}

if (is_file($mantenimiento = $app.'/storage/framework/maintenance.php')) {
    require $mantenimiento;
}

require $app.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $laravel */
$laravel = require_once $app.'/bootstrap/app.php';
$laravel->usePublicPath(__DIR__);
$laravel->handleRequest(Request::capture());

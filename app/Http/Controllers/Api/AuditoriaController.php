<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Consulta de la auditoría (solo perfil administrador). */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        return Auditoria::query()
            ->filtrar($this->filtros($request))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('por_pagina', 25));
    }

    /** Opciones de los filtros: acciones, módulos y usuarios que aparecen en la auditoría. */
    public function catalogos()
    {
        return [
            'acciones' => collect(Auditoria::ACCIONES)->map(fn ($etiqueta, $valor) => compact('valor', 'etiqueta'))->values(),
            'modulos' => [
                ['valor' => 'sesiones', 'etiqueta' => 'Sesiones y años'],
                ['valor' => 'documentos', 'etiqueta' => 'Documentos'],
                ['valor' => 'drive', 'etiqueta' => 'Google Drive'],
            ],
            'usuarios' => Auditoria::query()
                ->whereNotNull('usuario_correo')
                ->select('usuario_nombre', 'usuario_correo')
                ->distinct()
                ->orderBy('usuario_nombre')
                ->get(),
        ];
    }

    /** CSV (separado por ";" y con BOM, para abrirlo directo en Excel) con los mismos filtros. */
    public function exportar(Request $request): StreamedResponse
    {
        $consulta = Auditoria::query()->filtrar($this->filtros($request))->orderByDesc('created_at')->orderByDesc('id');
        $nombre = 'auditoria-gobernanza-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($consulta) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fecha y hora', 'Usuario', 'Correo', 'Perfil', 'Módulo', 'Acción', 'Descripción', 'Detalle', 'IP'], ';');

            $consulta->chunk(500, function ($filas) use ($salida) {
                foreach ($filas as $a) {
                    fputcsv($salida, [
                        $a->created_at?->timezone(config('app.timezone_local', 'America/Lima'))->format('d/m/Y H:i:s'),
                        $a->usuario_nombre,
                        $a->usuario_correo,
                        $a->usuario_roles,
                        $a->modulo,
                        Auditoria::ACCIONES[$a->accion] ?? $a->accion,
                        $a->descripcion,
                        $a->datos ? json_encode($a->datos, JSON_UNESCAPED_UNICODE) : '',
                        $a->ip,
                    ], ';');
                }
            });

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtros(Request $request): array
    {
        return $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'modulo' => ['nullable', Rule::in(['sesiones', 'documentos', 'drive'])],
            'accion' => ['nullable', Rule::in(array_keys(Auditoria::ACCIONES))],
            'usuario' => ['nullable', 'string', 'max:150'],
            'sesion_id' => ['nullable', 'integer'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'between:10,100'],
        ]);
    }
}

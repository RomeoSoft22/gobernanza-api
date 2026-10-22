<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\SesionDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SesionDocumentoController extends Controller
{
    private const EXTENSIONES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip,rar,txt,csv';

    /**
     * Sube uno o varios archivos. Para acta, asistencia y presentación
     * el archivo nuevo reemplaza al anterior; los adjuntos se acumulan.
     */
    public function store(Request $request, Sesion $sesion)
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(SesionDocumento::TIPOS)],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'archivos' => ['required', 'array', 'min:1', 'max:20'],
            'archivos.*' => ['file', 'max:51200', 'mimes:'.self::EXTENSIONES],
        ], [
            'archivos.*.max' => 'Cada archivo puede pesar como máximo 50 MB.',
            'archivos.*.mimes' => 'Formato no permitido. Use: '.self::EXTENSIONES.'.',
        ]);

        $unico = in_array($data['tipo'], SesionDocumento::TIPOS_UNICOS, true);
        $archivos = $unico ? [($data['archivos'])[0]] : $data['archivos'];
        $carpeta = sprintf('sesiones/%d/sesion-%03d/%s', $sesion->anio, $sesion->numero, $data['tipo']);

        $anteriores = $unico ? $sesion->documentos()->where('tipo', $data['tipo'])->get() : collect();

        $creados = DB::transaction(fn () => collect($archivos)->map(fn ($archivo) => $sesion->documentos()->create([
                'tipo' => $data['tipo'],
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta' => $archivo->store($carpeta, SesionDocumento::DISCO),
                'mime' => $archivo->getMimeType(),
                'tamano' => $archivo->getSize(),
                'descripcion' => $data['descripcion'] ?? null,
            ])));

        // Solo se elimina la versión anterior cuando la nueva quedó guardada.
        $anteriores->each->delete();

        return response()->json($creados, 201);
    }

    /** Muestra el archivo en el navegador (PDF, imágenes). */
    public function ver(SesionDocumento $documento)
    {
        return Storage::disk(SesionDocumento::DISCO)->response(
            $documento->ruta,
            $documento->nombre_original,
            ['Content-Type' => $documento->mime ?? 'application/octet-stream'],
            'inline'
        );
    }

    public function descargar(SesionDocumento $documento)
    {
        return Storage::disk(SesionDocumento::DISCO)->download($documento->ruta, $documento->nombre_original);
    }

    public function update(Request $request, SesionDocumento $documento)
    {
        $documento->update($request->validate(['descripcion' => ['nullable', 'string', 'max:255']]));

        return $documento;
    }

    public function destroy(SesionDocumento $documento)
    {
        $documento->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Models\SesionDocumento;
use App\Services\DriveSincronizador;
use App\Services\GoogleDrive;
use App\Support\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SesionDocumentoController extends Controller
{
    private const EXTENSIONES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip,rar,txt,csv';

    public function __construct(private DriveSincronizador $drive) {}

    /**
     * Sube uno o varios archivos directamente a la carpeta de la sesión en Google Drive.
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

        $creados = collect($data['archivos'])->map(function ($archivo) use ($sesion, $data) {
            $doc = $this->drive->subirDocumento($sesion, $archivo, $data['tipo'], $data['descripcion'] ?? null);
            Auditor::registrar('documento.subido', sprintf('"%s" subido como %s en %s',
                $doc->nombre_original, SesionDocumento::ETIQUETAS[$doc->tipo], Auditor::etiquetaSesion($sesion)), $doc, [
                    'archivo' => $doc->nombre_original,
                    'tipo' => $doc->tipo,
                    'tamano' => $doc->tamano,
                    'drive' => $doc->drive_url,
                ]);

            return $doc;
        });

        return response()->json($creados, 201);
    }

    /** Abre el archivo en Google Drive (las copias antiguas del servidor se siguen sirviendo). */
    public function ver(SesionDocumento $documento)
    {
        if ($documento->drive_url) {
            return redirect()->away($documento->drive_url);
        }

        return $this->copiaLocal($documento, 'inline');
    }

    /** Descarga directa desde Google Drive. */
    public function descargar(SesionDocumento $documento)
    {
        if ($documento->drive_file_id) {
            return redirect()->away(GoogleDrive::urlDescarga($documento->drive_file_id, $documento->mime));
        }

        return $this->copiaLocal($documento, 'attachment');
    }

    /** Edita la descripción o mueve el documento a otro tipo (p. ej. de "acta" a "asistencia"). */
    public function update(Request $request, SesionDocumento $documento)
    {
        $data = $request->validate([
            'descripcion' => ['nullable', 'string', 'max:255'],
            'tipo' => ['sometimes', Rule::in(SesionDocumento::TIPOS)],
        ]);
        $antes = $documento->only(['tipo', 'descripcion']);
        $documento->update($data);
        $sesion = $documento->sesion;

        if ($antes['tipo'] !== $documento->tipo) {
            Auditor::registrar('documento.movido', sprintf('"%s" movido de %s a %s en %s', $documento->nombre_original,
                SesionDocumento::ETIQUETAS[$antes['tipo']], SesionDocumento::ETIQUETAS[$documento->tipo], Auditor::etiquetaSesion($sesion)),
                $documento, ['archivo' => $documento->nombre_original, 'de' => $antes['tipo'], 'a' => $documento->tipo]);
        }
        if (array_key_exists('descripcion', $data) && $antes['descripcion'] !== $documento->descripcion) {
            Auditor::registrar('documento.editado', sprintf('Descripción de "%s" modificada', $documento->nombre_original),
                $documento, ['antes' => $antes['descripcion'], 'despues' => $documento->descripcion]);
        }

        return $documento;
    }

    /**
     * Quita el documento de la sesión enviando el archivo a la papelera de Drive
     * (recuperable 30 días). Como la lista se lee de Drive, solo desvincularlo haría
     * que reapareciera en la siguiente lectura.
     */
    public function destroy(SesionDocumento $documento, GoogleDrive $drive)
    {
        if ($documento->drive_file_id) {
            $drive->enviarAPapelera($documento->drive_file_id);
        }
        $documento->delete();

        Auditor::registrar('documento.eliminado', sprintf('"%s" (%s) enviado a la papelera de Drive desde %s', $documento->nombre_original,
            SesionDocumento::ETIQUETAS[$documento->tipo], Auditor::etiquetaSesion($documento->sesion)), $documento, [
                'archivo' => $documento->nombre_original,
                'tipo' => $documento->tipo,
                'registrado_por' => $documento->registrado_por,
                'drive' => $documento->drive_url,
            ]);

        return response()->noContent();
    }

    /** Documentos cargados antes de usar solo Drive y que aún tienen copia en el servidor. */
    private function copiaLocal(SesionDocumento $documento, string $disposicion)
    {
        abort_unless($documento->ruta && Storage::disk(SesionDocumento::DISCO)->exists($documento->ruta), 404, 'El archivo no está disponible.');

        return Storage::disk(SesionDocumento::DISCO)->response(
            $documento->ruta,
            $documento->nombre_original,
            ['Content-Type' => $documento->mime ?? 'application/octet-stream'],
            $disposicion
        );
    }
}

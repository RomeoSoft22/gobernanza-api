<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['datos' => 'array', 'created_at' => 'datetime'];

    /** Etiquetas legibles de cada acción (también las usa el filtro del front-end). */
    public const ACCIONES = [
        'sesion.creada' => 'Sesión registrada',
        'sesion.actualizada' => 'Sesión editada',
        'sesion.eliminada' => 'Sesión eliminada',
        'anio.creado' => 'Año creado',
        'documento.subido' => 'Documento subido',
        'documento.movido' => 'Documento movido de tipo',
        'documento.editado' => 'Documento editado',
        'documento.eliminado' => 'Documento enviado a la papelera',
        'documento.sincronizado' => 'Cambios leídos de Drive',
        'drive.conectado' => 'Google Drive conectado',
        'drive.desconectado' => 'Google Drive desconectado',
        'drive.carpeta_asignada' => 'Carpeta de Drive asignada',
        'drive.carpeta_quitada' => 'Carpeta de Drive quitada',
        'drive.importacion_iniciada' => 'Importación iniciada',
        'drive.importacion_terminada' => 'Importación terminada',
        'drive.importacion_error' => 'Importación con error',
    ];

    public function scopeFiltrar(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($f['accion'] ?? null, fn ($q, $v) => $q->where('accion', $v))
            ->when($f['sesion_id'] ?? null, fn ($q, $v) => $q->where('sesion_id', $v))
            ->when($f['usuario'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q
                ->where('usuario_nombre', 'like', "%{$v}%")
                ->orWhere('usuario_correo', 'like', "%{$v}%")))
            ->when($f['buscar'] ?? null, fn ($q, $v) => $q->where('descripcion', 'like', "%{$v}%"));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SesionDocumento extends Model
{
    protected $table = 'sesion_documentos';

    public const TIPOS = ['acta', 'asistencia', 'presentacion', 'adjunto'];

    /** Tipos que admiten un solo archivo vigente (al subir otro, se reemplaza). */
    public const TIPOS_UNICOS = ['acta', 'asistencia', 'presentacion'];

    public const DISCO = 'local';

    protected $fillable = ['sesion_id', 'tipo', 'nombre_original', 'ruta', 'mime', 'tamano', 'descripcion'];

    protected $hidden = ['ruta'];

    protected $casts = ['tamano' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(fn (SesionDocumento $doc) => Storage::disk(self::DISCO)->delete($doc->ruta));
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class);
    }
}

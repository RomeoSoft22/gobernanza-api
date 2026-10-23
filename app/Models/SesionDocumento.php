<?php

namespace App\Models;

use App\Services\GoogleDrive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SesionDocumento extends Model
{
    protected $table = 'sesion_documentos';

    public const TIPOS = ['acta', 'asistencia', 'presentacion', 'adjunto'];

    public const DISCO = 'local';

    protected $fillable = [
        'sesion_id', 'tipo', 'nombre_original', 'ruta', 'mime', 'tamano', 'descripcion',
        'drive_file_id', 'drive_url', 'drive_modificado_at', 'registrado_por',
    ];

    /** Nombre legible de cada tipo (para la auditoría). */
    public const ETIQUETAS = [
        'acta' => 'Acta', 'asistencia' => 'Lista de asistencia', 'presentacion' => 'Presentación', 'adjunto' => 'Adjunto',
    ];

    protected $hidden = ['ruta'];

    protected $appends = ['url_descarga'];

    protected $casts = ['tamano' => 'integer', 'drive_modificado_at' => 'datetime'];

    protected static function booted(): void
    {
        // Copias antiguas del servidor (antes de usar solo Drive). El archivo en Drive no se toca.
        static::deleting(function (SesionDocumento $doc) {
            if ($doc->ruta) {
                Storage::disk(self::DISCO)->delete($doc->ruta);
            }
        });
    }

    /** Descarga directa desde Drive (el navegador la abre sin pasar por la API). */
    public function getUrlDescargaAttribute(): ?string
    {
        return $this->drive_file_id ? GoogleDrive::urlDescarga($this->drive_file_id, $this->mime) : null;
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class);
    }

    /** Sugiere el tipo de documento a partir del nombre del archivo. */
    public static function clasificar(string $nombre): string
    {
        $n = mb_strtolower($nombre);

        return match (true) {
            str_contains($n, 'acta') => 'acta',
            (bool) preg_match('/asistencia|asistentes|participantes|lista/u', $n) => 'asistencia',
            (bool) preg_match('/presentaci|diapositiva|\.pptx?$|exposici/u', $n) => 'presentacion',
            default => 'adjunto',
        };
    }
}

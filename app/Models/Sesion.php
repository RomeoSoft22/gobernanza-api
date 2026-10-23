<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sesion extends Model
{
    use SoftDeletes;

    protected $table = 'sesiones';

    /** Documentos que se esperan en toda sesión realizada. */
    public const DOCUMENTOS_REQUERIDOS = ['acta', 'asistencia', 'presentacion'];

    protected $fillable = [
        'tipo_reunion_id', 'clase', 'numero', 'anio', 'fecha', 'hora_inicio', 'hora_fin',
        'modalidad', 'lugar', 'estado', 'agenda', 'acuerdos', 'observaciones',
        'drive_folder_id', 'drive_folder_url', 'drive_sincronizado_at',
    ];

    public const CLASES = ['ordinaria', 'extraordinaria'];

    /** Carpetas de Drive con el formato "2026.10.19 - 72va Reunión [Extraordinaria]". */
    public const PATRON_CARPETA = '/^(\d{4})\.(\d{2})\.(\d{2})\s*-\s*(\d+)/u';

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'numero' => 'integer',
        'anio' => 'integer',
        'drive_sincronizado_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // El año siempre se deriva de la fecha de la sesión.
        static::saving(function (Sesion $sesion) {
            if ($sesion->fecha) {
                $sesion->anio = (int) $sesion->fecha->format('Y');
            }
        });
    }

    /** Nombre de la carpeta de la sesión en Drive, según la convención del equipo. */
    public function nombreCarpetaDrive(): string
    {
        $nombre = sprintf('%s - %dva Reunión', $this->fecha->format('Y.m.d'), $this->numero);

        return $this->clase === 'extraordinaria' ? $nombre.' Extraordinaria' : $nombre;
    }

    public function tipoReunion(): BelongsTo
    {
        return $this->belongsTo(TipoReunion::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(SesionDocumento::class);
    }

    /** Aplica los filtros de búsqueda recibidos desde el listado. */
    public function scopeFiltrar(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['tipo_reunion_id'] ?? null, fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->when($f['clase'] ?? null, fn ($q, $v) => $q->where('clase', $v))
            ->when($f['anio'] ?? null, fn ($q, $v) => $q->where('anio', $v))
            ->when($f['numero'] ?? null, fn ($q, $v) => $q->where('numero', $v))
            ->when($f['numero_desde'] ?? null, fn ($q, $v) => $q->where('numero', '>=', $v))
            ->when($f['numero_hasta'] ?? null, fn ($q, $v) => $q->where('numero', '<=', $v))
            ->when($f['fecha_desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($f['fecha_hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when($f['mes'] ?? null, fn ($q, $v) => $q->whereMonth('fecha', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['modalidad'] ?? null, fn ($q, $v) => $q->where('modalidad', $v))
            ->when($f['buscar'] ?? null, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $like = '%'.$v.'%';
                    $q->where('agenda', 'like', $like)
                        ->orWhere('acuerdos', 'like', $like)
                        ->orWhere('observaciones', 'like', $like)
                        ->orWhere('lugar', 'like', $like)
                        ->orWhereHas('documentos', fn ($d) => $d->where('nombre_original', 'like', $like)
                            ->orWhere('descripcion', 'like', $like));
                });
            })
            ->when($f['documentacion'] ?? null, function ($q, $v) {
                match ($v) {
                    'completa' => collect(self::DOCUMENTOS_REQUERIDOS)
                        ->each(fn ($t) => $q->whereHas('documentos', fn ($d) => $d->where('tipo', $t))),
                    'incompleta' => $q->where(function ($q) {
                        foreach (self::DOCUMENTOS_REQUERIDOS as $t) {
                            $q->orWhereDoesntHave('documentos', fn ($d) => $d->where('tipo', $t));
                        }
                    }),
                    'sin_acta' => $q->whereDoesntHave('documentos', fn ($d) => $d->where('tipo', 'acta')),
                    'sin_asistencia' => $q->whereDoesntHave('documentos', fn ($d) => $d->where('tipo', 'asistencia')),
                    'sin_presentacion' => $q->whereDoesntHave('documentos', fn ($d) => $d->where('tipo', 'presentacion')),
                    'con_adjuntos' => $q->whereHas('documentos', fn ($d) => $d->where('tipo', 'adjunto')),
                    default => null,
                };
            });
    }

    /** Agrega contadores por tipo de documento para mostrar indicadores en el listado. */
    public function scopeConContadores(Builder $q): Builder
    {
        return $q->withCount([
            'documentos as acta_count' => fn ($d) => $d->where('tipo', 'acta'),
            'documentos as asistencia_count' => fn ($d) => $d->where('tipo', 'asistencia'),
            'documentos as presentacion_count' => fn ($d) => $d->where('tipo', 'presentacion'),
            'documentos as adjuntos_count' => fn ($d) => $d->where('tipo', 'adjunto'),
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriveCarpeta extends Model
{
    protected $table = 'drive_carpetas';

    protected $fillable = ['tipo_reunion_id', 'clase', 'anio', 'folder_id', 'nombre', 'url'];

    protected $casts = ['anio' => 'integer'];

    public function tipoReunion(): BelongsTo
    {
        return $this->belongsTo(TipoReunion::class);
    }

    public static function raiz(int $tipoId, string $clase): ?self
    {
        return self::where(['tipo_reunion_id' => $tipoId, 'clase' => $clase])->whereNull('anio')->first();
    }

    public static function delAnio(int $tipoId, string $clase, int $anio): ?self
    {
        return self::where(['tipo_reunion_id' => $tipoId, 'clase' => $clase, 'anio' => $anio])->first();
    }
}

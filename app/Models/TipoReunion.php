<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoReunion extends Model
{
    protected $table = 'tipos_reunion';

    protected $fillable = ['codigo', 'slug', 'nombre', 'base_legal', 'periodicidad', 'descripcion', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class);
    }

    public function carpetasDrive(): HasMany
    {
        return $this->hasMany(DriveCarpeta::class);
    }
}

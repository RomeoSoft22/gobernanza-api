<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoReunion extends Model
{
    protected $table = 'tipos_reunion';

    protected $fillable = ['codigo', 'nombre', 'base_legal', 'periodicidad', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cuenta de Google autorizada por OAuth. Los tokens se guardan cifrados con APP_KEY. */
class DriveConexion extends Model
{
    protected $table = 'drive_conexiones';

    protected $fillable = ['email', 'access_token', 'refresh_token', 'expira_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expira_at' => 'datetime',
    ];

    public static function actual(): ?self
    {
        return self::latest('id')->first();
    }
}

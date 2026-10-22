<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoReunion;

class TipoReunionController extends Controller
{
    public function index()
    {
        return TipoReunion::where('activo', true)->orderBy('nombre')->get();
    }
}

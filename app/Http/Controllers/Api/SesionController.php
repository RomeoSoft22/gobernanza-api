<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SesionRequest;
use App\Models\Sesion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SesionController extends Controller
{
    private const ORDENES = ['numero', 'fecha', 'estado', 'created_at'];

    public function index(Request $request)
    {
        $f = $request->validate([
            'tipo_reunion_id' => ['nullable', 'integer'],
            'anio' => ['nullable', 'integer'],
            'numero' => ['nullable', 'integer'],
            'numero_desde' => ['nullable', 'integer'],
            'numero_hasta' => ['nullable', 'integer'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'estado' => ['nullable', Rule::in(['programada', 'realizada', 'reprogramada', 'suspendida'])],
            'modalidad' => ['nullable', Rule::in(['presencial', 'virtual', 'mixta'])],
            'documentacion' => ['nullable', Rule::in(['completa', 'incompleta', 'sin_acta', 'sin_asistencia', 'sin_presentacion', 'con_adjuntos'])],
            'buscar' => ['nullable', 'string', 'max:100'],
            'orden' => ['nullable', Rule::in(self::ORDENES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'between:5,100'],
        ]);

        return Sesion::query()
            ->with('tipoReunion:id,codigo,nombre')
            ->conContadores()
            ->filtrar($f)
            ->orderBy($f['orden'] ?? 'numero', $f['direccion'] ?? 'desc')
            ->paginate($f['por_pagina'] ?? 15);
    }

    /** Años con sesiones registradas (para las pestañas por año). */
    public function anios(Request $request)
    {
        return Sesion::query()
            ->when($request->integer('tipo_reunion_id'), fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->selectRaw('anio, COUNT(*) as total, MIN(numero) as desde, MAX(numero) as hasta')
            ->groupBy('anio')
            ->orderByDesc('anio')
            ->get();
    }

    /** Indicadores para el tablero del módulo. */
    public function resumen(Request $request)
    {
        $base = Sesion::query()
            ->when($request->integer('tipo_reunion_id'), fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->when($request->integer('anio'), fn ($q, $v) => $q->where('anio', $v));

        $porEstado = (clone $base)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $realizadas = (clone $base)->where('estado', 'realizada');

        return [
            'total' => (clone $base)->count(),
            'por_estado' => $porEstado,
            'realizadas_sin_acta' => (clone $realizadas)->filtrar(['documentacion' => 'sin_acta'])->count(),
            'realizadas_incompletas' => (clone $realizadas)->filtrar(['documentacion' => 'incompleta'])->count(),
            'ultima' => (clone $base)->with('tipoReunion:id,nombre')->orderByDesc('numero')->first(),
            'proxima' => (clone $base)->where('estado', 'programada')->whereDate('fecha', '>=', now()->toDateString())
                ->orderBy('fecha')->first(),
        ];
    }

    public function siguienteNumero(Request $request)
    {
        $request->validate(['tipo_reunion_id' => ['required', 'integer']]);

        $max = Sesion::withTrashed()->where('tipo_reunion_id', $request->integer('tipo_reunion_id'))->max('numero');

        return ['numero' => ((int) $max) + 1];
    }

    public function store(SesionRequest $request)
    {
        $sesion = Sesion::create($request->validated());

        return response()->json($this->cargar($sesion), 201);
    }

    public function show(Sesion $sesion)
    {
        return $this->cargar($sesion);
    }

    public function update(SesionRequest $request, Sesion $sesion)
    {
        $sesion->update($request->validated());

        return $this->cargar($sesion);
    }

    public function destroy(Sesion $sesion)
    {
        $sesion->delete();

        return response()->noContent();
    }

    private function cargar(Sesion $sesion): Sesion
    {
        return $sesion->load([
            'tipoReunion',
            'documentos' => fn ($q) => $q->orderBy('tipo')->orderByDesc('created_at'),
        ]);
    }
}

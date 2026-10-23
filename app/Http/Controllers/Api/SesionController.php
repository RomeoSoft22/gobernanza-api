<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SesionRequest;
use App\Models\DriveCarpeta;
use App\Models\Sesion;
use App\Models\TipoReunion;
use App\Services\DriveSincronizador;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class SesionController extends Controller
{
    private const ORDENES = ['numero', 'fecha', 'estado', 'created_at'];

    public function __construct(private DriveSincronizador $drive) {}

    public function index(Request $request)
    {
        $f = $request->validate([
            'tipo_reunion_id' => ['nullable', 'integer'],
            'clase' => ['nullable', Rule::in(Sesion::CLASES)],
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

        $dir = $f['direccion'] ?? 'desc';

        return Sesion::query()
            ->with('tipoReunion:id,codigo,slug,nombre')
            ->conContadores()
            ->filtrar($f)
            ->orderBy($f['orden'] ?? 'fecha', $dir)
            ->orderBy('numero', $dir)
            ->paginate($f['por_pagina'] ?? 15);
    }

    /** Años disponibles: los que tienen sesiones y los creados en Drive aunque aún estén vacíos. */
    public function anios(Request $request)
    {
        $tipoId = $request->integer('tipo_reunion_id') ?: null;

        $conSesiones = Sesion::query()
            ->when($tipoId, fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->selectRaw('anio, COUNT(*) as total')
            ->selectRaw("SUM(clase = 'ordinaria') as ordinarias, SUM(clase = 'extraordinaria') as extraordinarias")
            ->groupBy('anio')
            ->get()
            ->keyBy('anio');

        $creados = DriveCarpeta::whereNotNull('anio')
            ->when($tipoId, fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->pluck('anio');

        return $conSesiones->keys()->merge($creados)->unique()->sortDesc()->values()
            ->map(fn ($anio) => [
                'anio' => (int) $anio,
                'total' => (int) ($conSesiones[$anio]->total ?? 0),
                'ordinarias' => (int) ($conSesiones[$anio]->ordinarias ?? 0),
                'extraordinarias' => (int) ($conSesiones[$anio]->extraordinarias ?? 0),
            ]);
    }

    /** Crea el año: carpetas "AAAA" en Drive para las reuniones ordinarias y extraordinarias. */
    public function crearAnio(Request $request)
    {
        $data = $request->validate([
            'tipo_reunion_id' => ['required', 'exists:tipos_reunion,id'],
            'anio' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $carpetas = $this->drive->crearAnio(TipoReunion::findOrFail($data['tipo_reunion_id']), $data['anio']);

        return response()->json($carpetas, 201);
    }

    public function resumen(Request $request)
    {
        $base = Sesion::query()
            ->when($request->integer('tipo_reunion_id'), fn ($q, $v) => $q->where('tipo_reunion_id', $v))
            ->when($request->input('clase'), fn ($q, $v) => $q->where('clase', $v))
            ->when($request->integer('anio'), fn ($q, $v) => $q->where('anio', $v));

        $realizadas = (clone $base)->where('estado', 'realizada');

        return [
            'total' => (clone $base)->count(),
            'por_estado' => (clone $base)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado'),
            'por_clase' => (clone $base)->selectRaw('clase, COUNT(*) as total')->groupBy('clase')->pluck('total', 'clase'),
            'realizadas_sin_acta' => (clone $realizadas)->filtrar(['documentacion' => 'sin_acta'])->count(),
            'realizadas_incompletas' => (clone $realizadas)->filtrar(['documentacion' => 'incompleta'])->count(),
            'ultima' => (clone $base)->orderByDesc('fecha')->orderByDesc('numero')->first(),
            'proxima' => (clone $base)->where('estado', 'programada')->whereDate('fecha', '>=', now()->toDateString())
                ->orderBy('fecha')->first(),
        ];
    }

    public function siguienteNumero(Request $request)
    {
        $data = $request->validate([
            'tipo_reunion_id' => ['required', 'integer'],
            'clase' => ['required', Rule::in(Sesion::CLASES)],
            'anio' => ['nullable', 'integer'],
        ]);

        // Ordinarias: correlativo continuo. Extraordinarias: reinician cada año.
        $max = Sesion::withTrashed()
            ->where(['tipo_reunion_id' => $data['tipo_reunion_id'], 'clase' => $data['clase']])
            ->when($data['clase'] === 'extraordinaria', fn ($q) => $q->where('anio', $data['anio'] ?? now()->year))
            ->max('numero');

        return ['numero' => ((int) $max) + 1];
    }

    public function store(SesionRequest $request)
    {
        $sesion = Sesion::create($request->validated());

        return response()->json($this->conCarpetaDrive($sesion), 201);
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

    /** Crea la carpeta de la sesión en Drive si está conectado; un fallo no impide registrar la sesión. */
    private function conCarpetaDrive(Sesion $sesion): array
    {
        $aviso = null;
        if ($this->drive->disponible() && DriveCarpeta::raiz($sesion->tipo_reunion_id, $sesion->clase)) {
            try {
                $this->drive->carpetaSesion($sesion);
            } catch (Throwable $e) {
                report($e);
                $aviso = 'La sesión se registró, pero no se pudo crear su carpeta en Drive: '.$e->getMessage();
            }
        }

        return $this->cargar($sesion->refresh())->toArray() + ['aviso_drive' => $aviso];
    }

    private function cargar(Sesion $sesion): Sesion
    {
        return $sesion->load([
            'tipoReunion',
            'documentos' => fn ($q) => $q->orderBy('tipo')->orderByDesc('created_at'),
        ]);
    }
}

<?php

namespace App\Support;

use App\Models\Auditoria;
use App\Models\Sesion;
use App\Models\SesionDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registra en la tabla auditoria quién hizo cada movimiento. El usuario sale del JWT de identidad
 * de la petición; en procesos sin petición (importación en segundo plano) se fija con comoUsuario().
 */
class Auditor
{
    /** Usuario para procesos sin petición HTTP (p. ej. la importación de Drive). */
    private static ?array $usuarioFijo = null;

    /** Prefijo de la acción → módulo (filtro de la pantalla de auditoría). */
    public const MODULOS = ['sesion' => 'sesiones', 'documento' => 'documentos', 'anio' => 'sesiones', 'drive' => 'drive'];

    /** Campos de la sesión que vale la pena mostrar en el "antes / después". */
    private const CAMPOS_SESION = [
        'clase', 'numero', 'fecha', 'hora_inicio', 'hora_fin', 'modalidad', 'lugar', 'estado',
        'agenda', 'acuerdos', 'observaciones',
    ];

    public static function comoUsuario(?array $usuario): void
    {
        self::$usuarioFijo = $usuario;
    }

    /** Usuario actual como {sub, nombre, correo, roles}. */
    public static function usuario(): array
    {
        if (self::$usuarioFijo) {
            return self::$usuarioFijo;
        }

        $claims = app()->bound('request') ? request()->attributes->get('identidad') : null;
        if (! $claims) {
            return ['sub' => null, 'nombre' => 'Sistema', 'correo' => null, 'roles' => []];
        }

        return [
            'sub' => $claims['sub'] ?? null,
            'nombre' => $claims['name'] ?? ($claims['email'] ?? 'Usuario'),
            'correo' => $claims['email'] ?? null,
            'roles' => (array) ($claims['roles'] ?? []),
        ];
    }

    /** Nombre para las columnas "registrado_por". */
    public static function nombre(): string
    {
        $u = self::usuario();

        return Str::limit($u['nombre'] ?? $u['correo'] ?? 'Sistema', 145, '');
    }

    /**
     * Guarda un evento. Nunca interrumpe la operación del usuario: si la auditoría falla,
     * queda en el log del servidor.
     */
    public static function registrar(string $accion, string $descripcion, ?Model $entidad = null, array $datos = []): void
    {
        try {
            $u = self::usuario();
            // En procesos de consola no hay navegador ni IP que registrar.
            $peticion = app()->bound('request') && request()->attributes->has('identidad') ? request() : null;

            Auditoria::create([
                'usuario_sub' => $u['sub'],
                'usuario_nombre' => $u['nombre'],
                'usuario_correo' => $u['correo'],
                'usuario_roles' => implode(',', $u['roles'] ?? []),
                'modulo' => self::MODULOS[Str::before($accion, '.')] ?? 'otros',
                'accion' => $accion,
                'entidad_tipo' => $entidad ? Str::snake(class_basename($entidad)) : null,
                'entidad_id' => $entidad?->getKey(),
                'sesion_id' => match (true) {
                    $entidad instanceof Sesion => $entidad->id,
                    $entidad instanceof SesionDocumento => $entidad->sesion_id,
                    default => $datos['sesion_id'] ?? null,
                },
                'descripcion' => Str::limit($descripcion, 495),
                'datos' => $datos ?: null,
                'ip' => $peticion?->ip(),
                'navegador' => $peticion ? Str::limit((string) $peticion->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            Log::error('No se pudo registrar la auditoría', ['accion' => $accion, 'error' => $e->getMessage()]);
        }
    }

    /** "Sesión N° 72 ordinaria (Seguimiento Operativo, 19/10/2026)". */
    public static function etiquetaSesion(Sesion $s): string
    {
        $s->loadMissing('tipoReunion');

        return sprintf('Sesión N° %d %s (%s, %s)', $s->numero, $s->clase, $s->tipoReunion?->nombre ?? 'reunión', $s->fecha?->format('d/m/Y'));
    }

    /** Diferencias entre los valores anteriores y los nuevos de una sesión. */
    public static function cambiosSesion(array $antes, Sesion $despues): array
    {
        $cambios = [];
        foreach (self::CAMPOS_SESION as $campo) {
            $a = self::normalizar($antes[$campo] ?? null);
            $d = self::normalizar($despues->getAttribute($campo));
            if ($a !== $d) {
                $cambios[$campo] = ['antes' => $a, 'despues' => $d];
            }
        }

        return $cambios;
    }

    private static function normalizar(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if ($v === null || $v === '') {
            return null;
        }
        $v = (string) $v;

        // "09:00:00" y "09:00" son la misma hora
        return preg_match('/^\d{2}:\d{2}:00$/', $v) ? substr($v, 0, 5) : $v;
    }
}

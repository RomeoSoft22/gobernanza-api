<?php

namespace App\Support;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Cliente de identidad.juarezcampos.net: valida los JWT RS256 con la llave pública (JWKS)
 * y canjea códigos y refresh tokens. Gobernanza no guarda usuarios: confía en los claims del token.
 */
class Identidad
{
    private const CACHE_JWKS = 'identidad.jwks';

    public static function config(string $key): mixed
    {
        return config('services.identidad.'.$key);
    }

    /**
     * Verifica firma, vencimiento, emisor y audiencia. Devuelve los claims.
     *
     * @throws UnexpectedValueException
     */
    public static function verificar(string $jwt): array
    {
        JWT::$leeway = 30;

        try {
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(self::jwks(), 'RS256'));
        } catch (UnexpectedValueException $e) {
            // Llave desconocida: puede que identidad haya rotado su llave. Se recarga el JWKS una vez.
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(self::jwks(true), 'RS256'));
        }

        if (($claims['iss'] ?? null) !== self::config('issuer')) {
            throw new UnexpectedValueException('Emisor inválido.');
        }
        if (! in_array(self::config('audience'), (array) ($claims['aud'] ?? []), true)) {
            throw new UnexpectedValueException('El token no es para este sistema.');
        }
        if (empty($claims['sub'])) {
            throw new UnexpectedValueException('Token sin sujeto.');
        }

        return $claims;
    }

    /** ¿Alguno de los roles del token permite usar el sistema? */
    public static function tieneAcceso(array $claims): bool
    {
        return array_intersect((array) ($claims['roles'] ?? []), (array) self::config('roles')) !== [];
    }

    public static function jwks(bool $recargar = false): array
    {
        if ($recargar) {
            Cache::forget(self::CACHE_JWKS);
        }

        return Cache::remember(self::CACHE_JWKS, 3600, fn () => Http::timeout(10)
            ->get(self::config('api_url').'/jwks.json')
            ->throw()
            ->json());
    }

    public static function canjearCodigo(string $code, string $verifier): Response
    {
        return self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => self::config('redirect_uri'),
        ]);
    }

    public static function renovar(string $refreshToken): Response
    {
        return self::token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private static function token(array $datos): Response
    {
        return Http::asForm()->acceptJson()->timeout(15)->post(self::config('api_url').'/oauth/token', $datos + [
            'client_id' => self::config('client_id'),
            'client_secret' => self::config('client_secret'),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsuarioRecurso;
use Illuminate\Support\Facades\Auth;

/**
 * Habilitación de recursos (secciones del menú) por usuario.
 *
 * Los usuarios viven en el ERP y los permisos en la tabla local
 * `usuario_recursos`. Una fila manda sobre el default; sin fila, los
 * recursos marcados como "restringidos" (Usuarios y Control de
 * Diferencias) sólo los tienen los administradores del `SIS_ADMINS`.
 */
class ControlAcceso
{
    /**
     * Catálogo de recursos: [clave => ['titulo' => ..., 'rutas' => [...]]].
     *
     * @return array<string, array{titulo: string, rutas: array<int, string>}>
     */
    public static function catalogo(): array
    {
        return config('recursos.catalogo', []);
    }

    /** Título del recurso para avisos y menú. */
    public static function titulo(string $recurso): string
    {
        return self::catalogo()[$recurso]['titulo'] ?? $recurso;
    }

    /** ¿Es administrador por el .env (SIS_ADMINS)? */
    public static function esAdmin(?User $usuario): bool
    {
        if (! $usuario) {
            return false;
        }

        return in_array(trim((string) $usuario->sisusrcod), self::admins(), true);
    }

    /**
     * Códigos de administrador (SIS_ADMINS).
     *
     * @return array<int, string>
     */
    public static function admins(): array
    {
        return config('recursos.admins', []);
    }

    /** ¿El recurso está restringido por defecto (sólo SIS_ADMINS)? */
    public static function esRestringido(string $recurso): bool
    {
        return (bool) (self::catalogo()[$recurso]['restringido'] ?? false);
    }

    /**
     * Estado de un recurso sin fila en `usuario_recursos`: los
     * restringidos (Usuarios, Control de Diferencias) sólo los tienen los
     * administradores del .env; el resto está habilitado para todos.
     */
    public static function porDefecto(string $sisusrcod, string $recurso): bool
    {
        if (self::esRestringido($recurso)) {
            return in_array(trim($sisusrcod), self::admins(), true);
        }

        return true;
    }

    /**
     * ¿Tiene el usuario el recurso habilitado?
     *
     * Una fila en `usuario_recursos` manda sobre el valor por defecto
     * (tanto para habilitar como para bloquear, aunque el usuario sea
     * admin). Sin fila, vale el default del recurso.
     */
    public static function habilitado(?User $usuario, string $recurso): bool
    {
        if (! $usuario) {
            return false;
        }

        $guardado = UsuarioRecurso::query()
            ->where('sisusrcod', $usuario->sisusrcod)
            ->where('recurso', $recurso)
            ->value('habilitado');

        if ($guardado !== null) {
            return (bool) $guardado;
        }

        return self::porDefecto((string) $usuario->sisusrcod, $recurso);
    }

    /** ¿Puede entrar a la sección Usuarios? */
    public static function puedeGestionarUsuarios(?User $usuario): bool
    {
        return self::habilitado($usuario, 'usuarios');
    }

    /**
     * Recurso al que pertenece una ruta del panel (o null si no pertenece
     * a ninguno: login, perfil, assets, etc.).
     *
     * Se comparan las rutas de más larga a más corta para que '/admin'
     * sólo matchee la raíz exacta y no tape al resto.
     */
    public static function recursoDeRuta(string $ruta): ?string
    {
        $ruta = '/'.trim($ruta, '/');

        $pares = [];

        foreach (self::catalogo() as $clave => $recurso) {
            foreach ($recurso['rutas'] as $camino) {
                $pares[] = [$camino, $clave];
            }
        }

        // Las rutas más largas primero: así '/admin' sólo matchea la raíz
        // exacta y no se come a /admin/inventarios, /admin/login, etc.
        usort($pares, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

        foreach ($pares as [$camino, $clave]) {
            if ($camino === '/admin') {
                if ($ruta === '/admin') {
                    return $clave;
                }

                continue;
            }

            if ($ruta === $camino || str_starts_with($ruta, $camino.'/')) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * Primera URL habilitada para el usuario, para redirigir cuando se
     * le bloquea el recurso al que intentaba entrar. null si no tiene
     * ninguno.
     */
    public static function primeraHabilitada(?User $usuario): ?string
    {
        foreach (self::catalogo() as $clave => $recurso) {
            if (! self::habilitado($usuario, $clave)) {
                continue;
            }

            $camino = $recurso['rutas'][0] ?? null;

            if ($camino !== null) {
                return $camino === '/admin' ? '/admin' : $camino;
            }
        }

        return null;
    }

    /** Usuario logueado (o null). */
    public static function usuarioActual(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }
}

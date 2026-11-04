<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsuarioRecurso;
use Illuminate\Support\Facades\Auth;

/**
 * Habilitación de recursos (secciones del menú) por usuario.
 *
 * Los usuarios viven en el ERP y los permisos en la tabla local
 * `usuario_recursos`: sin fila, el recurso está habilitado. La sección
 * Usuarios exige además figurar en el `SIS_ADMINS` del .env.
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

        return in_array((string) $usuario->sisusrcod, self::admins(), true);
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

    /**
     * ¿Tiene el usuario el recurso habilitado?
     *
     * Sin fila en `usuario_recursos` está habilitado. El recurso
     * `usuarios` exige además ser admin por .env.
     */
    public static function habilitado(?User $usuario, string $recurso): bool
    {
        if (! $usuario) {
            return false;
        }

        $habilitado = UsuarioRecurso::query()
            ->where('sisusrcod', $usuario->sisusrcod)
            ->where('recurso', $recurso)
            ->value('habilitado');

        // null = sin fila = habilitado por defecto.
        if ($habilitado !== null && ! (bool) $habilitado) {
            return false;
        }

        if ($recurso === 'usuarios') {
            return self::esAdmin($usuario);
        }

        return true;
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

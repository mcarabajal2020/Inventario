<?php

namespace App\Http\Middleware;

use App\Services\ControlAcceso;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a las rutas de un recurso que el usuario no tiene
 * habilitado (la navegación se oculta aparte, en shouldRegisterNavigation).
 *
 * Si el recurso bloqueado es al que se intenta entrar, se redirige a la
 * primera sección habilitada; si no tiene ninguna, responde 403.
 */
class EnsureRecursoHabilitado
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = ControlAcceso::usuarioActual();

        if ($usuario) {
            $recurso = ControlAcceso::recursoDeRuta($request->path());

            if ($recurso !== null && ! ControlAcceso::habilitado($usuario, $recurso)) {
                $destino = ControlAcceso::primeraHabilitada($usuario);

                Notification::make()
                    ->title('Recurso bloqueado')
                    ->body('No tiene habilitado el recurso "'.ControlAcceso::titulo($recurso).'".')
                    ->warning()
                    ->send();

                if ($destino !== null && $destino !== '/'.trim($request->path(), '/')) {
                    return redirect()->to($destino);
                }

                abort(403, 'No tiene ningún recurso habilitado. Contacte al administrador.');
            }
        }

        return $next($request);
    }
}

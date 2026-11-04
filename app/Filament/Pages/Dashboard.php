<?php

namespace App\Filament\Pages;

use App\Services\ControlAcceso;

/**
 * Dashboard propio para poder ocultarlo por usuario desde el apartado
 * Usuarios (el de Filament no se puede filtrar por recurso).
 */
class Dashboard extends \Filament\Pages\Dashboard
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && ControlAcceso::habilitado(ControlAcceso::usuarioActual(), 'dashboard');
    }
}

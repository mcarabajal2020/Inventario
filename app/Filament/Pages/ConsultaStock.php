<?php

namespace App\Filament\Pages;

use App\Services\SisApiClient;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ConsultaStock extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected string $view = 'filament.pages.consulta-stock';

    protected static ?string $navigationLabel = 'Consulta de Stock';

    protected static ?string $title = 'Consulta de Stock';

    protected static ?string $slug = 'consulta-stock';

    /** Código escaneado: código de barras o código del artículo. */
    public string $codigo = '';

    /** Artículo consultado: ['artcod' => ..., 'artdes' => ...]. */
    public ?array $articulo = null;

    /** Stock por depósito: una fila por depósito. */
    public array $saldos = [];

    public function consultar(): void
    {
        if (blank($this->codigo)) {
            $this->notificacion('Sin código', 'Escanee el código de barras o el código del artículo.', 'danger');

            return;
        }

        try {
            $saldos = $this->api()->stockPorArticulo($this->codigo);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (empty($saldos)) {
            $this->sinStock();

            return;
        }

        $this->articulo = [
            'artcod' => $saldos[0]['artcod'],
            'artdes' => $saldos[0]['artdes'],
        ];
        $this->saldos = $saldos;
        $this->codigo = '';

        $this->dispatch('focus-input');
    }

    /**
     * El ERP no devolvió stock: se busca el artículo igual para mostrar su
     * código y descripción (existe pero sin stock) o avisar que no existe.
     */
    protected function sinStock(): void
    {
        try {
            $articulos = $this->api()->buscarArticulo($this->codigo);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (empty($articulos)) {
            $this->limpiar();

            $this->notificacion('Código no encontrado', 'No existe el código de barras ni el artículo ingresado.', 'danger');

            return;
        }

        $this->articulo = [
            'artcod' => $articulos[0]['artcod'] ?? null,
            'artdes' => $articulos[0]['artdes'] ?? null,
        ];
        $this->saldos = [];
        $this->codigo = '';

        $this->notificacion('Sin stock', 'El artículo existe pero no tiene stock en ningún depósito.', 'warning');

        $this->dispatch('focus-input');
    }

    public function limpiar(): void
    {
        $this->reset('codigo', 'articulo', 'saldos');

        $this->dispatch('focus-input');
    }

    /** Total del artículo en todos los depósitos (se muestra en la vista). */
    public function total(): float
    {
        return round((float) collect($this->saldos)->sum('stock'), 2);
    }

    protected function api(): SisApiClient
    {
        return app(SisApiClient::class);
    }

    protected function notificacion(string $titulo, string $cuerpo, string $color): void
    {
        Notification::make()
            ->title($titulo)
            ->body($cuerpo)
            ->{$color}()
            ->send();
    }
}

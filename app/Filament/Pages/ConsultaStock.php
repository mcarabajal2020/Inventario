<?php

namespace App\Filament\Pages;

use App\Services\ControlAcceso;
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

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && ControlAcceso::habilitado(ControlAcceso::usuarioActual(), 'consulta-stock');
    }

    /** Código escaneado: código de barras o código del artículo. */
    public string $codigo = '';

    /** Artículo consultado: ['artcod' => ..., 'artdes' => ...]. */
    public ?array $articulo = null;

    /** Stock por depósito: una fila por depósito. */
    public array $saldos = [];

    /** Coincidencias cuando la búsqueda por descripción trae varios artículos. */
    public array $resultados = [];

    public function consultar(): void
    {
        if (blank($this->codigo)) {
            $this->notificacion('Sin código', 'Escanee el código de barras o el código del artículo.', 'danger');

            return;
        }

        $this->resultados = [];

        // Búsqueda por descripción: verificar si hay varias coincidencias.
        if (preg_match('/[A-Za-z]/', $this->codigo)) {
            try {
                $articulos = $this->api()->buscarArticulo($this->codigo);
            } catch (Exception) {
                $articulos = [];
            }

            if (count($articulos) > 1) {
                $this->resultados = $articulos;

                return;
            }
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

        $permitidos = $this->filtrarDepositos($saldos);

        if (empty($permitidos)) {
            $this->sinStockEnDepositos($saldos[0]);

            return;
        }

        $this->articulo = [
            'artcod' => $permitidos[0]['artcod'],
            'artdes' => $permitidos[0]['artdes'],
        ];
        $this->saldos = $permitidos;
        $this->codigo = '';

        $this->dispatch('focus-input');
    }

    /**
     * Muestra el stock del artículo elegido de la lista de coincidencias.
     */
    public function seleccionar(string $artcod): void
    {
        $articulo = collect($this->resultados)->firstWhere('artcod', $artcod);

        $this->resultados = [];

        if (! $articulo) {
            return;
        }

        $this->mostrarStock($artcod, (string) ($articulo['artdes'] ?? ''));
    }

    /**
     * Busca y muestra el stock de un artículo por su código exacto.
     */
    protected function mostrarStock(string $artcod, string $artdes): void
    {
        try {
            $saldos = $this->api()->stockPorArticulo($artcod);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (empty($saldos)) {
            $this->articulo = ['artcod' => $artcod, 'artdes' => $artdes];
            $this->saldos = [];
            $this->codigo = '';

            $this->notificacion('Sin stock', 'El artículo existe pero no tiene stock en ningún depósito.', 'warning');

            $this->dispatch('focus-input');

            return;
        }

        $permitidos = $this->filtrarDepositos($saldos);

        if (empty($permitidos)) {
            $this->articulo = ['artcod' => $artcod, 'artdes' => $artdes];
            $this->saldos = [];
            $this->codigo = '';

            $this->notificacion('Sin stock en sus depósitos', 'El artículo tiene stock, pero no en los depósitos habilitados para su usuario.', 'warning');

            $this->dispatch('focus-input');

            return;
        }

        $this->articulo = [
            'artcod' => $permitidos[0]['artcod'],
            'artdes' => $permitidos[0]['artdes'],
        ];
        $this->saldos = $permitidos;
        $this->codigo = '';

        $this->dispatch('focus-input');
    }

    /**
     * Sólo los depósitos habilitados para el usuario. Sin filas en
     * `usuario_depositos` se devuelven todos.
     */
    protected function filtrarDepositos(array $saldos): array
    {
        $permitidos = ControlAcceso::depositosPermitidos(ControlAcceso::usuarioActual());

        if ($permitidos === null) {
            return $saldos;
        }

        return array_values(array_filter(
            $saldos,
            fn ($saldo) => in_array(trim((string) ($saldo['depcod'] ?? '')), $permitidos, true)
        ));
    }

    /**
     * El artículo tiene stock sólo en depósitos que el usuario no ve:
     * se muestra el artículo sin saldos y el aviso correspondiente.
     */
    protected function sinStockEnDepositos(array $fila): void
    {
        $this->articulo = [
            'artcod' => $fila['artcod'] ?? null,
            'artdes' => $fila['artdes'] ?? null,
        ];
        $this->saldos = [];
        $this->codigo = '';

        $this->notificacion(
            'Sin stock en sus depósitos',
            'El artículo tiene stock, pero no en los depósitos habilitados para su usuario.',
            'warning'
        );

        $this->dispatch('focus-input');
    }

    public function descartarResultados(): void
    {
        $this->resultados = [];
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
        $this->reset('codigo', 'articulo', 'saldos', 'resultados');

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

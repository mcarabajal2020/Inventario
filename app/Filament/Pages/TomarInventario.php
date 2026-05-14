<?php

namespace App\Filament\Pages;

use App\Models\InventarioMovimiento;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class TomarInventario extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected string $view = 'filament.pages.tomar-inventario';

    protected static ?string $navigationLabel = 'Tomar Inventario';

    protected static ?string $title = 'Tomar Inventario';

    protected static ?string $slug = 'tomar-inventario/{inventario}';

    protected static bool $shouldRegisterNavigation = false;

    public $inventario;

    public $codigo_barra = '';

    public $cantidad = 1;

    public $ubicacion = 'DEPOSITO';

    public $articulo = null;

    public $movimientos = [];

    public function mount($inventario): void
    {
        $this->inventario = $inventario;

        $this->cargarMovimientos();
    }

    public function buscarArticulo(): void
    {
        $codigo = trim($this->codigo_barra);

        $this->articulo = DB::connection('mutualnew')
            ->table('stkartic0')
            ->leftJoin('artbar', 'stkartic0.artcod', '=', 'artbar.artcod')
            ->where(function ($query) use ($codigo) {

                $query->where('artbar.artcodbar', $codigo)
                    ->orWhere('stkartic0.artcod', $codigo);

            })
            ->select(
                'stkartic0.artcod',
                'stkartic0.artdes',
                'stkartic0.reposipre',
                'artbar.artcodbar'
            )
            ->first();

        if (! $this->articulo) {

            Notification::make()
                ->title('Artículo no encontrado')
                ->body('No existe el código ingresado.')
                ->danger()
                ->send();

            return;
        }
    }

    public function agregar(): void
    {
        $this->buscarArticulo();

        if (! $this->articulo) {
            return;
        }

        InventarioMovimiento::create([
            'inventario_id' => $this->inventario,
            'artcod' => $this->articulo->artcod,
            'artdes' => $this->articulo->artdes,
            'codigo_barra' => $this->codigo_barra,
            'cantidad' => $this->cantidad,
            'ubicacion' => $this->ubicacion,
            'usuario' => auth()->user()->sisusrcod,
            'created_at' => now(),
        ]);

        $this->codigo_barra = '';

        $this->cantidad = 1;

        $this->articulo = null;

        $this->cargarMovimientos();

        $this->dispatch('focus-input');
    }

    public function cargarMovimientos(): void
    {
        $this->movimientos = InventarioMovimiento::query()

            ->where('inventario_id', $this->inventario)

            ->where('usuario', auth()->user()->sisusrcod)

            ->orderByDesc('id')

            ->limit(10)

            ->get();
    }
}
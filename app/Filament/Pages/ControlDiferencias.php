<?php

namespace App\Filament\Pages;

use App\Exports\ControlDiferenciasExport;
use App\Models\Inventario;
use App\Services\ComparadorInventario;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ControlDiferencias extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected string $view = 'filament.pages.control-diferencias';

    protected static ?string $navigationLabel = 'Control de Diferencias';

    protected static ?string $title = 'Control de Diferencias';

    protected static ?string $slug = 'control-diferencias';

    /** Filas por página de la tabla. */
    public const POR_PAGINA = 50;

    /** Inventario seleccionado (id). */
    public $inventario = null;

    /** Filtro de estado de la tabla. */
    public string $filtro = 'pendientes';

    /** Búsqueda por código o descripción. */
    public string $busqueda = '';

    /** Página actual de la tabla. */
    public int $pagina = 1;

    /** Filas completas de la comparación (se recalcula en cada request). */
    protected ?array $cacheComparacion = null;

    /** Filas que pasan la búsqueda. */
    protected ?array $cacheBuscadas = null;

    /** Filas que pasan búsqueda + filtro de estado. */
    protected ?array $cacheFiltradas = null;

    public function mount(): void
    {
        $parametro = (int) request()->query('inventario', 0);

        if ($parametro > 0) {
            $this->inventario = $parametro;
        }
    }

    public function updatedInventario(): void
    {
        $this->inventario = blank($this->inventario) ? null : (int) $this->inventario;

        $this->busqueda = '';
        $this->pagina = 1;

        $this->limpiarCaches();
    }

    public function updatedBusqueda(): void
    {
        $this->pagina = 1;

        $this->limpiarCaches();
    }

    public function setFiltro(string $filtro): void
    {
        if (! in_array($filtro, ['pendientes', 'diferencia', 'no_contado', 'sin_diferencia', 'todos'], true)) {
            return;
        }

        $this->filtro = $filtro;
        $this->pagina = 1;

        $this->limpiarCaches();
    }

    public function anterior(): void
    {
        if ($this->pagina > 1) {
            $this->pagina--;
        }
    }

    public function siguiente(): void
    {
        if ($this->pagina < $this->totalPaginas()) {
            $this->pagina++;
        }
    }

    /**
     * Fuerza una consulta nueva al ERP: los datos se recalculan en cada
     * request, así que el clic en sí ya trae el stock actualizado.
     */
    public function actualizar(): void
    {
        $this->limpiarCaches();
    }

    public function exportar()
    {
        $filas = $this->filasFiltradas();

        if ($filas === []) {
            Notification::make()
                ->title('Sin datos')
                ->body('No hay artículos para exportar con el filtro actual.')
                ->warning()
                ->send();

            return null;
        }

        return Excel::download(
            new ControlDiferenciasExport($filas),
            'control_diferencias_inventario_' . $this->inventario . '.xlsx'
        );
    }

    /** Inventario seleccionado (o null si no hay). */
    public function inventarioModelo(): ?Inventario
    {
        if (blank($this->inventario)) {
            return null;
        }

        return Inventario::find((int) $this->inventario);
    }

    /**
     * Aviso a mostrar cuando no se puede comparar: inventario inexistente
     * o sin depósito asignado. Cadena vacía si se puede comparar.
     */
    public function aviso(): string
    {
        $inventario = $this->inventarioModelo();

        if (blank($this->inventario)) {
            return '';
        }

        if (! $inventario) {
            return 'El inventario seleccionado no existe.';
        }

        if (blank($inventario->deposito)) {
            return 'Este inventario no tiene depósito asignado: edítelo desde Inventarios para poder comparar.';
        }

        return '';
    }

    /** Opciones del select de inventarios. */
    public function inventariosDisponibles(): array
    {
        return Inventario::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Inventario $inventario) => [
                'id' => (int) $inventario->id,
                'etiqueta' => sprintf(
                    '#%d — %s — Dep. %s',
                    $inventario->id,
                    $inventario->descripcion ?: 'Sin descripción',
                    $inventario->deposito ?: 'sin asignar'
                ),
            ])
            ->all();
    }

    /** Nombre del depósito en el ERP (fallback: el código). */
    public function nombreDeposito(): string
    {
        $inventario = $this->inventarioModelo();

        if (! $inventario || blank($inventario->deposito)) {
            return '';
        }

        $depcod = trim((string) $inventario->deposito);

        try {
            $nombre = DB::connection('mutualnew')->table('stkdepos')
                ->where('depcod', $depcod)
                ->value('depnom');
        } catch (Exception) {
            return $depcod;
        }

        return filled($nombre) ? (string) $nombre : $depcod;
    }

    /** Resumen con la cantidad de artículos por estado. */
    public function resumen(): array
    {
        return $this->comparacion()['resumen'];
    }

    /** Cantidad de artículos que mostraría cada filtro (según la búsqueda). */
    public function conteoFiltro(string $filtro): int
    {
        $filas = $this->buscadas();

        if ($filtro === 'todos') {
            return count($filas);
        }

        if ($filtro === 'pendientes') {
            return count(array_filter($filas, fn ($fila) => $fila['estado'] !== 'sin_diferencia'));
        }

        return count(array_filter($filas, fn ($fila) => $fila['estado'] === $filtro));
    }

    /** Filas de la página actual (búsqueda + filtro de estado + paginación). */
    public function filasPaginadas(): array
    {
        $this->pagina = min($this->pagina, $this->totalPaginas());

        $filas = $this->filasFiltradas();

        return array_slice(
            $filas,
            ($this->pagina - 1) * self::POR_PAGINA,
            self::POR_PAGINA
        );
    }

    /** Total de filas que pasan búsqueda + filtro de estado. */
    public function totalFiltradas(): int
    {
        return count($this->filasFiltradas());
    }

    /** Total de páginas para el filtro actual. */
    public function totalPaginas(): int
    {
        return max(1, (int) ceil($this->totalFiltradas() / self::POR_PAGINA));
    }

    /** Etiqueta en español de cada estado. */
    public function etiquetaEstado(string $estado): string
    {
        return match ($estado) {
            'sin_diferencia' => 'Sin diferencia',
            'diferencia' => 'Diferencia',
            'no_contado' => 'No contado',
            default => $estado,
        };
    }

    /** Color del badge de cada estado. */
    public function colorEstado(string $estado): string
    {
        return match ($estado) {
            'sin_diferencia' => 'success',
            'diferencia' => 'danger',
            'no_contado' => 'warning',
            default => 'gray',
        };
    }

    /** Formato de número sin ceros de más (47.50 → 47.5, 100.00 → 100). */
    public function formato(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2), '0'), '.');
    }

    /** Formato de la diferencia con signo (+2, -3); "—" si no aplica. */
    public function formatoDiferencia(?float $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        return ($valor > 0 ? '+' : '') . $this->formato($valor);
    }

    /**
     * Comparación completa: filas + resumen. Se calcula una vez por request
     * (se memoiza en propiedades que Livewire no serializa).
     *
     * @return array{filas: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    protected function comparacion(): array
    {
        if ($this->cacheComparacion !== null) {
            return $this->cacheComparacion;
        }

        $vacio = [
            'filas' => [],
            'resumen' => [
                'contados' => 0,
                'sin_diferencia' => 0,
                'diferencia' => 0,
                'no_contado' => 0,
                'total' => 0,
            ],
        ];

        $inventario = $this->inventarioModelo();

        if (! $inventario || blank($inventario->deposito)) {
            return $this->cacheComparacion = $vacio;
        }

        try {
            $filas = app(ComparadorInventario::class)->comparar($inventario);
        } catch (Exception $e) {
            Notification::make()
                ->title('No se pudo comparar')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return $this->cacheComparacion = $vacio;
        }

        $resumen = [
            'contados' => 0,
            'sin_diferencia' => 0,
            'diferencia' => 0,
            'no_contado' => 0,
            'total' => count($filas),
        ];

        foreach ($filas as $fila) {
            $resumen[$fila['estado']]++;

            if ($fila['contado'] !== null) {
                $resumen['contados']++;
            }
        }

        return $this->cacheComparacion = ['filas' => $filas, 'resumen' => $resumen];
    }

    /** Filas que pasan la búsqueda por código o descripción. */
    protected function buscadas(): array
    {
        if ($this->cacheBuscadas !== null) {
            return $this->cacheBuscadas;
        }

        $filas = $this->comparacion()['filas'];

        if (filled($this->busqueda)) {
            $busqueda = mb_strtolower(trim($this->busqueda));

            $filas = array_values(array_filter(
                $filas,
                fn ($fila) => str_contains(mb_strtolower($fila['artcod']), $busqueda)
                    || str_contains(mb_strtolower((string) $fila['artdes']), $busqueda)
            ));
        }

        return $this->cacheBuscadas = $filas;
    }

    /** Filas que pasan búsqueda + filtro de estado. */
    protected function filasFiltradas(): array
    {
        if ($this->cacheFiltradas !== null) {
            return $this->cacheFiltradas;
        }

        $filas = $this->buscadas();

        if ($this->filtro !== 'todos') {
            $estados = $this->filtro === 'pendientes'
                ? ['diferencia', 'no_contado']
                : [$this->filtro];

            $filas = array_values(array_filter(
                $filas,
                fn ($fila) => in_array($fila['estado'], $estados, true)
            ));
        }

        return $this->cacheFiltradas = $filas;
    }

    /** Las caches son por request; se limpian al cambiar el estado. */
    protected function limpiarCaches(): void
    {
        $this->cacheComparacion = null;
        $this->cacheBuscadas = null;
        $this->cacheFiltradas = null;
    }
}

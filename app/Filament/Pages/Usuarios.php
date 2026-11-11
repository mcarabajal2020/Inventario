<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\UsuarioDeposito;
use App\Models\UsuarioRecurso;
use App\Services\ControlAcceso;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Habilita o bloquea los recursos (secciones del menú) por usuario. Los
 * usuarios son los del ERP (`siserpy.sisusuar`, sólo lectura) y los
 * permisos se guardan en la tabla local `usuario_recursos`.
 */
class Usuarios extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected string $view = 'filament.pages.usuarios';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $title = 'Usuarios';

    protected static ?string $slug = 'usuarios';

    /** Usuario seleccionado (sisusrcod). */
    public $usuario = null;

    /** Búsqueda por código o nombre dentro de los usuarios del ERP. */
    public string $busqueda = '';

    /** Estado de cada recurso para el usuario seleccionado. */
    public array $permisos = [];

    /** Depósitos elegidos para Consulta de Stock (vacío = todos). */
    public array $depositosSeleccionados = [];

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $cacheUsuarios = null;

    /** @var array<int, array{depcod: string, depnom: string}>|null Depósitos del ERP. */
    protected ?array $cacheDepositos = null;

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && ControlAcceso::puedeGestionarUsuarios(ControlAcceso::usuarioActual());
    }

    public function mount(): void
    {
        $parametro = (string) request()->query('usuario', '');

        if ($parametro !== '') {
            $this->usuario = $parametro;

            $this->cargarPermisos();
        }
    }

    public function updatedUsuario(): void
    {
        $this->usuario = blank($this->usuario) ? null : (string) $this->usuario;

        $this->cargarPermisos();
    }

    /**
     * Usuarios del ERP para el select: los que coinciden con la búsqueda
     * (máx. 60) más el seleccionado, que siempre se muestra aunque el
     * filtro lo esconda.
     */
    public function usuariosDisponibles(): array
    {
        $todos = $this->todosLosUsuarios();

        $busqueda = mb_strtolower(trim($this->busqueda));

        $filtrados = $todos->filter(function ($usuario) use ($busqueda) {
            if ($busqueda === '') {
                return true;
            }

            return str_contains(mb_strtolower($usuario['sisusrcod']), $busqueda)
                || str_contains(mb_strtolower($usuario['nombre']), $busqueda);
        });

        if (filled($this->usuario)) {
            $seleccionado = $todos->firstWhere('sisusrcod', (string) $this->usuario);

            if ($seleccionado && ! $filtrados->contains('sisusrcod', $seleccionado['sisusrcod'])) {
                $filtrados->prepend($seleccionado);
            }
        }

        return $filtrados->take(60)->values()->all();
    }

    /** Nombre del usuario seleccionado (para el encabezado). */
    public function nombreUsuario(): string
    {
        $usuario = $this->todosLosUsuarios()->firstWhere('sisusrcod', (string) $this->usuario);

        if (! $usuario) {
            return (string) $this->usuario;
        }

        return trim($usuario['nombre']) !== ''
            ? $usuario['nombre'].' ('.$usuario['sisusrcod'].')'
            : $usuario['sisusrcod'];
    }

    /** ¿Puede alternar el estado de un recurso para el usuario en pantalla? */
    public function puedeAlternar(string $recurso): bool
    {
        if ($recurso !== 'usuarios') {
            return true;
        }

        $actual = ControlAcceso::usuarioActual();

        // Nadie se quita a sí mismo el acceso a la sección Usuarios, así
        // nunca queda un administrador afuera sin forma de volver.
        return ! $actual || $this->usuario !== (string) $actual->sisusrcod;
    }

    /** Alternar el estado de un recurso y guardarlo. */
    public function alternar(string $recurso): void
    {
        if (! isset(ControlAcceso::catalogo()[$recurso])) {
            return;
        }

        if (blank($this->usuario)) {
            Notification::make()
                ->title('Sin usuario')
                ->body('Primero seleccione un usuario.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->puedeAlternar($recurso)) {
            Notification::make()
                ->title('No permitido')
                ->body('No puede quitarse el acceso a la sección Usuarios a usted mismo.')
                ->danger()
                ->send();

            return;
        }

        // Se lee el estado actual de la BD (no el de la vista): así el
        // botón alterna siempre el valor real, aunque la pantalla no
        // haya cargado los permisos todavía. Sin fila manda el default
        // del recurso (los restringidos dependen del usuario elegido).
        $guardado = UsuarioRecurso::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->where('recurso', $recurso)
            ->value('habilitado');

        $actual = $guardado !== null
            ? (bool) $guardado
            : ControlAcceso::porDefecto((string) $this->usuario, $recurso);

        $habilitado = ! $actual;

        $this->permisos[$recurso] = $habilitado;

        UsuarioRecurso::query()->updateOrCreate(
            ['sisusrcod' => (string) $this->usuario, 'recurso' => $recurso],
            ['habilitado' => $habilitado]
        );

        Notification::make()
            ->title($habilitado ? 'Recurso habilitado' : 'Recurso bloqueado')
            ->body(ControlAcceso::titulo($recurso).' → '.$this->usuario)
            ->{$habilitado ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * Usuarios del ERP (sólo lectura) cacheados por request.
     *
     * @return Collection<int, array{sisusrcod: string, nombre: string, grupo: string, inactivo: bool}>
     */
    protected function todosLosUsuarios(): Collection
    {
        if ($this->cacheUsuarios !== null) {
            return $this->cacheUsuarios;
        }

        $this->cacheUsuarios = User::query()
            ->select('sisusrcod', 'sisusrnom', 'sisgrpcod', 'inactivo')
            ->orderBy('sisusrcod')
            ->get()
            ->map(fn (User $usuario) => [
                'sisusrcod' => trim((string) $usuario->sisusrcod),
                'nombre' => trim((string) $usuario->sisusrnom),
                'grupo' => trim((string) $usuario->sisgrpcod),
                'inactivo' => (int) $usuario->inactivo === 1,
            ])
            ->values();

        return $this->cacheUsuarios;
    }

    /** Carga los permisos actuales del usuario seleccionado. */
    protected function cargarPermisos(): void
    {
        $this->permisos = [];
        $this->depositosSeleccionados = [];

        if (blank($this->usuario)) {
            return;
        }

        $guardados = UsuarioRecurso::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->pluck('habilitado', 'recurso');

        foreach (array_keys(ControlAcceso::catalogo()) as $recurso) {
            // Sin fila: el default del recurso (los restringidos sólo
            // están habilitados si el usuario elegido es admin).
            $this->permisos[$recurso] = isset($guardados[$recurso])
                ? (bool) $guardados[$recurso]
                : ControlAcceso::porDefecto((string) $this->usuario, $recurso);
        }

        // Depósitos visibles en Consulta de Stock (vacío = todos).
        $this->depositosSeleccionados = UsuarioDeposito::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->pluck('depcod')
            ->map(fn ($depcod) => trim((string) $depcod))
            ->values()
            ->all();
    }

    /** ¿Consulta de Stock está habilitado para el usuario elegido? */
    public function consultaStockHabilitado(): bool
    {
        return (bool) ($this->permisos['consulta-stock'] ?? false);
    }

    /**
     * Depósitos del ERP para la lista de selección, ordenados por
     * número. Si la base no responde se muestra la lista vacía.
     *
     * @return array<int, array{depcod: string, depnom: string}>
     */
    public function depositosDisponibles(): array
    {
        if ($this->cacheDepositos !== null) {
            return $this->cacheDepositos;
        }

        try {
            $this->cacheDepositos = DB::connection('mutualnew')
                ->table('stkdepos')
                ->get(['depcod', 'depnom'])
                ->map(fn ($deposito) => [
                    'depcod' => trim((string) $deposito->depcod),
                    'depnom' => trim((string) $deposito->depnom),
                ])
                ->sortBy(fn ($deposito) => (int) $deposito['depcod'])
                ->values()
                ->all();
        } catch (Exception $e) {
            $this->cacheDepositos = [];
        }

        return $this->cacheDepositos;
    }

    /** Habilita o bloquea un depósito para Consulta de Stock. */
    public function alternarDeposito(string $depcod): void
    {
        $depcod = trim($depcod);

        if ($depcod === '' || blank($this->usuario)) {
            return;
        }

        $existe = UsuarioDeposito::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->where('depcod', $depcod)
            ->exists();

        if ($existe) {
            UsuarioDeposito::query()
                ->where('sisusrcod', (string) $this->usuario)
                ->where('depcod', $depcod)
                ->delete();

            $this->depositosSeleccionados = array_values(array_filter(
                $this->depositosSeleccionados,
                fn ($cod) => $cod !== $depcod
            ));
        } else {
            UsuarioDeposito::query()->create([
                'sisusrcod' => (string) $this->usuario,
                'depcod' => $depcod,
            ]);

            $this->depositosSeleccionados[] = $depcod;
        }
    }
}

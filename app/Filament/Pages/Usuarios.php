<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\UsuarioRecurso;
use App\Services\ControlAcceso;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

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

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $cacheUsuarios = null;

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
        // haya cargado los permisos todavía.
        $guardado = UsuarioRecurso::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->where('recurso', $recurso)
            ->value('habilitado');

        // null = sin fila = habilitado por defecto.
        $actual = $guardado === null || (bool) $guardado;
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

        if (blank($this->usuario)) {
            return;
        }

        $guardados = UsuarioRecurso::query()
            ->where('sisusrcod', (string) $this->usuario)
            ->pluck('habilitado', 'recurso');

        foreach (array_keys(ControlAcceso::catalogo()) as $recurso) {
            // Sin fila: habilitado por defecto.
            $this->permisos[$recurso] = isset($guardados[$recurso])
                ? (bool) $guardados[$recurso]
                : true;
        }
    }
}

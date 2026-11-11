<x-filament-panels::page>

    <div class="space-y-6">

        <x-filament::section>

            <x-slot name="heading">
                Seleccionar usuario
            </x-slot>

            <x-slot name="description">
                Usuarios del ERP. Los cambios se guardan en la tabla local usuario_recursos (el ERP no se modifica).
            </x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Buscar por código o nombre
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.300ms="busqueda"
                            placeholder="Ej.: juan, maxi..."
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Usuario
                    </label>

                    <x-filament::input.wrapper>

                        <select
                            wire:model.live="usuario"
                            class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white sm:text-sm sm:leading-6"
                        >
                            <option value="">Seleccione...</option>

                            @foreach ($this->usuariosDisponibles() as $usu)

                                <option value="{{ $usu['sisusrcod'] }}">
                                    {{ $usu['sisusrcod'] }} — {{ $usu['nombre'] ?: 'Sin nombre' }} ({{ $usu['grupo'] ?: 'sin grupo' }}){{ $usu['inactivo'] ? ' [inactivo]' : '' }}
                                </option>

                            @endforeach

                        </select>

                    </x-filament::input.wrapper>

                    @if (count($this->usuariosDisponibles()) >= 60)

                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Se muestran los primeros 60: afine la búsqueda para ver el resto.
                        </div>

                    @endif

                </div>

            </div>

        </x-filament::section>

        @if (filled($this->usuario) && $this->nombreUsuario() !== '')

            <x-filament::section>

                <x-slot name="heading">
                    Recursos de {{ $this->nombreUsuario() }}
                </x-slot>

                <x-slot name="description">
                    Los recursos comunes sin tocar están habilitados para todos los usuarios del ERP.
                    Usuarios y Control de Diferencias son restringidos: por defecto sólo los tienen los
                    administradores del .env (SIS_ADMINS), salvo que se habilite a mano desde acá.
                </x-slot>

                <div class="overflow-x-auto">

                    <table class="w-full divide-y divide-gray-200 dark:divide-white/10">

                        <thead>

                            <tr class="bg-gray-50 dark:bg-white/5">

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Recurso
                                </th>

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Secciones
                                </th>

                                <th class="px-4 py-3 text-right text-sm font-semibold">
                                    Estado
                                </th>

                            </tr>

                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">

                            @foreach (\App\Services\ControlAcceso::catalogo() as $clave => $recurso)

                                @php($habilitado = $this->permisos[$clave] ?? true)

                                <tr wire:key="recurso-{{ $clave }}">

                                    <td class="px-4 py-3 text-sm font-medium">
                                        {{ $recurso['titulo'] }}

                                        @if (\App\Services\ControlAcceso::esRestringido($clave))

                                            <span class="ms-2 inline-flex items-center rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
                                                Sólo admins
                                            </span>

                                        @endif
                                    </td>

                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ implode(' · ', $recurso['rutas']) }}
                                    </td>

                                    <td class="px-4 py-3 text-right text-sm">

                                        <x-filament::button
                                            wire:click="alternar('{{ $clave }}')"
                                            size="sm"
                                            :color="$habilitado ? 'success' : 'danger'"
                                            :disabled="! $this->puedeAlternar($clave)"
                                            :icon="$habilitado ? 'heroicon-o-check' : 'heroicon-o-x-mark'"
                                        >
                                            {{ $habilitado ? 'Habilitado' : 'Bloqueado' }}
                                        </x-filament::button>

                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

                @if (! $this->puedeAlternar('usuarios'))

                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        La sección Usuarios no se puede bloquear a uno mismo: si se hiciera, quedaría sin forma de volver a habilitar los recursos.
                    </div>

                @endif

                @if ($this->consultaStockHabilitado())

                    <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Depósitos visibles en Consulta de Stock
                        </div>

                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Sin selección, {{ $this->nombreUsuario() }} verá <strong>todos</strong> los depósitos.
                            Marque sólo los que deba ver.
                        </div>

                        @if (count($this->depositosDisponibles()) === 0)

                            <div class="mt-3 text-sm text-warning-700 dark:text-warning-400">
                                No se pudieron cargar los depósitos del ERP: revise la conexión con la base mutualnew.
                            </div>

                        @else

                            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">

                                @foreach ($this->depositosDisponibles() as $deposito)

                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">

                                        <input
                                            type="checkbox"
                                            wire:click="alternarDeposito('{{ $deposito['depcod'] }}')"
                                            @checked(in_array($deposito['depcod'], $depositosSeleccionados, true))
                                            class="size-4 shrink-0 cursor-pointer accent-info-600"
                                        >

                                        <span>
                                            {{ $deposito['depnom'] }}
                                            <span class="text-gray-500 dark:text-gray-400">({{ $deposito['depcod'] }})</span>
                                        </span>

                                    </label>

                                @endforeach

                            </div>

                        @endif

                    </div>

                @endif

            </x-filament::section>

        @elseif (filled($this->usuario))

            <x-filament::section>

                <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">

                    <div class="font-semibold text-warning-700 dark:text-warning-400">
                        Usuario inexistente
                    </div>

                    <div class="text-sm text-warning-700 dark:text-warning-400">
                        No se encontró el usuario seleccionado en el ERP.
                    </div>

                </div>

            </x-filament::section>

        @else

            <x-filament::section>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                    <div class="font-semibold text-gray-700 dark:text-gray-200">
                        Seleccione un usuario para gestionar sus recursos
                    </div>

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Se muestran las secciones del menú (Inventarios, Recepción, Transferencias, Consulta de Stock, Control de Diferencias, Dashboard y Usuarios) con su estado actual.
                    </div>

                </div>

            </x-filament::section>

        @endif

    </div>

</x-filament-panels::page>

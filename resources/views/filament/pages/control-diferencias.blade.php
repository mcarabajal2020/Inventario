<x-filament-panels::page>

    <div class="space-y-6">

        <x-filament::section>

            <x-slot name="heading">
                Seleccionar inventario
            </x-slot>

            <x-slot name="description">
                Compará lo contado en el inventario contra el stock que tiene ese depósito en el ERP
            </x-slot>

            <div>

                <label class="block text-sm font-medium mb-2">
                    Inventario
                </label>

                <x-filament::input.wrapper>

                    <select
                        wire:model.live="inventario"
                        class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white sm:text-sm sm:leading-6"
                    >
                        <option value="">Seleccione...</option>

                        @foreach ($this->inventariosDisponibles() as $inv)

                            <option value="{{ $inv['id'] }}">
                                {{ $inv['etiqueta'] }}
                            </option>

                        @endforeach

                    </select>

                </x-filament::input.wrapper>

            </div>

            @if (filled($this->aviso()))

                <div class="mt-4 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">

                    <div class="font-semibold text-warning-700 dark:text-warning-400">
                        No se puede comparar
                    </div>

                    <div class="text-sm text-warning-700 dark:text-warning-400">
                        {{ $this->aviso() }}
                    </div>

                </div>

            @endif

        </x-filament::section>

        @if (blank($this->inventario))

            <x-filament::section>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                    <div class="font-semibold text-gray-700 dark:text-gray-200">
                        Seleccione un inventario para comparar
                    </div>

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Se mostrarán las diferencias entre lo contado y el stock del depósito en el ERP, y los artículos con existencia que no fueron contados.
                    </div>

                </div>

            </x-filament::section>

        @elseif (blank($this->aviso()))

            @php($resumen = $this->resumen())

            <x-filament::section>

                <x-slot name="heading">
                    Resumen
                </x-slot>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">

                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Contados
                        </div>

                        <div class="text-2xl font-bold text-gray-950 dark:text-white">
                            {{ $resumen['contados'] }}
                        </div>

                    </div>

                    <div class="rounded-xl border border-success-300 bg-success-50 p-4 dark:border-success-700 dark:bg-success-900/20">

                        <div class="text-sm text-success-700 dark:text-success-400">
                            Sin diferencia
                        </div>

                        <div class="text-2xl font-bold text-success-700 dark:text-success-400">
                            {{ $resumen['sin_diferencia'] }}
                        </div>

                    </div>

                    <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-900/20">

                        <div class="text-sm text-danger-700 dark:text-danger-400">
                            Diferencias
                        </div>

                        <div class="text-2xl font-bold text-danger-700 dark:text-danger-400">
                            {{ $resumen['diferencia'] }}
                        </div>

                    </div>

                    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">

                        <div class="text-sm text-warning-700 dark:text-warning-400">
                            No contados
                        </div>

                        <div class="text-2xl font-bold text-warning-700 dark:text-warning-400">
                            {{ $resumen['no_contado'] }}
                        </div>

                    </div>

                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Total en depósito
                        </div>

                        <div class="text-2xl font-bold text-gray-950 dark:text-white">
                            {{ $resumen['total'] }}
                        </div>

                    </div>

                </div>

            </x-filament::section>

            <x-filament::section>

                <x-slot name="heading">
                    Detalle
                </x-slot>

                <div class="flex flex-wrap items-end gap-3">

                    <div class="flex flex-wrap gap-2">

                        @foreach ([
                            'pendientes' => 'Pendientes',
                            'diferencia' => 'Diferencias',
                            'no_contado' => 'No contados',
                            'sin_diferencia' => 'Sin diferencia',
                            'todos' => 'Todos',
                        ] as $clave => $etiqueta)

                            <x-filament::button
                                wire:click="setFiltro('{{ $clave }}')"
                                size="sm"
                                :color="$this->filtro === $clave ? 'primary' : 'gray'"
                            >
                                {{ $etiqueta }} ({{ $this->conteoFiltro($clave) }})
                            </x-filament::button>

                        @endforeach

                    </div>

                    <div class="min-w-56 flex-1">

                        <x-filament::input.wrapper>

                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.300ms="busqueda"
                                placeholder="Buscar por código o descripción"
                            />

                        </x-filament::input.wrapper>

                    </div>

                    <x-filament::button
                        wire:click="actualizar"
                        size="sm"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                    >
                        ACTUALIZAR
                    </x-filament::button>

                    <x-filament::button
                        wire:click="exportar"
                        size="sm"
                        color="success"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        EXPORTAR EXCEL
                    </x-filament::button>

                </div>

                <div class="mt-4 overflow-x-auto">

                    <table class="w-full divide-y divide-gray-200 dark:divide-white/10">

                        <thead>

                            <tr class="bg-gray-50 dark:bg-white/5">

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Artículo
                                </th>

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Descripción
                                </th>

                                <th class="px-4 py-3 text-right text-sm font-semibold">
                                    Contado
                                </th>

                                <th class="px-4 py-3 text-right text-sm font-semibold">
                                    Stock ERP
                                </th>

                                <th class="px-4 py-3 text-right text-sm font-semibold">
                                    Diferencia
                                </th>

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Estado
                                </th>

                            </tr>

                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">

                            @forelse ($this->filasPaginadas() as $fila)

                                <tr wire:key="fila-{{ $fila['artcod'] }}">

                                    <td class="px-4 py-3 text-sm font-medium">
                                        {{ $fila['artcod'] }}
                                    </td>

                                    <td class="px-4 py-3 text-sm">
                                        {{ $fila['artdes'] }}
                                    </td>

                                    <td class="px-4 py-3 text-right text-sm">
                                        {{ $fila['contado'] === null ? '—' : $this->formato($fila['contado']) }}
                                    </td>

                                    <td class="px-4 py-3 text-right text-sm">
                                        {{ $this->formato($fila['stock']) }}
                                    </td>

                                    <td class="px-4 py-3 text-right text-sm font-semibold">
                                        {{ $this->formatoDiferencia($fila['diferencia']) }}
                                    </td>

                                    <td class="px-4 py-3 text-sm">

                                        <x-filament::badge :color="$this->colorEstado($fila['estado'])">
                                            {{ $this->etiquetaEstado($fila['estado']) }}
                                        </x-filament::badge>

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No hay artículos para el filtro actual.
                                    </td>

                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->totalFiltradas() }} artículos — Página {{ $this->pagina }} de {{ $this->totalPaginas() }}
                    </div>

                    <div class="flex gap-2">

                        <x-filament::button
                            wire:click="anterior"
                            size="sm"
                            color="gray"
                            :disabled="$this->pagina <= 1"
                        >
                            Anterior
                        </x-filament::button>

                        <x-filament::button
                            wire:click="siguiente"
                            size="sm"
                            color="gray"
                            :disabled="$this->pagina >= $this->totalPaginas()"
                        >
                            Siguiente
                        </x-filament::button>

                    </div>

                </div>

            </x-filament::section>

        @endif

    </div>

</x-filament-panels::page>

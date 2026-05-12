<x-filament-panels::page>

    <div class="space-y-6">

        <x-filament::section>

            <x-slot name="heading">
                Colectora de Inventario
            </x-slot>

            <x-slot name="description">
                Escaneo y carga de artículos
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

                <div class="md:col-span-2">

                    <label class="block text-sm font-medium mb-2">
                        Código de barras
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="text"
                            wire:model="codigo_barra"
                            wire:keydown.enter="agregar"
                            autofocus
                            placeholder="Escanear código de barras"
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Cantidad
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="number"
                            step="0.01"
                            wire:model="cantidad"
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Ubicación
                    </label>

                    <x-filament::input.wrapper>

                        <select
                            wire:model="ubicacion"
                            class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white sm:text-sm sm:leading-6"
                        >
                            <option value="GONDOLA">Góndola</option>
                            <option value="DEPOSITO">Depósito</option>
                            <option value="EXHIBIDOR">Exhibidor</option>
                        </select>

                    </x-filament::input.wrapper>

                </div>

            </div>

        </x-filament::section>

        @if($articulo)

            <x-filament::section>

                <x-slot name="heading">
                    Artículo Escaneado
                </x-slot>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                    <div>
                        <div class="text-sm text-gray-500">
                            Código
                        </div>

                        <div class="text-lg font-bold">
                            {{ $articulo->artcod }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">
                            Descripción
                        </div>

                        <div class="text-lg font-bold">
                            {{ $articulo->artdes }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">
                            Cantidad
                        </div>

                        <div class="text-lg font-bold">
                            {{ $cantidad }}
                        </div>
                    </div>

                </div>

            </x-filament::section>

        @endif

        <x-filament::section>

            <x-slot name="heading">
                Resumen del Inventario
            </x-slot>

            <div class="overflow-x-auto">

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
                                Cantidad
                            </th>

                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">

                        @forelse($movimientos as $mov)

                            <tr>

                                <td class="px-4 py-3 text-sm">
                                    {{ $mov->artcod }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    {{ $mov->artdes }}
                                </td>

                                <td class="px-4 py-3 text-sm text-right font-bold">
                                    {{ $mov->cantidad }}
                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td colspan="3" class="px-4 py-10 text-center text-sm text-gray-500">
                                    No hay artículos cargados
                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </x-filament::section>

    </div>

</x-filament-panels::page>
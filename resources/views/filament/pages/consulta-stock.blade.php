<x-filament-panels::page>

    <div class="space-y-6">

        <x-filament::section>

            <x-slot name="heading">
                Consulta de stock
            </x-slot>

            <x-slot name="description">
                Escanee el código de barras, el código del artículo o escriba una descripción para ver el stock en cada depósito
            </x-slot>

            <div>

                <label class="block text-sm font-medium mb-2">
                    Código de barras o descripción
                </label>

                <x-filament::input.wrapper>

                    <x-filament::input
                        type="text"
                        wire:model="codigo"
                        wire:keydown.enter="consultar"
                        autofocus
                        placeholder="Escanear o escribir descripción"
                    />

                </x-filament::input.wrapper>

            </div>

            <div class="mt-20 flex flex-wrap gap-8 md:mt-8 md:gap-3">

                <x-filament::button
                    wire:click="consultar"
                    size="xl"
                >
                    CONSULTAR
                </x-filament::button>

                @if($articulo)

                    <x-filament::button
                        wire:click="limpiar"
                        size="xl"
                        color="gray"
                    >
                        LIMPIAR
                    </x-filament::button>

                @endif

            </div>

            @if(count($this->resultados))

                <div class="mt-6 rounded-xl border border-info-300 bg-info-50 p-4 dark:border-info-700 dark:bg-info-900/20">

                    <div class="flex flex-wrap items-center justify-between gap-3">

                        <div class="font-semibold text-info-700 dark:text-info-400">

                            {{ count($this->resultados) }} artículos coinciden con "{{ $codigo }}"

                        </div>

                        <x-filament::button
                            wire:click="descartarResultados"
                            color="gray"
                            size="sm"
                        >
                            Cerrar
                        </x-filament::button>

                    </div>

                    <div class="mt-3 max-h-64 overflow-y-auto">

                        <table class="w-full divide-y divide-info-200 dark:divide-info-800">

                            <thead>

                                <tr class="text-left text-xs font-semibold text-info-700 dark:text-info-400">

                                    <th class="px-2 py-2">Código</th>
                                    <th class="px-2 py-2">Descripción</th>
                                    <th class="px-2 py-2"></th>

                                </tr>

                            </thead>

                            <tbody class="divide-y divide-info-100 dark:divide-info-800/60">

                                @foreach($this->resultados as $resultado)

                                    <tr class="text-sm">

                                        <td class="px-2 py-2 font-medium">
                                            {{ $resultado['artcod'] }}
                                        </td>

                                        <td class="px-2 py-2">
                                            {{ $resultado['artdes'] }}
                                        </td>

                                        <td class="px-2 py-2 text-right">

                                            <x-filament::button
                                                wire:click="seleccionar('{{ $resultado['artcod'] }}')"
                                                size="sm"
                                            >
                                                Ver stock
                                            </x-filament::button>

                                        </td>

                                    </tr>

                                @endforeach

                            </tbody>

                        </table>

                    </div>

                </div>

            @endif

            @if(! $articulo && count($saldos) === 0 && count($this->resultados) === 0)

                <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                    <div class="font-semibold text-gray-700 dark:text-gray-200">
                        Escanee un artículo para ver su stock
                    </div>

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        La consulta muestra el código y la descripción del artículo y el stock de cada depósito.
                    </div>

                </div>

            @endif

        </x-filament::section>

        @if($articulo)

            <x-filament::section>

                <x-slot name="heading">
                    Artículo
                </x-slot>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div>

                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            Código
                        </div>

                        <div class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $articulo['artcod'] }}
                        </div>

                    </div>

                    <div>

                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            Descripción
                        </div>

                        <div class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $articulo['artdes'] }}
                        </div>

                    </div>

                </div>

                @if(count($saldos) === 0)

                    <div class="mt-4 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">

                        <div class="font-semibold text-warning-700 dark:text-warning-400">
                            Sin stock en ningún depósito
                        </div>

                    </div>

                @endif

            </x-filament::section>

        @endif

        @if(count($saldos))

            <x-filament::section>

                <x-slot name="heading">
                    Stock por depósito
                </x-slot>

                <div class="overflow-x-auto">

                    <table class="w-full divide-y divide-gray-200 dark:divide-white/10">

                        <thead>

                            <tr class="bg-gray-50 dark:bg-white/5">

                                <th class="px-4 py-3 text-left text-sm font-semibold">
                                    Depósito
                                </th>

                                <th class="px-4 py-3 text-right text-sm font-semibold">
                                    Stock
                                </th>

                            </tr>

                        </thead>

                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">

                            @foreach($saldos as $saldo)

                                <tr wire:key="saldo-{{ $saldo['depcod'] }}">

                                    <td class="px-4 py-3">

                                        <div class="text-sm font-medium">
                                            {{ $saldo['depnom'] }}
                                        </div>

                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $saldo['depcod'] }}
                                        </div>

                                    </td>

                                    <td class="px-4 py-3 text-right text-sm font-semibold">
                                        {{ rtrim(rtrim(number_format((float) $saldo['stock'], 2), '0'), '.') }}
                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                        <tfoot>

                            <tr class="bg-gray-50 dark:bg-white/5">

                                <td class="px-4 py-3 text-sm font-semibold">
                                    Total
                                </td>

                                <td class="px-4 py-3 text-right text-sm font-semibold">
                                    {{ rtrim(rtrim(number_format($this->total(), 2), '0'), '.') }}
                                </td>

                            </tr>

                        </tfoot>

                    </table>

                </div>

            </x-filament::section>

        @endif

    </div>

    <script>

        document.addEventListener('livewire:init', () => {

            Livewire.on('focus-input', () => {

                setTimeout(() => {

                    let input = document.querySelector('input[type=text]');

                    if (input) {
                        input.focus();
                    }

                }, 100);

            });

        });

    </script>

</x-filament-panels::page>

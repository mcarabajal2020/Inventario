<x-filament-panels::page>

    <div class="space-y-6">

        <x-filament::section>

            <x-slot name="heading">
                Cabecera de la factura
            </x-slot>

            <x-slot name="description">
                Fecha, depósito, proveedor y datos del comprobante
            </x-slot>

            @if(count($this->depositos()) === 0 || count($this->proveedores()) === 0)

                <div class="mb-4 rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-900/20">

                    <div class="font-semibold text-danger-700 dark:text-danger-400">
                        No se pudieron cargar los datos del ERP
                    </div>

                    <div class="text-sm text-danger-600 dark:text-danger-500">
                        {{ filled($errorApi) ? $errorApi : 'Los depósitos o proveedores vienen vacíos: revise la conexión con la API del ERP y recargue la página.' }}
                    </div>

                </div>

            @endif

            <div class="mb-4 flex justify-end">

                <x-filament::button
                    wire:click="alternarCabecera"
                    size="sm"
                    color="gray"
                >
                    {{ $cabeceraVisible ? 'Ocultar cabecera' : 'Mostrar cabecera' }}
                </x-filament::button>

            </div>

            @unless ($cabeceraVisible)

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">

                    <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                        Cabecera oculta
                    </div>

                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ $this->resumenCabecera() }}
                    </div>

                    @if ($recepcion)

                        <div class="mt-1 text-sm font-semibold text-success-700 dark:text-success-400">
                            Recepción en curso
                        </div>

                    @endif

                </div>

            @else

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Fecha
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="date"
                            wire:model="fecha"
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Depósito
                    </label>

                    <x-filament::input.wrapper>

                        <select
                            wire:model="deposito"
                            class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white sm:text-sm sm:leading-6"
                        >
                            <option value="">Seleccione...</option>

                            @foreach (collect($this->depositos()) as $dep)

                                <option value="{{ $dep['depcod'] }}">
                                    {{ $dep['depnom'] }}
                                </option>

                            @endforeach

                        </select>

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Proveedor
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.300ms="busquedaProveedor"
                            placeholder="Buscar por nombre, CUIT o n&#250;mero de cuenta"
                        />

                    </x-filament::input.wrapper>

                    <div class="mt-2">

                        <x-filament::input.wrapper>

                            <select
                                wire:model="proveedor"
                                class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white sm:text-sm sm:leading-6"
                            >
                                <option value="">Seleccione...</option>

                                @foreach (collect($this->proveedoresFiltrados()) as $prov)

                                    <option value="{{ $prov['ctacod'] }}">
                                        {{ $prov['ctanom'] }} (cuenta {{ $prov['ctacod'] }} - CUIT {{ $prov['ctanro'] ?? 's/d' }})
                                    </option>

                                @endforeach

                            </select>

                        </x-filament::input.wrapper>

                        @if ($this->busquedaSinCoincidencias())

                            <div class="mt-2 text-sm text-danger-600">
                                Sin coincidencias para {{ $busquedaProveedor }}
                            </div>

                        @endif

                    </div>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Punto de venta
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="number"
                            wire:model.defer="ptovta"
                            placeholder="Ej: 1"
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Hoja
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="number"
                            wire:model.defer="hoja"
                            placeholder="Ej: 1"
                        />

                    </x-filament::input.wrapper>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-2">
                        Comprobante
                    </label>

                    <x-filament::input.wrapper>

                        <x-filament::input
                            type="number"
                            wire:model.defer="comprobante"
                            placeholder="Ej: 12345"
                        />

                    </x-filament::input.wrapper>

                </div>

            </div>

            <div class="mt-8 flex flex-wrap gap-3">

                <x-filament::button
                    wire:click="iniciar"
                    size="xl"
                >
                    {{ $recepcion ? 'Actualizar cabecera' : 'Iniciar recepción' }}
                </x-filament::button>

                @if($recepcion)

                    <x-filament::button
                        wire:click="cancelar"
                        color="danger"
                        size="xl"
                        wire:confirm="Se borrarán los artículos cargados. ¿Continuar?"
                    >
                        Cancelar carga
                    </x-filament::button>

                @endif

            </div>

            @if($recepcion)

                <div class="mt-4 rounded-xl border border-success-300 bg-success-50 p-4 dark:border-success-700 dark:bg-success-900/20">

                    <div class="font-semibold text-success-700 dark:text-success-400">
                        Recepción en curso
                    </div>

                    <div class="text-sm text-success-600 dark:text-success-500">
                        Escanee los artículos de la factura y luego guarde en el ERP.
                    </div>

                </div>

            @endif

            @endunless

        </x-filament::section>

        <x-filament::section>

            <x-slot name="heading">
                Carga de artículos
            </x-slot>

            <x-slot name="description">
                Escanee el código de barras y confirme la cantidad
            </x-slot>

            @if($recepcion)

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

                    <div class="md:col-span-2">

                        <label class="block text-sm font-medium mb-2">
                            Código de barras / artículo
                        </label>

                        <x-filament::input.wrapper>

                            <x-filament::input
                                type="text"
                                wire:model="codigo"
                                wire:keydown.enter="agregar"
                                autofocus
                                placeholder="Escanear código"
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
                                wire:keydown.enter="agregar"
                            />

                        </x-filament::input.wrapper>

                    </div>

                    <div class="flex items-end">

                        <x-filament::button
                            wire:click="agregar"
                            size="xl"
                            class="w-full"
                        >
                            AGREGAR
                        </x-filament::button>

                    </div>

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
                                        <th class="px-2 py-2 text-right">Stock</th>
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
                                                {{ rtrim(rtrim(number_format((float) ($resultado['stkcan'] ?? 0), 2), '0'), '.') }}
                                            </td>

                                            <td class="px-2 py-2 text-right">

                                                <x-filament::button
                                                    wire:click="seleccionar('{{ $resultado['artcod'] }}')"
                                                    size="sm"
                                                >
                                                    Agregar
                                                </x-filament::button>

                                            </td>

                                        </tr>

                                    @endforeach

                                </tbody>

                            </table>

                        </div>

                    </div>

                @endif

                <div class="mt-6 overflow-x-auto">

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

                                <th class="px-4 py-3 text-right text-sm font-semibold">

                                </th>

                            </tr>

                        </thead>

                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">

                            @forelse($this->detalles() as $detalle)

                                <tr>

                                    <td class="px-4 py-3 text-sm">
                                        {{ $detalle->artcod }}
                                    </td>

                                    <td class="px-4 py-3 text-sm">
                                        {{ $detalle->artdes }}
                                    </td>

                                    <td class="px-4 py-3 text-sm text-right font-bold">
                                        {{ rtrim(rtrim(number_format($detalle->cantidad, 2), '0'), '.') }}
                                    </td>

                                    <td class="px-4 py-3 text-right">

                                        <x-filament::button
                                            wire:click="eliminar('{{ $detalle->id }}')"
                                            color="danger"
                                            size="sm"
                                        >
                                            Quitar
                                        </x-filament::button>

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="4" class="px-4 py-10 text-center text-sm text-gray-500">
                                        No hay artículos cargados
                                    </td>

                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

                <div class="mt-6 flex flex-wrap gap-3">

                    <x-filament::button
                        wire:click="finalizar"
                        size="xl"
                        color="success"
                        wire:confirm="Se enviará la recepción al ERP. ¿Continuar?"
                    >
                        GUARDAR EN EL ERP
                    </x-filament::button>

                </div>

            @else

                <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">

                    <div class="font-semibold text-warning-700 dark:text-warning-400">
                        Sin recepción iniciada
                    </div>

                    <div class="text-sm text-warning-600 dark:text-warning-500">
                        Complete la cabecera de la factura y presione "Iniciar recepción".
                    </div>

                </div>

            @endif

        </x-filament::section>

        <x-filament::section>

            <x-slot name="heading">
                Últimas recepciones
            </x-slot>

            <div class="overflow-x-auto">

                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">

                    <thead>

                        <tr class="bg-gray-50 dark:bg-white/5">

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Fecha
                            </th>

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Proveedor
                            </th>

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Depósito
                            </th>

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Comprobante
                            </th>

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Estado
                            </th>

                            <th class="px-4 py-3 text-left text-sm font-semibold">
                                Mensaje
                            </th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">

                        @forelse($this->ultimasRecepciones() as $recepcionItem)

                            <tr>

                                <td class="px-4 py-3 text-sm">
                                    {{ $recepcionItem->fecha }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    {{ $recepcionItem->proveedor_nom }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    {{ $recepcionItem->deposito_nom }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    {{ $recepcionItem->ptovta }}-{{ $recepcionItem->comprobante }} / {{ $recepcionItem->hoja }}
                                </td>

                                <td class="px-4 py-3 text-sm">

                                    @if($recepcionItem->estado === 'finalizada')

                                        <x-filament::badge color="success">
                                            Finalizada
                                        </x-filament::badge>

                                    @elseif($recepcionItem->estado === 'error')

                                        <x-filament::badge color="danger">
                                            Error
                                        </x-filament::badge>

                                    @else

                                        <x-filament::badge color="gray">
                                            Borrador
                                        </x-filament::badge>

                                    @endif

                                </td>

                                <td class="px-4 py-3 text-sm">
                                    {{ $recepcionItem->mensaje }}
                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                                    Todavía no se registraron recepciones
                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </x-filament::section>

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

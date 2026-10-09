<?php

namespace App\Filament\Pages;

use App\Models\Recepcion;
use App\Models\RecepcionDetalle;
use App\Services\ComprobanteErp;
use App\Services\SisApiClient;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RecepcionMercaderia extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected string $view = 'filament.pages.recepcion-mercaderia';

    protected static ?string $navigationLabel = 'Recepción de Mercadería';

    protected static ?string $title = 'Recepción de Mercadería';

    protected static ?string $slug = 'recepcion-mercaderia';

    public ?int $recepcion = null;

    public string $fecha = '';

    public string $deposito = '';

    public string $proveedor = '';

    /** Texto de búsqueda del proveedor: nombre, CUIT o número de cuenta. */
    public string $busquedaProveedor = '';

    public string $ptovta = '';

    public string $hoja = '1';

    public string $comprobante = '';

    public string $codigo = '';

    public float $cantidad = 1;

    /** Coincidencias de la búsqueda cuando hay más de un artículo. */
    public array $resultados = [];

    /** La cabecera se pliega para que en el móvil quede el campo de carga a la vista. */
    public bool $cabeceraVisible = true;

    /** Último error de la API del ERP, para mostrarlo en la pantalla. */
    public string $errorApi = '';

    public function mount(): void
    {
        $this->fecha = now()->format('Y-m-d');

        $borrador = $this->borrador();

        if ($borrador) {
            $this->cargarCabecera($borrador);
        }

        try {
            $this->api()->depositos();
            $this->api()->proveedores();
        } catch (Exception $e) {
            $this->errorApi = $e->getMessage();

            $this->notificacion('API del ERP', $e->getMessage(), 'danger');
        }
    }

    /**
     * Se usan en la vista: si el ERP no responde la pantalla queda vacía
     * en lugar de tirar un error 500.
     */
    public function depositos(): array
    {
        return $this->consultar(fn () => $this->api()->depositos());
    }

    public function proveedores(): array
    {
        return $this->consultar(fn () => $this->api()->proveedores());
    }

    /**
     * Proveedores para el desplegable: coinciden con la búsqueda por nombre,
     * CUIT o número de cuenta (la lista completa viene de la API y se cachea
     * 30 minutos, así que el filtrado es local e instantáneo).
     */
    public function proveedoresFiltrados(): array
    {
        $proveedores = $this->proveedores();

        if (blank($this->busquedaProveedor)) {
            return $proveedores;
        }

        $filtrados = array_values(array_filter($proveedores, fn ($proveedor) => $this->coincideConBusqueda($proveedor)));

        // El proveedor ya elegido se mantiene visible aunque no coincida.
        if (filled($this->proveedor) && ! collect($filtrados)->contains('ctacod', $this->proveedor)) {
            $seleccionado = collect($proveedores)->firstWhere('ctacod', $this->proveedor);

            if ($seleccionado) {
                $filtrados[] = $seleccionado;
            }
        }

        return $filtrados;
    }

    /** ¿La búsqueda actual no encontró ningún proveedor? */
    public function busquedaSinCoincidencias(): bool
    {
        if (blank($this->busquedaProveedor)) {
            return false;
        }

        return ! collect($this->proveedores())->contains(fn ($proveedor) => $this->coincideConBusqueda($proveedor));
    }

    /** Coincide el proveedor con la búsqueda por nombre, CUIT o número de cuenta. */
    protected function coincideConBusqueda(array $proveedor): bool
    {
        $texto = strtoupper(trim($this->busquedaProveedor));

        if ($texto === '') {
            return true;
        }

        $compacto = str_replace([' ', '-', '.'], '', $texto);

        // Nombre (sin distinguir mayúsculas).
        if (str_contains(strtoupper((string) ($proveedor['ctanom'] ?? '')), $texto)) {
            return true;
        }

        // Número de cuenta: coincide si empieza con lo tipeado.
        if (str_starts_with((string) ($proveedor['ctacod'] ?? ''), $compacto)) {
            return true;
        }

        // CUIT: sin puntuación (20-217894132 = 20217894132).
        return str_contains(str_replace([' ', '-', '.'], '', (string) ($proveedor['ctanro'] ?? '')), $compacto);
    }

    public function detalles(): Collection
    {
        if (! $this->recepcion) {
            return collect();
        }

        return RecepcionDetalle::query()
            ->where('recepcion_id', $this->recepcion)
            ->orderBy('id')
            ->get();
    }

    public function ultimasRecepciones(): Collection
    {
        return Recepcion::query()->latest('id')->limit(10)->get();
    }

    public function iniciar(): void
    {
        if (blank($this->deposito) || blank($this->proveedor) || blank($this->ptovta) || blank($this->hoja) || blank($this->comprobante)) {

            $this->notificacion('Datos incompletos', 'Complete depósito, proveedor, punto de venta, hoja y comprobante.', 'danger');

            return;
        }

        if (! is_numeric($this->ptovta) || ! is_numeric($this->hoja) || ! is_numeric($this->comprobante)) {

            $this->notificacion('Datos inválidos', 'Punto de venta, hoja y comprobante deben ser numéricos.', 'danger');

            return;
        }

        $borrador = $this->borrador();

        if ($cargada = $this->comprobanteYaIngresado($borrador?->id)) {

            $cuerpo = 'El comprobante ' . $this->ptovta . '-' . $this->hoja . '-' . $this->comprobante
                . ' de ' . $cargada->proveedor_nom;

            $cuerpo .= $cargada->estado === 'error'
                ? ' ya fue intentado el ' . date('d/m/Y', strtotime($cargada->fecha))
                    . ' por ' . $cargada->user_id . ' y el ERP lo rechazó por duplicado: ' . $cargada->mensaje
                : ' ya fue cargado el ' . date('d/m/Y', strtotime($cargada->fecha))
                    . ' por ' . $cargada->user_id . ' (estado: ' . $cargada->estado . ').';

            $this->notificacion('Comprobante ya ingresado', $cuerpo, 'danger');

            return;
        }

        // El comprobante puede no estar en esta aplicación y ya existir en
        // el ERP (lo cargó otro sistema o se cargó directo en el ERP).
        // Si la consulta falla no se bloquea la carga.
        try {
            $enErp = $this->comprobanteErp()->buscar((int) $this->ptovta, (int) $this->comprobante);
        } catch (Exception $e) {
            Log::warning('No se pudo validar el comprobante en el ERP: ' . $e->getMessage());

            $enErp = null;
        }

        if ($enErp) {

            $cuerpo = 'El comprobante ' . $this->ptovta . '-' . $this->comprobante . ' ya está registrado en el ERP';

            $cuerpo .= ! empty($enErp['fecha'])
                ? ' el ' . date('d/m/Y', strtotime($enErp['fecha']))
                : '';

            $cuerpo .= ! empty($enErp['proveedor'])
                ? ' a nombre de ' . $enErp['proveedor']
                : '';

            $cuerpo .= ! empty($enErp['usuario'])
                ? ' (lo cargó ' . $enErp['usuario'] . ').'
                : '.';

            $this->notificacion('Comprobante ya existe en el ERP', $cuerpo, 'danger');

            return;
        }

        try {
            $deposito = collect($this->api()->depositos())->firstWhere('depcod', $this->deposito);
            $proveedor = collect($this->api()->proveedores())->firstWhere('ctacod', $this->proveedor);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (! $deposito || ! $proveedor) {

            $this->notificacion('Datos inválidos', 'El depósito o el proveedor seleccionado no existe en el ERP.', 'danger');

            return;
        }

        $recepcion = $borrador ?? new Recepcion();

        $recepcion->fill([
            'fecha' => $this->fecha,
            'deposito_cod' => $deposito['depcod'],
            'deposito_nom' => $deposito['depnom'],
            'proveedor_cod' => $proveedor['ctacod'],
            'proveedor_nom' => $proveedor['ctanom'],
            'ptovta' => $this->ptovta,
            'hoja' => $this->hoja,
            'comprobante' => $this->comprobante,
            'estado' => 'borrador',
        ])->save();

        $this->recepcion = $recepcion->id;

        // Con la recepción abierta, lo importante es el campo de carga.
        $this->cabeceraVisible = false;

        $this->notificacion('Recepción iniciada', 'Ahora puede escanear los artículos.', 'success');
    }

    /** Pliega o despliega la cabecera de la factura. */
    public function alternarCabecera(): void
    {
        $this->cabeceraVisible = ! $this->cabeceraVisible;
    }

    /** Resumen de la cabecera para mostrarla plegada (una sola línea). */
    public function resumenCabecera(): string
    {
        $deposito = collect($this->depositos())->firstWhere('depcod', $this->deposito);
        $proveedor = collect($this->proveedores())->firstWhere('ctacod', $this->proveedor);

        return collect([
            filled($this->fecha) ? date('d/m/Y', strtotime($this->fecha)) : '',
            $deposito['depnom'] ?? '',
            $proveedor['ctanom'] ?? '',
            collect([
                filled($this->ptovta) ? 'Pto Vta ' . $this->ptovta : '',
                filled($this->hoja) ? 'Hoja ' . $this->hoja : '',
                filled($this->comprobante) ? 'Comprobante ' . $this->comprobante : '',
            ])->filter()->implode(' · '),
        ])->filter()->implode(' · ');
    }

    /**
     * Otra recepción (de este usuario o de cualquiera) con el mismo
     * punto de venta, hoja y comprobante: se corta antes de cargar.
     *
     * Además de los borradores y las finalizadas, cuenta la que quedó en
     * `error` cuando el ERP la rechazó por duplicado: reintentarla siempre
     * termina en el mismo error.
     */
    protected function comprobanteYaIngresado(?int $excluirId = null): ?Recepcion
    {
        return Recepcion::query()
            ->where(function ($query) {
                $query->whereIn('estado', ['borrador', 'finalizada'])
                    ->orWhere(function ($query) {
                        $query->where('estado', 'error')
                            ->whereRaw('mensaje LIKE ?', ['%duplicados del n_mero de comprobante%']);
                    });
            })
            ->whereRaw('CAST(ptovta AS UNSIGNED) = ?', [(int) $this->ptovta])
            ->whereRaw('CAST(hoja AS UNSIGNED) = ?', [(int) $this->hoja])
            ->whereRaw('CAST(comprobante AS UNSIGNED) = ?', [(int) $this->comprobante])
            ->when($excluirId, fn ($query) => $query->where('id', '!=', $excluirId))
            ->latest('id')
            ->first();
    }

    public function agregar(): void
    {
        if (! $this->recepcion) {

            $this->notificacion('Sin recepción', 'Primero ingrese los datos de la factura.', 'danger');

            return;
        }

        if (blank($this->codigo)) {
            return;
        }

        if ($this->cantidad <= 0) {

            $this->notificacion('Cantidad inválida', 'Ingrese una cantidad mayor a cero.', 'danger');

            return;
        }

        $this->resultados = [];

        try {
            $articulos = $this->api()->buscarArticulo($this->codigo);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (empty($articulos)) {

            $this->notificacion('Artículo no encontrado', 'No existe el código ingresado.', 'danger');

            $this->codigo = '';

            return;
        }

        if (count($articulos) > 1) {

            // La búsqueda por descripción trae varios: el usuario elige cuál.
            $this->resultados = $articulos;

            return;
        }

        $this->guardarArticulo($articulos[0]);
    }

    /**
     * Agrega el artículo elegido de la lista de coincidencias.
     */
    public function seleccionar(string $artcod): void
    {
        $articulo = collect($this->resultados)->firstWhere('artcod', $artcod);

        $this->resultados = [];

        if (! $articulo) {
            return;
        }

        $this->guardarArticulo($articulo);
    }

    public function descartarResultados(): void
    {
        $this->resultados = [];
    }

    protected function guardarArticulo(array $articulo): void
    {
        $artcod = $articulo['artcod'] ?? null;

        if (blank($artcod)) {

            $this->notificacion('Artículo no encontrado', 'No existe el código ingresado.', 'danger');

            $this->codigo = '';

            return;
        }

        // Cada captura es un renglón nuevo, aunque se repita el artículo.
        RecepcionDetalle::create([
            'recepcion_id' => $this->recepcion,
            'artcod' => $artcod,
            'artdes' => $articulo['artdes'] ?? null,
            'cantidad' => $this->cantidad,
        ]);

        $this->codigo = '';
        $this->cantidad = 1;

        $this->notificacion('Artículo agregado', $artcod . ' (' . $this->detalles()->count() . ' renglones)', 'success');

        $this->dispatch('focus-input');
    }

    /** Elimina un renglón de la carga (por id, no por artículo, porque se puede repetir). */
    public function eliminar($id): void
    {
        $detalle = RecepcionDetalle::query()
            ->where('recepcion_id', $this->recepcion)
            ->find($id);

        if (! $detalle) {
            return;
        }

        $detalle->delete();

        $this->notificacion('Artículo eliminado', $detalle->artcod, 'success');
    }

    public function cancelar(): void
    {
        if ($this->recepcion) {

            Recepcion::where('id', $this->recepcion)->delete();

            $this->recepcion = null;
        }

        $this->reset('ptovta', 'hoja', 'comprobante', 'codigo', 'resultados', 'busquedaProveedor', 'cabeceraVisible');

        $this->hoja = '1';
        $this->cantidad = 1;
        $this->fecha = now()->format('Y-m-d');

        $this->notificacion('Carga cancelada', 'Se borraron los artículos ingresados.', 'success');
    }

    public function finalizar(): void
    {
        $recepcion = $this->recepcion ? Recepcion::find($this->recepcion) : null;

        if (! $recepcion || ! $recepcion->detalles()->exists()) {

            $this->notificacion('Sin artículos', 'No hay ninguna recepción con artículos cargados.', 'danger');

            return;
        }

        $fecha = date('d-m-Y', strtotime($recepcion->fecha));

        $articulos = $recepcion->detalles
            ->map(fn ($detalle) => [
                'artcod' => $detalle->artcod,
                'stkcan' => round((float) $detalle->cantidad, 2),
            ])
            ->values()
            ->all();

        try {
            $mensajePaso1 = $this->api()->recepcionPaso1($fecha, $recepcion->deposito_nom, $recepcion->proveedor_nom);

            if (str_contains($mensajePaso1, 'El proveedor no se encontró')) {

                $recepcion->update(['estado' => 'error', 'mensaje' => $mensajePaso1]);

                $this->reiniciar();

                $this->notificacion('Proveedor no encontrado', $mensajePaso1, 'danger');

                return;
            }

            $this->api()->recepcionPaso2($fecha, $recepcion->ptovta, $recepcion->hoja);

            $respuesta = $this->api()->recepcionPaso3(
                $fecha,
                $recepcion->ptovta,
                $recepcion->proveedor_cod,
                $recepcion->comprobante,
                $recepcion->deposito_cod,
                $articulos
            );

            $mensaje = is_array($respuesta) ? (string) ($respuesta['message'] ?? '') : (string) $respuesta;

        } catch (Exception $e) {

            $recepcion->update(['estado' => 'error', 'mensaje' => $e->getMessage()]);

            $this->reiniciar();

            $this->notificacion('No se pudo guardar', $e->getMessage(), 'danger');

            return;
        }

        $errores = [
            'Error al guardar informe de recepción',
            'El proveedor no se encontró',
            'duplicados del número de comprobante',
            'Debe ingresar el número de comprobante manualmente',
        ];

        $conError = ! str_contains($mensaje, 'creado')
            || collect($errores)->contains(fn ($error) => str_contains($mensaje, $error));

        $recepcion->update([
            'estado' => $conError ? 'error' : 'finalizada',
            'mensaje' => $mensaje,
        ]);

        $this->reiniciar();

        if ($conError) {
            $this->notificacion('Recepción con errores', $mensaje, 'danger');

            return;
        }

        $this->notificacion('Recepción guardada', $mensaje ?: 'La recepción se registró correctamente.', 'success');
    }

    protected function reiniciar(): void
    {
        $this->recepcion = null;

        $this->reset('ptovta', 'hoja', 'comprobante', 'codigo', 'resultados', 'busquedaProveedor', 'cabeceraVisible');

        $this->hoja = '1';
        $this->cantidad = 1;
        $this->fecha = now()->format('Y-m-d');
    }

    protected function borrador(): ?Recepcion
    {
        return Recepcion::query()
            ->where('estado', 'borrador')
            ->where('user_id', auth()->user()?->sisusrcod)
            ->latest('id')
            ->first();
    }

    protected function cargarCabecera(Recepcion $recepcion): void
    {
        $this->recepcion = $recepcion->id;
        $this->fecha = $recepcion->fecha;
        $this->deposito = $recepcion->deposito_cod;
        $this->proveedor = $recepcion->proveedor_cod;
        $this->ptovta = $recepcion->ptovta;
        $this->hoja = $recepcion->hoja;
        $this->comprobante = $recepcion->comprobante;
    }

    protected function api(): SisApiClient
    {
        return app(SisApiClient::class);
    }

    protected function comprobanteErp(): ComprobanteErp
    {
        return app(ComprobanteErp::class);
    }

    /**
     * Consulta al ERP para la vista: si falla devuelve una lista vacía.
     */
    protected function consultar(callable $consulta): array
    {
        try {
            return $consulta();
        } catch (Exception $e) {
            Log::warning('SIS API en la pantalla de recepcion: ' . $e->getMessage());

            $this->errorApi = $e->getMessage();

            return [];
        }
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

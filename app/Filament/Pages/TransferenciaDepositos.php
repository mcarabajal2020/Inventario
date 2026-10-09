<?php

namespace App\Filament\Pages;

use App\Models\Transferencia;
use App\Models\TransferenciaDetalle;
use App\Services\SisApiClient;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TransferenciaDepositos extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected string $view = 'filament.pages.transferencia-depositos';

    protected static ?string $navigationLabel = 'Transferencia entre Depósitos';

    protected static ?string $title = 'Transferencia entre Depósitos';

    protected static ?string $slug = 'transferencias';

    public ?int $transferencia = null;

    public string $fecha = '';

    public string $depositoOrigen = '';

    public string $depositoDestino = '';

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
            $this->api()->numeracion($this->depositoOrigen);
        } catch (Exception $e) {
            $this->errorApi = $e->getMessage();

            $this->notificacion('API del ERP', $e->getMessage(), 'danger');
        }
    }

    /**
     * Se usa en la vista: si el ERP no responde la pantalla queda vacía
     * en lugar de tirar un error 500.
     */
    public function depositos(): array
    {
        try {
            return $this->api()->depositos();
        } catch (Exception $e) {
            Log::warning('SIS API en la pantalla de transferencias: ' . $e->getMessage());

            $this->errorApi = $e->getMessage();

            return [];
        }
    }

    /**
     * Si la numeración es automática el comprobante lo asigna el ERP.
     */
    public function numeracionManual(): bool
    {
        try {
            $numeracion = $this->api()->numeracion($this->depositoOrigen);
        } catch (Exception $e) {
            Log::warning('SIS API en la pantalla de transferencias: ' . $e->getMessage());

            return true;
        }

        return ($numeracion['manual'] ?? true) !== false;
    }

    public function detalles(): Collection
    {
        if (! $this->transferencia) {
            return collect();
        }

        return TransferenciaDetalle::query()
            ->where('transferencia_id', $this->transferencia)
            ->orderBy('id')
            ->get();
    }

    public function ultimasTransferencias(): Collection
    {
        return Transferencia::query()->latest('id')->limit(10)->get();
    }

    public function iniciar(): void
    {
        if (blank($this->depositoOrigen) || blank($this->depositoDestino)) {

            $this->notificacion('Datos incompletos', 'Seleccione el depósito origen y el destino.', 'danger');

            return;
        }

        if ($this->depositoOrigen === $this->depositoDestino) {

            $this->notificacion('Depósitos iguales', 'El depósito origen y destino deben ser distintos.', 'danger');

            return;
        }

        $manual = $this->numeracionManual();

        if ($manual && blank($this->comprobante)) {

            $this->notificacion('Comprobante requerido', 'La numeración es manual: ingrese el número de comprobante.', 'danger');

            return;
        }

        if ($manual && ! is_numeric($this->comprobante)) {

            $this->notificacion('Comprobante inválido', 'El número de comprobante debe ser numérico.', 'danger');

            return;
        }

        try {
            $depositos = collect($this->api()->depositos());
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        $origen = $depositos->firstWhere('depcod', $this->depositoOrigen);
        $destino = $depositos->firstWhere('depcod', $this->depositoDestino);

        if (! $origen || ! $destino) {

            $this->notificacion('Datos inválidos', 'El depósito seleccionado no existe en el ERP.', 'danger');

            return;
        }

        $transferencia = $this->borrador() ?? new Transferencia();

        $transferencia->fill([
            'fecha' => $this->fecha,
            'deposito_origen_cod' => $origen['depcod'],
            'deposito_origen_nom' => $origen['depnom'],
            'deposito_destino_cod' => $destino['depcod'],
            'deposito_destino_nom' => $destino['depnom'],
            'comprobante' => $this->comprobante,
            'estado' => 'borrador',
        ])->save();

        $this->transferencia = $transferencia->id;

        // Con la transferencia abierta, lo importante es el campo de carga.
        $this->cabeceraVisible = false;

        $this->notificacion('Transferencia iniciada', 'Ahora puede escanear los artículos.', 'success');
    }

    /** Pliega o despliega la cabecera de la transferencia. */
    public function alternarCabecera(): void
    {
        $this->cabeceraVisible = ! $this->cabeceraVisible;
    }

    /** Resumen de la cabecera para mostrarla plegada (una sola línea). */
    public function resumenCabecera(): string
    {
        $origen = collect($this->depositos())->firstWhere('depcod', $this->depositoOrigen);
        $destino = collect($this->depositos())->firstWhere('depcod', $this->depositoDestino);

        return collect([
            filled($this->fecha) ? date('d/m/Y', strtotime($this->fecha)) : '',
            collect([$origen['depnom'] ?? '', $destino['depnom'] ?? ''])->filter()->implode(' → '),
            $this->numeracionManual() && filled($this->comprobante)
                ? 'Comprobante ' . $this->comprobante
                : '',
        ])->filter()->implode(' · ');
    }

    public function agregar(): void
    {
        if (! $this->transferencia) {

            $this->notificacion('Sin transferencia', 'Primero ingrese los datos de cabecera.', 'danger');

            return;
        }

        if (blank($this->codigo)) {
            return;
        }

        if ($this->cantidad <= 0) {

            $this->notificacion('Cantidad inválida', 'Ingrese una cantidad mayor a cero.', 'danger');

            return;
        }

        $transferencia = Transferencia::find($this->transferencia);

        if (! $transferencia) {

            $this->transferencia = null;

            $this->notificacion('Transferencia inexistente', 'Vuelva a iniciar la transferencia.', 'danger');

            return;
        }

        $this->resultados = [];

        try {
            $articulos = $this->api()->stockPorDeposito($this->codigo, $transferencia->deposito_origen_cod);
        } catch (Exception $e) {
            $this->notificacion('API del ERP', $e->getMessage(), 'danger');

            return;
        }

        if (empty($articulos)) {

            $this->notificacion('Artículo no encontrado', 'No hay stock de ese artículo en el depósito origen.', 'danger');

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
        $stock = collect($this->resultados)->firstWhere('articulo.codigo', $artcod);

        $this->resultados = [];

        if (! $stock) {
            return;
        }

        $this->guardarArticulo($stock);
    }

    public function descartarResultados(): void
    {
        $this->resultados = [];
    }

    protected function guardarArticulo(array $stock): void
    {
        $artcod = $stock['articulo']['codigo'] ?? null;
        $artdes = $stock['articulo']['descripcion'] ?? null;

        if (blank($artcod)) {

            $this->notificacion('Artículo no encontrado', 'No hay stock de ese artículo en el depósito origen.', 'danger');

            $this->codigo = '';

            return;
        }

        // Cada captura es un renglón nuevo, aunque se repita el artículo.
        TransferenciaDetalle::create([
            'transferencia_id' => $this->transferencia,
            'artcod' => $artcod,
            'artdes' => $artdes,
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
        $detalle = TransferenciaDetalle::query()
            ->where('transferencia_id', $this->transferencia)
            ->find($id);

        if (! $detalle) {
            return;
        }

        $detalle->delete();

        $this->notificacion('Artículo eliminado', $detalle->artcod, 'success');
    }

    public function cancelar(): void
    {
        if ($this->transferencia) {

            Transferencia::where('id', $this->transferencia)->delete();

            $this->transferencia = null;
        }

        $this->reset('comprobante', 'codigo', 'resultados', 'cabeceraVisible');

        $this->cantidad = 1;
        $this->fecha = now()->format('Y-m-d');

        $this->notificacion('Carga cancelada', 'Se borraron los artículos ingresados.', 'success');
    }

    public function finalizar(): void
    {
        $transferencia = $this->transferencia ? Transferencia::find($this->transferencia) : null;

        if (! $transferencia || ! $transferencia->detalles()->exists()) {

            $this->notificacion('Sin artículos', 'No hay ninguna transferencia con artículos cargados.', 'danger');

            return;
        }

        $articulos = $transferencia->detalles
            ->map(fn ($detalle) => [
                'artcod' => $detalle->artcod,
                'stkcan' => round((float) $detalle->cantidad, 2),
            ])
            ->values()
            ->all();

        try {
            $respuesta = $this->api()->transferir(
                $transferencia->comprobante,
                $transferencia->deposito_origen_cod,
                $transferencia->deposito_destino_cod,
                $articulos
            );
        } catch (Exception $e) {

            $transferencia->update(['estado' => 'error', 'mensaje' => $e->getMessage()]);

            $this->reiniciar();

            $this->notificacion('No se pudo guardar', $e->getMessage(), 'danger');

            return;
        }

        $mensaje = (string) ($respuesta['message'] ?? '');

        $conError = ! str_contains($mensaje, 'creado');

        $transferencia->update([
            'estado' => $conError ? 'error' : 'finalizada',
            'cbtnro' => $respuesta['cbtnro'] ?? null,
            'mensaje' => $mensaje,
        ]);

        $this->reiniciar();

        if ($conError) {
            $this->notificacion('Transferencia con errores', $mensaje ?: 'La API no devolvió confirmación.', 'danger');

            return;
        }

        $this->notificacion(
            'Transferencia guardada',
            $mensaje . ($respuesta['cbtnro'] ? ' Comprobante N°: ' . $respuesta['cbtnro'] : ''),
            'success'
        );
    }

    protected function reiniciar(): void
    {
        $this->transferencia = null;

        $this->reset('comprobante', 'codigo', 'resultados', 'cabeceraVisible');

        $this->cantidad = 1;
        $this->fecha = now()->format('Y-m-d');
    }

    protected function borrador(): ?Transferencia
    {
        return Transferencia::query()
            ->where('estado', 'borrador')
            ->where('user_id', auth()->user()?->sisusrcod)
            ->latest('id')
            ->first();
    }

    protected function cargarCabecera(Transferencia $transferencia): void
    {
        $this->transferencia = $transferencia->id;
        $this->fecha = $transferencia->fecha;
        $this->depositoOrigen = $transferencia->deposito_origen_cod;
        $this->depositoDestino = $transferencia->deposito_destino_cod;
        $this->comprobante = (string) $transferencia->comprobante;
    }

    protected function api(): SisApiClient
    {
        return app(SisApiClient::class);
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

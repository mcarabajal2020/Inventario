<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comprobantes de compras del ERP.
 *
 * La API del SIS no expone forma de consultarlos (el GET de
 * `compras-proveedores/recepcion-mercaderia/` devuelve 500 y el índice del
 * módulo sólo publica `zonas` y `cuentas`), así que se lee directo de la
 * base `mutualnew`.
 *
 * Se consulta en dos tablas: `comcbt` (comprobantes de compra) y `stkmov`
 * (movimientos de stock; `cbtcod = 80` y `tmscod = 6` es recepción de
 * mercadería). Una recepción exitosa deja la cabecera en `stkmov` con su
 * detalle en `stkmovart`, pero un intento de guardado interrumpido (por
 * ejemplo por un timeout de la API) puede dejar sólo la cabecera: eso igual
 * hay que limpiarlo en el ERP.
 *
 * La comparación al **abrir la carga** es **proveedor + punto de venta +
 * comprobante**: el mismo número se puede cargar para otro proveedor. Pero
 * al **guardar** manda la regla del ERP: su mensaje de duplicados
 * (*"Se encontraron duplicados del número de comprobante, para el centro
 * emisor y tipo de movimiento indicados"*) no mira la cuenta (el chequeo es
 * por `cemcod` + número + tipo de movimiento en `stkmov`), así que un número
 * que ya es recepción de otro proveedor lo rechaza igual en el paso3. Por
 * eso `recepcionEnErp()` busca sin filtrar por proveedor y se corre antes de
 * enviar, para cortar el guardado y avisar en vez de regalar un 500.
 *
 * Centro emisor (`cemcod`) = punto de venta, número = `cbtnro`.
 */
class ComprobanteErp
{
    /**
     * Datos del comprobante si ya está registrado en el ERP, o null si no
     * (o si la base no responde: en ese caso no se bloquea la carga).
     *
     * `origen` indica dónde se encontró (`comcbt` o `stkmov`), `parcial`
     * es true cuando sólo existe la cabecera del movimiento en `stkmov`
     * sin detalle: es una carga interrumpida que hay que limpiar en el ERP.
     *
     * Con `$proveedorCod` la búsqueda es por **proveedor + punto de venta +
     * comprobante** en las dos tablas (`comcbt.ctacod` y `stkmov.cmpctacod`):
     * si el comprobante existe pero con otra cuenta no es duplicado y se
     * devuelve null. Sin proveedor (`null`) se compara sólo por punto de
     * venta + comprobante, como antes.
     */
    public function buscar(int $puntoDeVenta, int $comprobante, ?int $proveedorCod = null): ?array
    {
        try {
            $fila = $this->comcbt($puntoDeVenta, $comprobante, $proveedorCod);

            if ($fila) {
                return [
                    'ptovta' => $fila->cemcod,
                    'comprobante' => $fila->cbtnro,
                    'fecha' => $fila->cbtfec,
                    'proveedor' => $fila->ctanom,
                    'proveedor_cod' => $fila->ctacod,
                    'usuario' => $fila->usrregis,
                    'origen' => 'comcbt',
                    'parcial' => false,
                ];
            }

            $movimiento = $this->stkmov($puntoDeVenta, $comprobante, $proveedorCod);
        } catch (Exception $e) {
            Log::warning('No se pudo consultar el comprobante en el ERP: ' . $e->getMessage());

            return null;
        }

        if (! $movimiento) {
            return null;
        }

        return $this->filaMovimiento($movimiento);
    }

    /**
     * Recepción existente en `stkmov` **sin mirar el proveedor**: es la
     * regla con la que realmente valida el ERP al guardar (el paso3 manda
     * centro emisor + número + tipo de movimiento y la cuenta no viaja en
     * el payload, así que el 1-2 que ya es recepción de otro proveedor lo
     * rechaza igual: *"Se encontraron duplicados del número de comprobante,
     * para el centro emisor y tipo de movimiento indicados."*).
     *
     * Se usa justo antes de enviar, para cortar el guardado y avisar en vez
     * de regalar un llamado al ERP que termina en 500.
     */
    public function recepcionEnErp(int $puntoDeVenta, int $comprobante): ?array
    {
        try {
            $movimiento = $this->stkmov($puntoDeVenta, $comprobante, null);
        } catch (Exception $e) {
            Log::warning('No se pudo consultar el movimiento en el ERP: ' . $e->getMessage());

            return null;
        }

        return $movimiento ? $this->filaMovimiento($movimiento) : null;
    }

    /**
     * Fila de `stkmov` en el arreglo con la misma forma que devuelve
     * `buscar()`.
     */
    protected function filaMovimiento(object $movimiento): array
    {
        return [
            'ptovta' => $movimiento->cemcod,
            'comprobante' => $movimiento->cbtnro,
            'fecha' => $movimiento->cbtfec,
            'proveedor' => null,
            'proveedor_cod' => $movimiento->cmpctacod,
            'usuario' => $movimiento->usrregis,
            'origen' => 'stkmov',
            'parcial' => (int) $movimiento->detalles === 0,
        ];
    }

    protected function comcbt(int $puntoDeVenta, int $comprobante, ?int $proveedorCod): ?object
    {
        $query = DB::connection('mutualnew')
            ->table('comcbt')
            ->select('cemcod', 'cbtnro', 'cbtcod', 'cbtfec', 'ctanom', 'ctacod', 'usrregis')
            ->where('cemcod', $puntoDeVenta)
            ->where('cbtnro', $comprobante)
            ->where('cbtcod', 5);

        if (! is_null($proveedorCod)) {
            $query->where('ctacod', $proveedorCod);
        }

        return $query->first();
    }

    protected function stkmov(int $puntoDeVenta, int $comprobante, ?int $proveedorCod): ?object
    {
        $query = DB::connection('mutualnew')
            ->table('stkmov')
            ->select('stkmov.cemcod', 'stkmov.cbtnro', 'stkmov.cbtfec', 'stkmov.cmpctacod', 'stkmov.usrregis')
            ->selectRaw('(SELECT COUNT(*) FROM stkmovart WHERE stkmovart.id = stkmov.id) as detalles')
            ->where('stkmov.cemcod', $puntoDeVenta)
            ->where('stkmov.cbtnro', $comprobante)
            ->where('stkmov.cbtcod', 80)
            ->where('stkmov.tmscod', 6)
            ->where('stkmov.inactivo', 0);

        if (! is_null($proveedorCod)) {
            $query->where('stkmov.cmpctacod', $proveedorCod);
        }

        return $query->first();
    }
}

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
 * El ERP valida sus duplicados ("Se encontraron duplicados del número de
 * comprobante, para el centro emisor y tipo de movimiento indicados") sobre
 * `stkmov` (movimientos de stock; `cbtcod = 80` y `tmscod = 6` es recepción
 * de mercadería), y no sobre `comcbt`. La validación no tiene en cuenta el
 * proveedor: el mismo número no se puede volver a usar en el mismo centro
 * emisor aunque sea otra cuenta. Una recepción exitosa deja la cabecera en
 * `stkmov` con su detalle en `stkmovart`, pero un intento de guardado
 * interrumpido (por ejemplo por un timeout de la API) puede dejar sólo la
 * cabecera: eso igual bloquea el siguiente guardado.
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
     * El ERP valida los duplicados por centro emisor + número + tipo de
     * movimiento, sin importar el proveedor: el mismo número se puede usar
     * para otro proveedor en `comcbt` (la numeración de ahí es por cuenta),
     * pero en `stkmov` con `tmscod = 6` (recepción, el tipo que crea esta
     * aplicación) nunca se reutiliza entre proveedores. Por eso la
     * búsqueda en `stkmov` no filtra por proveedor.
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

            $movimiento = $this->stkmov($puntoDeVenta, $comprobante);
        } catch (Exception $e) {
            Log::warning('No se pudo consultar el comprobante en el ERP: ' . $e->getMessage());

            return null;
        }

        if (! $movimiento) {
            return null;
        }

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

    protected function stkmov(int $puntoDeVenta, int $comprobante): ?object
    {
        return DB::connection('mutualnew')
            ->table('stkmov')
            ->select('stkmov.cemcod', 'stkmov.cbtnro', 'stkmov.cbtfec', 'stkmov.cmpctacod', 'stkmov.usrregis')
            ->selectRaw('(SELECT COUNT(*) FROM stkmovart WHERE stkmovart.id = stkmov.id) as detalles')
            ->where('stkmov.cemcod', $puntoDeVenta)
            ->where('stkmov.cbtnro', $comprobante)
            ->where('stkmov.cbtcod', 80)
            ->where('stkmov.tmscod', 6)
            ->where('stkmov.inactivo', 0)
            ->first();
    }
}

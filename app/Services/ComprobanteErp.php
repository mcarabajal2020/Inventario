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
 * tabla `comcbt` de la base `mutualnew`, que es la que el ERP usa para
 * validar sus duplicados: *"Se encontraron duplicados del número de
 * comprobante, para el centro emisor y tipo de movimiento indicados"*.
 *
 * Centro emisor (`cemcod`) = punto de venta, número = `cbtnro`.
 */
class ComprobanteErp
{
    /**
     * Datos del comprobante si ya está registrado en el ERP, o null si no
     * (o si la base no responde: en ese caso no se bloquea la carga).
     *
     * El ERP valida los duplicados por centro emisor + número + tipo de
     * movimiento + proveedor, así que la búsqueda filtra por los mismos
     * criterios: `cbtcod = 5` es recepción de mercadería y `ctacod` es
     * el código del proveedor. Sin esos filtros, números bajos (88, 100…)
     * aparecen repetidos con otros proveedores o tipos de comprobante.
     */
    public function buscar(int $puntoDeVenta, int $comprobante, ?int $proveedorCod = null): ?array
    {
        try {
            $query = DB::connection('mutualnew')
                ->table('comcbt')
                ->select('cemcod', 'cbtnro', 'cbtcod', 'cbtfec', 'ctanom', 'ctacod', 'usrregis')
                ->where('cemcod', $puntoDeVenta)
                ->where('cbtnro', $comprobante)
                ->where('cbtcod', 5);

            if (! is_null($proveedorCod)) {
                $query->where('ctacod', $proveedorCod);
            }

            $fila = $query->first();
        } catch (Exception $e) {
            Log::warning('No se pudo consultar el comprobante en el ERP: ' . $e->getMessage());

            return null;
        }

        if (! $fila) {
            return null;
        }

        return [
            'ptovta' => $fila->cemcod,
            'comprobante' => $fila->cbtnro,
            'fecha' => $fila->cbtfec,
            'proveedor' => $fila->ctanom,
            'usuario' => $fila->usrregis,
        ];
    }
}

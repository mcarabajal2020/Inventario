<?php

namespace App\Services;

use App\Models\Inventario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Compara lo contado en un inventario con el stock que tiene ese depósito
 * en el ERP (tabla `stksaldos`), marcando las diferencias y los artículos
 * con existencia que no fueron contados.
 *
 * Admite un corte por fecha: en vez del stock vigente se usa el stock a
 * esa fecha, reconstruido con los movimientos del ERP
 * (`stkmov` + `stkmovart` + `stktmstk`).
 */
class ComparadorInventario
{
    /**
     * Inicio de los datos de movimientos en el ERP (`MIN(stkmov.cbtfec)`).
     */
    public const FECHA_MINIMA = '2020-06-30';

    /**
     * Segundos que se cachea el stock reconstruido a una fecha: la
     * reconstrucción puede tardar decenas de segundos en fechas viejas
     * (no hay índice en `stkmovart.depcod`), así que el resultado se guarda
     * para que los filtros y la paginación no lo repitan.
     */
    public const TTL_STOCK_FECHA = 300;

    /**
     * Filas del control, ordenadas por estado (primero las diferencias,
     * luego los no contados y al final los que coinciden) y dentro de cada
     * estado por código de artículo.
     *
     * @param  string|null  $fecha  Corte en formato Y-m-d; null o la fecha
     *                              de hoy usa el stock vigente sin recalcular.
     * @return array<int, array{artcod: string, artdes: string, contado: float|null, stock: float, diferencia: float|null, estado: string}>
     */
    public function comparar(Inventario $inventario, ?string $fecha = null): array
    {
        $depcod = trim((string) $inventario->deposito);

        if ($depcod === '') {
            return [];
        }

        $contados = $this->contados($inventario->id);
        $saldos = $this->saldos($depcod, $fecha);
        $descripciones = $this->descripciones($contados, $saldos);

        $codigos = array_values(array_unique(array_merge(
            array_keys($contados),
            array_keys($saldos),
        )));

        $filas = [];

        foreach ($codigos as $artcod) {
            $contado = $contados[$artcod] ?? null;
            $stock = $saldos[$artcod]['stock'] ?? 0.0;

            // Sin existencia en el depósito y sin contar: no aporta nada.
            if ($contado === null && $stock == 0) {
                continue;
            }

            if ($contado === null) {
                // Existencia en el ERP (incluidos los saldos negativos)
                // que no aparece en el inventario.
                $estado = 'no_contado';
                $diferencia = null;
            } else {
                $diferencia = round($contado - $stock, 2);

                // -0.0 se normaliza para que no se muestre como "-0".
                $diferencia = $diferencia == 0 ? 0.0 : $diferencia;

                $estado = $diferencia == 0 ? 'sin_diferencia' : 'diferencia';
            }

            $filas[] = [
                'artcod' => (string) $artcod,
                'artdes' => (string) ($descripciones[$artcod] ?? ''),
                'contado' => $contado,
                'stock' => (float) $stock,
                'diferencia' => $diferencia,
                'estado' => $estado,
            ];
        }

        $orden = ['diferencia' => 0, 'no_contado' => 1, 'sin_diferencia' => 2];

        usort($filas, fn ($a, $b) => [$orden[$a['estado']], $a['artcod']]
            <=> [$orden[$b['estado']], $b['artcod']]);

        return $filas;
    }

    /**
     * Clave de cache del stock reconstruido a una fecha.
     */
    public function claveStock(string $depcod, string $fecha): string
    {
        return "stock-fecha.{$depcod}.{$fecha}";
    }

    /**
     * Fuerza una reconstrucción nueva (botón ACTUALIZAR). Con la fecha de
     * hoy no hay nada que olvidar: el stock vigente no se cachea.
     */
    public function olvidarStock(string $depcod, string $fecha): void
    {
        if ($fecha >= now()->toDateString()) {
            return;
        }

        Cache::forget($this->claveStock(trim($depcod), $fecha));
    }

    /**
     * Stock del depósito: vigente (hoy/null) o reconstruido a una fecha.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function saldos(string $depcod, ?string $fecha = null): array
    {
        $hoy = now()->toDateString();

        if ($fecha === null || $fecha >= $hoy) {
            return $this->saldosActuales($depcod);
        }

        return Cache::remember(
            $this->claveStock($depcod, $fecha),
            self::TTL_STOCK_FECHA,
            fn () => $this->stockEnFecha($depcod, $fecha)
        );
    }

    /**
     * Stock vigente del depósito con la descripción en la misma consulta.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function saldosActuales(string $depcod): array
    {
        $filas = DB::connection('mutualnew')->table('stksaldos as s')
            ->leftJoin('stkartic0 as a', DB::raw('a.artcod'), '=', DB::raw('TRIM(s.artcod)'))
            ->where('s.depcod', $depcod)
            ->selectRaw('TRIM(s.artcod) as artcod, SUM(s.stkcan) as stock, MAX(a.artdes) as artdes')
            ->groupByRaw('TRIM(s.artcod)')
            ->get();

        $saldos = [];

        foreach ($filas as $fila) {
            $saldos[$fila->artcod] = [
                'stock' => (float) $fila->stock,
                'artdes' => $fila->artdes,
            ];
        }

        return $saldos;
    }

    /**
     * Stock del depósito a una fecha, con dos direcciones según cuál tenga
     * menos movimientos que procesar (no hay índice en `stkmovart.depcod`,
     * así que el costo depende de la cantidad de movimientos que se une):
     *
     *  - Reciente: stock vigente − movimientos posteriores a la fecha.
     *  - Antigua: suma de los movimientos anteriores a la fecha (el saldo
     *    inicial de 2020 quedó cargado como movimientos "S.Inicial").
     *
     * Se elige por el punto medio del rango de fechas de `stkmov`.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function stockEnFecha(string $depcod, string $fecha): array
    {
        $rango = DB::connection('mutualnew')
            ->select('SELECT MIN(cbtfec) AS min, MAX(cbtfec) AS max FROM stkmov')[0] ?? null;

        $puntoMedio = $rango && $rango->min && $rango->max
            ? substr((string) $rango->min, 0, 10) . '~~' . substr((string) $rango->max, 0, 10)
            : null;

        if ($puntoMedio) {
            [$min, $max] = explode('~~', $puntoMedio);
            $puntoMedio = date('Y-m-d', (strtotime($min) + strtotime($max)) / 2);
        }

        $porLaDerecha = $puntoMedio === null || $fecha >= $puntoMedio;

        return $porLaDerecha
            ? $this->stockRestandoPosteriores($depcod, $fecha)
            : $this->stockSumandoAnteriores($depcod, $fecha);
    }

    /**
     * Stock = vigente − movimientos posteriores a la fecha.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function stockRestandoPosteriores(string $depcod, string $fecha): array
    {
        $saldos = $this->saldosActuales($depcod);

        $posteriores = $this->efectosPorArticulo($depcod, '>', $fecha);

        foreach ($posteriores as $artcod => $delta) {
            if (isset($saldos[$artcod])) {
                $saldos[$artcod]['stock'] = round($saldos[$artcod]['stock'] - $delta, 4);

                continue;
            }

            // Tuvo movimientos pero ya no tiene fila de saldo: el vigente es 0.
            $saldos[$artcod] = ['stock' => round(-$delta, 4), 'artdes' => null];
        }

        $this->completarDescripciones($saldos);

        return $saldos;
    }

    /**
     * Stock = suma de los movimientos anteriores o iguales a la fecha.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function stockSumandoAnteriores(string $depcod, string $fecha): array
    {
        $anteriores = $this->efectosPorArticulo($depcod, '<=', $fecha);

        $saldos = [];

        foreach ($anteriores as $artcod => $total) {
            $saldos[$artcod] = ['stock' => round($total, 4), 'artdes' => null];
        }

        $this->completarDescripciones($saldos);

        return $saldos;
    }

    /**
     * Efecto sobre el stock de cada artículo del depósito: suma de
     * `stkcan × afestk` (el catálogo `stktmstk` indica si el tipo suma o
     * resta), sin filtrar por `inactivo` porque el saldo vigente refleja
     * también los movimientos anulados (verificado contra `stksaldos`).
     *
     * @return array<string, float>
     */
    protected function efectosPorArticulo(string $depcod, string $operador, string $fecha): array
    {
        $filas = DB::connection('mutualnew')->table('stkmovart as a')
            ->join('stkmov as m', 'm.id', '=', 'a.id')
            ->join('stktmstk as t', 't.tmscod', '=', 'm.tmscod')
            ->where('a.depcod', $depcod)
            ->whereRaw("m.cbtfec {$operador} ?", [$fecha . ' 23:59:59'])
            ->whereRaw('t.afestk <> 0')
            ->selectRaw('TRIM(a.artcod) as artcod, SUM(a.stkcan * t.afestk) as efecto')
            ->groupByRaw('TRIM(a.artcod)')
            ->get();

        $efectos = [];

        foreach ($filas as $fila) {
            $efectos[$fila->artcod] = (float) $fila->efecto;
        }

        return $efectos;
    }

    /**
     * Busca en `stkartic0` las descripciones que faltan (por bloques, para
     * no mandar miles de códigos en un solo IN).
     *
     * @param  array<string, array{stock: float, artdes: string|null}>  $saldos
     */
    protected function completarDescripciones(array &$saldos): void
    {
        $faltantes = [];

        foreach ($saldos as $artcod => $saldo) {
            if ($saldo['artdes'] === null) {
                $faltantes[] = $artcod;
            }
        }

        if ($faltantes === []) {
            return;
        }

        foreach (array_chunk($faltantes, 1000) as $bloque) {
            $encontradas = DB::connection('mutualnew')->table('stkartic0')
                ->whereIn('artcod', $bloque)
                ->pluck('artdes', 'artcod');

            foreach ($encontradas as $artcod => $artdes) {
                $clave = trim((string) $artcod);

                if (isset($saldos[$clave]) && filled($artdes)) {
                    $saldos[$clave]['artdes'] = (string) $artdes;
                }
            }
        }
    }

    /**
     * Suma de lo capturado en el inventario por artículo (cada captura es
     * un renglón, así que se agrupa por código).
     *
     * @return array<string, float>
     */
    protected function contados(int $inventarioId): array
    {
        $filas = DB::table('inventario_movimientos')
            ->where('inventario_id', $inventarioId)
            ->selectRaw('TRIM(artcod) as artcod, SUM(cantidad) as cantidad')
            ->groupByRaw('TRIM(artcod)')
            ->get();

        $contados = [];

        foreach ($filas as $fila) {
            $contados[$fila->artcod] = (float) $fila->cantidad;
        }

        return $contados;
    }

    /**
     * Descripciones de todos los artículos involucrados: las que trae el
     * join de saldos más una consulta puntual (un solo whereIn) para los
     * códigos que faltan, que son los contados sin fila de stock en este
     * depósito.
     *
     * @param  array<string, float>  $contados
     * @param  array<string, array{stock: float, artdes: string|null}>  $saldos
     * @return array<string, string>
     */
    protected function descripciones(array $contados, array $saldos): array
    {
        $descripciones = [];

        foreach ($saldos as $artcod => $saldo) {
            if (filled($saldo['artdes'])) {
                $descripciones[$artcod] = (string) $saldo['artdes'];
            }
        }

        $faltantes = array_values(array_diff(array_keys($contados), array_keys($descripciones)));

        if ($faltantes === []) {
            return $descripciones;
        }

        $encontradas = DB::connection('mutualnew')->table('stkartic0')
            ->whereIn('artcod', $faltantes)
            ->pluck('artdes', 'artcod');

        foreach ($encontradas as $artcod => $artdes) {
            if (filled($artdes)) {
                $descripciones[trim((string) $artcod)] = (string) $artdes;
            }
        }

        return $descripciones;
    }
}

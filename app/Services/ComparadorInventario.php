<?php

namespace App\Services;

use App\Models\Inventario;
use Illuminate\Support\Facades\DB;

/**
 * Compara lo contado en un inventario con el stock que tiene ese depósito
 * en el ERP (tabla `stksaldos`), marcando las diferencias y los artículos
 * con existencia que no fueron contados.
 */
class ComparadorInventario
{
    /**
     * Filas del control, ordenadas por estado (primero las diferencias,
     * luego los no contados y al final los que coinciden) y dentro de cada
     * estado por código de artículo.
     *
     * @return array<int, array{artcod: string, artdes: string, contado: float|null, stock: float, diferencia: float|null, estado: string}>
     */
    public function comparar(Inventario $inventario): array
    {
        $depcod = trim((string) $inventario->deposito);

        if ($depcod === '') {
            return [];
        }

        $contados = $this->contados($inventario->id);
        $saldos = $this->saldos($depcod);
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
     * Stock del depósito en el ERP, sumado por artículo (el ERP puede
     * devolver varias filas por artículo) y con la descripción traída de
     * `stkartic0` en la misma consulta.
     *
     * El `artcod` de `stksaldos` viene a veces con espacios iniciales
     * (char(20) alineado a la derecha), por eso se compara con TRIM.
     *
     * @return array<string, array{stock: float, artdes: string|null}>
     */
    protected function saldos(string $depcod): array
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

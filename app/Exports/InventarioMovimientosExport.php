<?php

namespace App\Exports;

use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventarioMovimientosExport implements FromCollection, WithHeadings
{
    protected $inventarioId;

    public function __construct($inventarioId)
    {
        $this->inventarioId = $inventarioId;
    }

    public function collection(): Enumerable
    {
        $movimientos = DB::table('inventario_movimientos')
            ->where('inventario_id', $this->inventarioId)
            ->select(
                'id',
                'artcod',
                'codigo_barra',
                'cantidad',
                'ubicacion',
                'usuario',
                'created_at'
            )
            ->orderBy('id')
            ->get();

        $articulos = DB::connection('mutualnew')
            ->table('stkartic0')
            ->whereIn('artcod', $movimientos->pluck('artcod'))
            ->pluck('artdes', 'artcod');

        return $movimientos->map(function ($mov) use ($articulos) {

            return [

                'ID' => $mov->id,

                'ARTICULO' => $mov->artcod,

                'DESCRIPCION' => $articulos[$mov->artcod] ?? '',

                'CODIGO_BARRA' => $mov->codigo_barra,

                'CANTIDAD' => $mov->cantidad,

                'UBICACION' => $mov->ubicacion,

                'USUARIO' => $mov->usuario,

                'FECHA' => $mov->created_at,

            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'ARTICULO',
            'DESCRIPCION',
            'CODIGO_BARRA',
            'CANTIDAD',
            'UBICACION',
            'USUARIO',
            'FECHA',
        ];
    }
}

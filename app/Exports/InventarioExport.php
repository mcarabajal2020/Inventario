<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InventarioExport implements FromCollection, WithHeadings
{
    protected $inventarioId;

    public function __construct($inventarioId)
    {
        $this->inventarioId = $inventarioId;
    }

    public function collection()
    {
        $movimientos = DB::table('inventario_movimientos')
            ->where('inventario_id', $this->inventarioId)
            ->select(
                'artcod',
                DB::raw('SUM(cantidad) as total')
            )
            ->groupBy('artcod')
            ->orderBy('artcod')
            ->get();

        $articulos = DB::connection('mutualnew')
            ->table('stkartic0')
            ->whereIn('artcod', $movimientos->pluck('artcod'))
            ->pluck('artdes', 'artcod');

        return $movimientos->map(function ($mov) use ($articulos) {

            return [

                'ARTICULO' => $mov->artcod,

                'DESCRIPCION' => $articulos[$mov->artcod] ?? '',

                'CANTIDAD' => $mov->total,

            ];
        });
    }

    public function headings(): array
    {
        return [
            'ARTICULO',
            'DESCRIPCION',
            'CANTIDAD',
        ];
    }
}
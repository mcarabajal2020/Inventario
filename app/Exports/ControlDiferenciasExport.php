<?php

namespace App\Exports;

use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Exporta el control de diferencias con el filtro actual (estado +
 * búsqueda), sin paginar, para pasarlo a alguien en Excel.
 */
class ControlDiferenciasExport implements FromCollection, WithHeadings
{
    /**
     * @param  array<int, array{artcod: string, artdes: string, contado: float|null, stock: float, diferencia: float|null, estado: string}>  $filas
     */
    public function __construct(protected array $filas)
    {
    }

    public function collection(): Enumerable
    {
        $etiquetas = [
            'sin_diferencia' => 'Sin diferencia',
            'diferencia' => 'Diferencia',
            'no_contado' => 'No contado',
        ];

        return collect($this->filas)->map(fn ($fila) => [
            'artcod' => $fila['artcod'],
            'artdes' => $fila['artdes'],
            'contado' => $fila['contado'],
            'stock' => $fila['stock'],
            'diferencia' => $fila['diferencia'],
            'estado' => $etiquetas[$fila['estado']] ?? $fila['estado'],
        ]);
    }

    public function headings(): array
    {
        return ['Artículo', 'Descripción', 'Contado', 'Stock ERP', 'Diferencia', 'Estado'];
    }
}

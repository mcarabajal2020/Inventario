<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioMovimiento extends Model
{
    protected $table = 'inventario_movimientos';

    public $timestamps = false;

    protected $fillable = [
        'inventario_id',
        'artcod',
        'codigo_barra',
        'cantidad',
        'ubicacion',
        'usuario',
        'created_at',
    ];
}
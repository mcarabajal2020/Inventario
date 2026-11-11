<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Depósito habilitado para un usuario en Consulta de Stock. Sin filas
 * para un usuario, ve todos los depósitos.
 */
class UsuarioDeposito extends Model
{
    protected $table = 'usuario_depositos';

    protected $fillable = [
        'sisusrcod',
        'depcod',
    ];
}

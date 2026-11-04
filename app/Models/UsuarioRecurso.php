<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Habilitación/bloqueo de un recurso (sección del menú) para un usuario
 * del ERP. Sin fila, el recurso está habilitado.
 */
class UsuarioRecurso extends Model
{
    protected $table = 'usuario_recursos';

    protected $fillable = [
        'sisusrcod',
        'recurso',
        'habilitado',
    ];

    protected $casts = [
        'habilitado' => 'boolean',
    ];
}

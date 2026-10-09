<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recepcion extends Model
{
    /**
     * La tabla se declara a mano porque el pluralizador inglés da
     * "recepcions" en lugar de "recepciones".
     */
    protected $table = 'recepciones';

    protected $fillable = [
        'fecha',
        'deposito_cod',
        'deposito_nom',
        'proveedor_cod',
        'proveedor_nom',
        'ptovta',
        'hoja',
        'comprobante',
        'estado',
        'mensaje',
        'user_id',
    ];

    protected static function booted()
    {
        static::creating(function ($recepcion) {

            $recepcion->user_id ??= auth()->user()?->sisusrcod;

        });
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RecepcionDetalle::class)->orderBy('id');
    }
}

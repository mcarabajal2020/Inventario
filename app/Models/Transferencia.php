<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transferencia extends Model
{
    protected $fillable = [
        'fecha',
        'deposito_origen_cod',
        'deposito_origen_nom',
        'deposito_destino_cod',
        'deposito_destino_nom',
        'comprobante',
        'cbtnro',
        'estado',
        'mensaje',
        'user_id',
    ];

    protected static function booted()
    {
        static::creating(function ($transferencia) {

            $transferencia->user_id ??= auth()->user()?->sisusrcod;

        });
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(TransferenciaDetalle::class)->orderBy('id');
    }
}

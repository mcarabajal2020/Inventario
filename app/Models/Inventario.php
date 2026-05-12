<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventario extends Model
{
    protected $fillable = [
        'sucursal',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'user_id',
    ];

    protected static function booted()
    {
        static::creating(function ($inventario) {

            $inventario->user_id = auth()->user()->sisusrcod;

        });
    }
}
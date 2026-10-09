<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecepcionDetalle extends Model
{
    protected $fillable = [
        'recepcion_id',
        'artcod',
        'artdes',
        'cantidad',
    ];

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(Recepcion::class);
    }
}

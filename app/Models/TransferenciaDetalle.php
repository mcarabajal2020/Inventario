<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaDetalle extends Model
{
    protected $fillable = [
        'transferencia_id',
        'artcod',
        'artdes',
        'cantidad',
    ];

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }
}

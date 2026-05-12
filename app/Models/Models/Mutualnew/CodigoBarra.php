<?php

namespace App\Models\Mutualnew;

use Illuminate\Database\Eloquent\Model;

class CodigoBarra extends Model
{
    protected $connection = 'mutualnew';

    protected $table = 'artbar';

    public $timestamps = false;

    protected $fillable = [
        'artcod',
        'artcodbar',
    ];
}
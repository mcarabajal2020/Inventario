<?php

namespace App\Models\Mutualnew;

use Illuminate\Database\Eloquent\Model;

class Articulo extends Model
{
    protected $connection = 'mutualnew';

    protected $table = 'stkartic0';

    protected $primaryKey = 'artcod';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'artcod',
        'artdes',
        'reposipre',
    ];
}
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;

class User extends Authenticatable implements FilamentUser, HasName
{
    protected $connection = 'siserpy';

    protected $table = 'sisusuar';

    protected $primaryKey = 'sisusrcod';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sisusrcod',
        'sisusrseg',
    ];

    protected $hidden = [
        'sisusrseg',
    ];

    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return true;
    }

    public function getFilamentName(): string
    {
        return (string) ($this->sisusrnom ?: $this->sisusrcod);
    }

    public function getAuthPassword()
    {
        return $this->sisusrseg;
    }

    public function getAuthIdentifierName()
    {
        return 'sisusrcod';
    }
}
<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseAuth;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;

class Login extends BaseAuth
{
    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sisusrcod')
                    ->label('Usuario')
                    ->required()
                    ->autofocus()
                    ->autocomplete(false),

                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->required(),
            ]);
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        if (! Auth::attempt([
            'sisusrcod' => $data['sisusrcod'],
            'password' => $data['password'],
        ])) {

            $this->addError(
                'data.sisusrcod',
                'Usuario o contraseña incorrectos'
            );

            return null;
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
<?php

namespace App\Providers;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('siserpy', function ($app, array $config) {
    
            return new class implements \Illuminate\Contracts\Auth\UserProvider {
    
                public function retrieveById($identifier)
                {
                    return User::find($identifier);
                }
    
                public function retrieveByToken($identifier, $token)
                {
                    return null;
                }
    
                public function updateRememberToken(
                    \Illuminate\Contracts\Auth\Authenticatable $user,
                    $token
                ) {
                }
    
                public function retrieveByCredentials(array $credentials)
                {
                    return User::where('sisusrcod', $credentials['sisusrcod'])->first();
                }
    
                public function validateCredentials(
                    \Illuminate\Contracts\Auth\Authenticatable $user,
                    array $credentials
                ) {
                    $password = trim($credentials['password']);
                
                    return str_contains(
                        trim($user->sisusrseg),
                        $password
                    );
                }
    
                public function rehashPasswordIfRequired(
                    \Illuminate\Contracts\Auth\Authenticatable $user,
                    array $credentials,
                    bool $force = false
                ) {
                    //
                }
            };
        });
    }
}

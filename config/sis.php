<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API del SIS (ERP)
    |--------------------------------------------------------------------------
    |
    | Se usa para recepción de mercadería y transferencias entre depósitos.
    | El token y la sesión se envían en los headers Authorization y Session.
    | El token se fija en `.env` con `SIS_API_TOKEN` (no se guarda en el código).
    |
    */

    'url' => env('SIS_API_URL', 'http://10.0.0.45:8000'),

    'token' => env('SIS_API_TOKEN'),

    'empresa' => env('SIS_EMPRESA'),

    // Credenciales fijas sólo como respaldo: por defecto se usa el
    // usuario ERP que tiene la sesión abierta en la aplicación.
    'username' => env('SIS_API_USER'),

    'password' => env('SIS_API_PASSWORD'),

    'timeout' => (int) env('SIS_API_TIMEOUT', 20),

    'session_cache' => env('SIS_SESSION_CACHE', 'sis.api.session'),

];

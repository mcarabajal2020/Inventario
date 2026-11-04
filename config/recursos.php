<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administradores de la sección Usuarios
    |--------------------------------------------------------------------------
    |
    | Sólo estos usuarios (código `sisusrcod` del ERP, separados por coma)
    | ven y pueden entrar a /admin/usuarios para habilitar o bloquear
    | recursos al resto. Además, el recurso "usuarios" del catálogo tiene
    | que estar habilitado para ellos (la pantalla no permite quitárselo
    | a uno mismo, para no quedar afuera).
    |
    */

    'admins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SIS_ADMINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Catálogo de recursos
    |--------------------------------------------------------------------------
    |
    | Cada recurso tiene un título (para los avisos y el menú) y las rutas
    | del panel que bloquea. Toda página nueva del panel hay que agregarla
    | acá: las rutas que no figuran no se bloquean.
    |
    | La raíz '/admin' se compara en forma exacta (si no, taparía a todas
    | las demás); el resto se compara por prefijo, así que
    | '/admin/inventarios' cubre también /create, /{id}/edit y el
    | /admin/tomar-inventario/{id} de la colectora.
    |
    */

    'catalogo' => [
        'dashboard' => [
            'titulo' => 'Dashboard',
            'rutas' => ['/admin'],
        ],
        'inventarios' => [
            'titulo' => 'Inventarios',
            'rutas' => ['/admin/inventarios', '/admin/tomar-inventario'],
        ],
        'recepcion' => [
            'titulo' => 'Recepción de Mercadería',
            'rutas' => ['/admin/recepcion-mercaderia'],
        ],
        'transferencias' => [
            'titulo' => 'Transferencia entre Depósitos',
            'rutas' => ['/admin/transferencias'],
        ],
        'consulta-stock' => [
            'titulo' => 'Consulta de Stock',
            'rutas' => ['/admin/consulta-stock'],
        ],
        'control-diferencias' => [
            'titulo' => 'Control de Diferencias',
            'rutas' => ['/admin/control-diferencias'],
        ],
        'usuarios' => [
            'titulo' => 'Usuarios',
            'rutas' => ['/admin/usuarios'],
        ],
    ],

];

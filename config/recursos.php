<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administradores
    |--------------------------------------------------------------------------
    |
    | Estos usuarios (código `sisusrcod` del ERP, separados por coma) ven
    | por defecto los recursos marcados como "restringidos" (Consulta de
    | Stock, Usuarios y Control de Diferencias) además de todos los
    | comunes. Un admin puede habilitar o bloquear cualquier recurso a
    | cualquier usuario desde /admin/usuarios; la pantalla no le permite
    | quitarse la sección Usuarios a sí mismo, para no quedar afuera.
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
    | 'restringido' => true: sin fila en `usuario_recursos` sólo lo tienen
    | los administradores del SIS_ADMINS; el resto necesita que se lo
    | habilite manualmente desde /admin/usuarios. Sin la marca, el recurso
    | está habilitado para todos los usuarios del ERP por defecto.
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
            'restringido' => true,
        ],
        'control-diferencias' => [
            'titulo' => 'Control de Diferencias',
            'rutas' => ['/admin/control-diferencias'],
            'restringido' => true,
        ],
        'usuarios' => [
            'titulo' => 'Usuarios',
            'rutas' => ['/admin/usuarios'],
            'restringido' => true,
        ],
    ],

];

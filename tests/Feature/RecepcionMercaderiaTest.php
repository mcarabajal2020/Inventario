<?php

namespace Tests\Feature;

use App\Filament\Pages\RecepcionMercaderia;
use App\Models\Recepcion;
use App\Models\RecepcionDetalle;
use App\Models\User;
use App\Services\ComprobanteErp;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class RecepcionMercaderiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'sis.empresa' => 'TEST',
            'sis.username' => null,
            'sis.password' => null,
        ]);

        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        $this->actingAs($user);

        // Por defecto el comprobante no existe en el ERP.
        $this->stubComprobanteErp();
    }

    /**
     * Reemplaza la consulta de comprobantes del ERP (`mutualnew.comcbt`):
     * `$fila` = datos del comprobante si ya existe, `$fallo` = si la base
     * del negocio no responde.
     */
    private function stubComprobanteErp(?array $fila = null, ?Exception $fallo = null): void
    {
        $stub = Mockery::mock(ComprobanteErp::class);

        if ($fallo) {
            $stub->shouldReceive('buscar')->andThrow($fallo);
        } else {
            $stub->shouldReceive('buscar')->andReturn($fila);
        }

        $this->instance(ComprobanteErp::class, $stub);
    }

    private function fakeSis(array $adicional = []): void
    {
        Http::fake(array_merge([
            '*/stock/depositos/*' => Http::response([
                ['depcod' => 'D1', 'depnom' => 'Depósito Central', 'succod' => 1],
                ['depcod' => 'D2', 'depnom' => 'Depósito Sucursal', 'succod' => 2],
            ]),
            '*/compras-proveedores/cuentas/*' => Http::response([
                ['ctacod' => 'P1', 'ctanom' => 'Proveedor Test'],
            ]),
            '*/stock/movimientos/numeracion/*' => Http::response(['manual' => true]),
        ], $adicional, [
            '*' => Http::response([]),
        ]));
    }

    private function iniciarRecepcion()
    {
        return Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '1')
            ->set('hoja', '2')
            ->set('comprobante', '3')
            ->call('iniciar');
    }

    public function test_muestra_la_pagina_con_depositos_y_proveedores(): void
    {
        $this->fakeSis();

        Livewire::test(RecepcionMercaderia::class)
            ->assertSee('Cabecera de la factura')
            ->assertSee('Depósito Central')
            ->assertSee('Proveedor Test');
    }

    public function test_inicia_la_recepcion_y_guarda_un_borrador(): void
    {
        $this->fakeSis();

        $component = $this->iniciarRecepcion();

        $this->assertNotNull($component->get('recepcion'));

        $this->assertDatabaseHas('recepciones', [
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '1',
            'hoja' => '2',
            'comprobante' => '3',
            'estado' => 'borrador',
            'user_id' => 'test-user',
        ]);
    }

    public function test_no_inicia_si_no_completo_la_cabecera(): void
    {
        $this->fakeSis();

        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->call('iniciar');

        $this->assertNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 0);
    }

    public function test_cada_captura_del_mismo_articulo_es_un_renglon(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', '7790000000001')
            ->set('cantidad', 2)
            ->call('agregar')
            ->set('codigo', '7790000000001')
            ->set('cantidad', 3)
            ->call('agregar');

        $this->assertDatabaseCount('recepcion_detalles', 2);

        $detalles = RecepcionDetalle::orderBy('id')->get();

        $this->assertSame('A1', $detalles[0]->artcod);
        $this->assertSame('Artículo Test', $detalles[0]->artdes);
        $this->assertEquals(2, $detalles[0]->cantidad);

        $this->assertSame('A1', $detalles[1]->artcod);
        $this->assertEquals(3, $detalles[1]->cantidad);
    }

    public function test_no_agrega_si_el_articulo_no_existe(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([]),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'NO-EXISTE')
            ->set('cantidad', 1)
            ->call('agregar');

        $this->assertDatabaseCount('recepcion_detalles', 0);
    }

    public function test_al_encontrar_varios_articulos_muestra_la_lista_para_elegir(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental Uno'],
                ['artcod' => 'A2', 'artdes' => 'Cepillo Dental Dos'],
            ]),
        ]);

        $component = $this->iniciarRecepcion()
            ->set('codigo', 'CEPILLO')
            ->call('agregar');

        $this->assertDatabaseCount('recepcion_detalles', 0);

        $this->assertCount(2, $component->get('resultados'));

        $component->assertSee('Cepillo Dental Uno')
            ->assertSee('Cepillo Dental Dos');
    }

    public function test_al_elegir_un_articulo_de_la_lista_lo_agrega(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental Uno'],
                ['artcod' => 'A2', 'artdes' => 'Cepillo Dental Dos'],
            ]),
        ]);

        $component = $this->iniciarRecepcion()
            ->set('codigo', 'CEPILLO')
            ->call('agregar')
            ->call('seleccionar', 'A2');

        $this->assertDatabaseCount('recepcion_detalles', 1);

        $detalle = RecepcionDetalle::first();

        $this->assertSame('A2', $detalle->artcod);
        $this->assertSame('Cepillo Dental Dos', $detalle->artdes);

        $this->assertCount(0, $component->get('resultados'));
        $this->assertSame('', $component->get('codigo'));
    }

    public function test_elimina_un_renglon_de_la_carga_sin_tocar_las_repeticiones(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
        ]);

        $component = $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 2)
            ->call('agregar')
            ->set('codigo', 'A1')
            ->set('cantidad', 3)
            ->call('agregar');

        $this->assertDatabaseCount('recepcion_detalles', 2);

        $primer = RecepcionDetalle::orderBy('id')->first();

        $component->call('eliminar', $primer->id);

        $this->assertDatabaseCount('recepcion_detalles', 1);

        $this->assertEquals(3, RecepcionDetalle::first()->cantidad);
    }

    public function test_finaliza_y_envia_la_recepcion_al_erp(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
            '*/recepcion-mercaderia/paso1/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso2/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso3/*' => Http::response(['message' => 'Movimiento de stock creado']),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 4)
            ->call('agregar')
            ->call('finalizar');

        $recepcion = Recepcion::first();

        $this->assertSame('finalizada', $recepcion->estado);
        $this->assertSame('Movimiento de stock creado', $recepcion->mensaje);
        $this->assertCount(1, $recepcion->detalles);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/recepcion-mercaderia/paso1/')
                && $request['fecha'] === date('d-m-Y')
                && $request['deposito'] === 'Depósito Central'
                && $request['proveedor'] === 'Proveedor Test';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/recepcion-mercaderia/paso3/')
                && $request['depcod'] === 'D1'
                && $request['ctacod'] === 'P1'
                && $request['cbtnro'] === '3'
                && $request['articulos'][0]['artcod'] === 'A1'
                && $request['articulos'][0]['stkcan'] == 4;
        });
    }

    public function test_finaliza_enviando_un_renglon_por_cada_captura(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
            '*/recepcion-mercaderia/paso1/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso2/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso3/*' => Http::response(['message' => 'Movimiento de stock creado']),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 2)
            ->call('agregar')
            ->set('codigo', 'A1')
            ->set('cantidad', 3)
            ->call('agregar')
            ->call('finalizar');

        $this->assertDatabaseCount('recepciones', 1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/recepcion-mercaderia/paso3/')
                && count($request['articulos']) === 2
                && $request['articulos'][0]['artcod'] === 'A1'
                && $request['articulos'][0]['stkcan'] == 2
                && $request['articulos'][1]['artcod'] === 'A1'
                && $request['articulos'][1]['stkcan'] == 3;
        });
    }

    public function test_se_detiene_si_el_proveedor_no_existe(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
            '*/recepcion-mercaderia/paso1/*' => Http::response(['message' => 'El proveedor no se encontró.'], 400),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 4)
            ->call('agregar')
            ->call('finalizar');

        $recepcion = Recepcion::first();

        $this->assertSame('error', $recepcion->estado);
        $this->assertSame('El proveedor no se encontró.', $recepcion->mensaje);

        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), '/paso3/'))->count());
    }

    public function test_marca_error_si_el_erp_rechaza_la_recepcion(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
            '*/recepcion-mercaderia/paso1/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso2/*' => Http::response(['message' => 'OK']),
            '*/recepcion-mercaderia/paso3/*' => Http::response(['message' => 'Error al guardar informe de recepción'], 400),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 4)
            ->call('agregar')
            ->call('finalizar');

        $recepcion = Recepcion::first();

        $this->assertSame('error', $recepcion->estado);
        $this->assertSame('Error al guardar informe de recepción', $recepcion->mensaje);
    }

    public function test_cancelar_borra_el_borrador(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
        ]);

        $this->iniciarRecepcion()
            ->set('codigo', 'A1')
            ->set('cantidad', 1)
            ->call('agregar')
            ->call('cancelar');

        $this->assertDatabaseCount('recepciones', 0);
        $this->assertDatabaseCount('recepcion_detalles', 0);
    }

    public function test_el_campo_hoja_trae_el_1_por_defecto_y_vuelve_a_1_al_cancelar(): void
    {
        $this->fakeSis();

        $component = Livewire::test(RecepcionMercaderia::class);

        $this->assertSame('1', $component->get('hoja'));

        $component->set('hoja', '7')
            ->call('cancelar');

        $this->assertSame('1', $component->get('hoja'));
    }

    public function test_no_inicia_si_el_comprobante_ya_fue_ingresado(): void
    {
        $this->fakeSis();

        Recepcion::create([
            'fecha' => '2026-10-01',
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '0001',
            'hoja' => '0002',
            'comprobante' => '00003',
            'estado' => 'finalizada',
            'user_id' => 'otro-usuario',
        ]);

        // Números cargados sin los ceros: sigue siendo el mismo comprobante.
        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '1')
            ->set('hoja', '2')
            ->set('comprobante', '3')
            ->call('iniciar')
            ->assertNotified('Comprobante ya ingresado');

        $this->assertNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 1);
    }

    public function test_no_inicia_si_el_erp_rechazo_el_comprobante_por_duplicado(): void
    {
        $this->fakeSis();

        Recepcion::create([
            'fecha' => '2026-10-08',
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '2',
            'hoja' => '1',
            'comprobante' => '310',
            'estado' => 'error',
            'mensaje' => 'Se encontraron duplicados del número de comprobante, para el centro emisor y tipo de movimiento indicados.',
            'user_id' => 'otro-usuario',
        ]);

        // Mismo comprobante que el ERP rechazó: no se vuelve a intentar.
        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '2')
            ->set('hoja', '1')
            ->set('comprobante', '310')
            ->call('iniciar')
            ->assertNotified('Comprobante ya ingresado');

        $this->assertNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 1);
    }

    public function test_permite_reintentar_un_comprobante_que_fallo_por_otro_motivo(): void
    {
        $this->fakeSis();

        Recepcion::create([
            'fecha' => '2026-10-08',
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '2',
            'hoja' => '1',
            'comprobante' => '310',
            'estado' => 'error',
            'mensaje' => 'El proveedor no se encontró.',
            'user_id' => 'otro-usuario',
        ]);

        // El fallo fue por el proveedor, no por duplicado: se puede reintentar.
        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '2')
            ->set('hoja', '1')
            ->set('comprobante', '310')
            ->call('iniciar')
            ->assertNotNotified('Comprobante ya ingresado');

        $this->assertNotNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 2);
    }

    public function test_no_se_olvida_del_borrador_propio_al_volver_a_iniciar(): void
    {
        $this->fakeSis();

        $component = $this->iniciarRecepcion();

        $recepcionId = $component->get('recepcion');

        $component->call('iniciar')->assertNotNotified('Comprobante ya ingresado');

        $this->assertSame($recepcionId, $component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 1);
    }

    public function test_permite_iniciar_con_un_comprobante_distinto(): void
    {
        $this->fakeSis();

        Recepcion::create([
            'fecha' => '2026-10-01',
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '1',
            'hoja' => '2',
            'comprobante' => '3',
            'estado' => 'finalizada',
            'user_id' => 'otro-usuario',
        ]);

        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '1')
            ->set('hoja', '2')
            ->set('comprobante', '4')
            ->call('iniciar');

        $this->assertNotNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 2);
    }

    public function test_el_listado_muestra_el_comprobante_como_ptovta_comprobante_hoja(): void
    {
        $this->fakeSis();

        Recepcion::create([
            'fecha' => '2026-10-01',
            'deposito_cod' => 'D1',
            'deposito_nom' => 'Depósito Central',
            'proveedor_cod' => 'P1',
            'proveedor_nom' => 'Proveedor Test',
            'ptovta' => '4',
            'hoja' => '7',
            'comprobante' => '12345',
            'estado' => 'finalizada',
            'user_id' => 'otro-usuario',
        ]);

        Livewire::test(RecepcionMercaderia::class)
            ->assertSee('4-12345 / 7')
            ->assertDontSee('4-7-12345');
    }

    public function test_no_inicia_si_el_comprobante_ya_existe_en_el_erp(): void
    {
        $this->fakeSis();

        // Comprobante cargado fuera de esta aplicación: sólo existe en el ERP.
        $this->stubComprobanteErp([
            'ptovta' => 3,
            'comprobante' => 77412,
            'fecha' => '2026-10-08 00:00:00',
            'proveedor' => 'JUAN MANUEL HIGONET',
            'usuario' => 'pablo',
        ]);

        $component = Livewire::test(RecepcionMercaderia::class)
            ->set('deposito', 'D1')
            ->set('proveedor', 'P1')
            ->set('ptovta', '3')
            ->set('hoja', '1')
            ->set('comprobante', '77412')
            ->call('iniciar')
            ->assertNotified('Comprobante ya existe en el ERP');

        $this->assertNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 0);
    }

    public function test_no_bloquea_si_no_se_puede_consultar_el_erp(): void
    {
        $this->fakeSis();

        $this->stubComprobanteErp(null, new Exception('base de datos caída'));

        $component = $this->iniciarRecepcion();

        $this->assertNotNull($component->get('recepcion'));

        $this->assertDatabaseCount('recepciones', 1);
    }

    public function test_busca_el_proveedor_por_cuit_o_numero_de_cuenta(): void
    {
        $this->fakeSis([
            '*/compras-proveedores/cuentas/*' => Http::response([
                ['ctacod' => '11048', 'ctanom' => 'JUAN MANUEL HIGONET', 'ctanro' => '20217894132'],
                ['ctacod' => '500', 'ctanom' => 'DISTRIBUIDORA DEL SUR', 'ctanro' => '30711122233'],
            ]),
        ]);

        // por CUIT (con y sin guiones)
        Livewire::test(RecepcionMercaderia::class)
            ->set('busquedaProveedor', '20-217894132')
            ->assertSee('JUAN MANUEL HIGONET')
            ->assertDontSee('DISTRIBUIDORA DEL SUR');

        Livewire::test(RecepcionMercaderia::class)
            ->set('busquedaProveedor', '20217894132')
            ->assertSee('JUAN MANUEL HIGONET')
            ->assertDontSee('DISTRIBUIDORA DEL SUR');

        // por numero de cuenta
        Livewire::test(RecepcionMercaderia::class)
            ->set('busquedaProveedor', '11048')
            ->assertSee('JUAN MANUEL HIGONET')
            ->assertDontSee('DISTRIBUIDORA DEL SUR');

        // sin coincidencias
        Livewire::test(RecepcionMercaderia::class)
            ->set('busquedaProveedor', 'ZZZZ')
            ->assertSee('Sin coincidencias');
    }

    public function test_mantiene_visible_el_proveedor_elegido_al_filtrar(): void
    {
        $this->fakeSis([
            '*/compras-proveedores/cuentas/*' => Http::response([
                ['ctacod' => '11048', 'ctanom' => 'JUAN MANUEL HIGONET', 'ctanro' => '20217894132'],
                ['ctacod' => '500', 'ctanom' => 'DISTRIBUIDORA DEL SUR', 'ctanro' => '30711122233'],
            ]),
        ]);

        Livewire::test(RecepcionMercaderia::class)
            ->set('proveedor', '11048')
            ->set('busquedaProveedor', 'ZZZZ')
            ->assertSee('JUAN MANUEL HIGONET')
            ->assertSee('Sin coincidencias');
    }

    public function test_las_opciones_de_proveedor_muestran_cuenta_y_cuit(): void
    {
        $this->fakeSis([
            '*/compras-proveedores/cuentas/*' => Http::response([
                ['ctacod' => '11048', 'ctanom' => 'JUAN MANUEL HIGONET', 'ctanro' => '20217894132'],
            ]),
        ]);

        Livewire::test(RecepcionMercaderia::class)
            ->assertSee('cuenta 11048')
            ->assertSee('CUIT 20217894132');
    }

    public function test_puede_ocultar_y_desplegar_la_cabecera(): void
    {
        $this->fakeSis();

        // Al iniciar la cabecera queda plegada (queda el campo de carga a la vista).
        $component = $this->iniciarRecepcion()
            ->assertSee('Cabecera oculta')
            ->assertDontSee('Actualizar cabecera');

        // Se despliega para volver a editar los datos de la factura.
        $component->call('alternarCabecera')
            ->assertSee('Actualizar cabecera')
            ->assertDontSee('Cabecera oculta');

        // Y se vuelve a plegar.
        $component->call('alternarCabecera')
            ->assertSee('Cabecera oculta')
            ->assertDontSee('Actualizar cabecera');
    }

    public function test_al_iniciar_la_cabecera_queda_oculta_con_el_resumen(): void
    {
        $this->fakeSis();

        $this->iniciarRecepcion()
            ->assertSee('Cabecera oculta')
            ->assertSee('Depósito Central')
            ->assertSee('Proveedor Test')
            ->assertDontSee('Actualizar cabecera');
    }

    public function test_muestra_en_pantalla_si_falta_la_configuracion_de_la_api(): void
    {
        config(['sis.token' => null]);

        Livewire::test(RecepcionMercaderia::class)
            ->assertSee('SIS_API_TOKEN')
            ->assertSee('config:clear');
    }
}
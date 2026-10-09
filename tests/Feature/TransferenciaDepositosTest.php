<?php

namespace Tests\Feature;

use App\Filament\Pages\TransferenciaDepositos;
use App\Models\Transferencia;
use App\Models\TransferenciaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TransferenciaDepositosTest extends TestCase
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
    }

    private function fakeSis(array $adicional = []): void
    {
        Http::fake(array_merge([
            '*/stock/depositos/*' => Http::response([
                ['depcod' => 'D1', 'depnom' => 'Depósito Central', 'succod' => 1],
                ['depcod' => 'D2', 'depnom' => 'Depósito Sucursal', 'succod' => 2],
            ]),
            '*/stock/movimientos/numeracion/*' => Http::response(['manual' => true]),
        ], $adicional, [
            '*' => Http::response([]),
        ]));
    }

    private function iniciarTransferencia()
    {
        return Livewire::test(TransferenciaDepositos::class)
            ->set('depositoOrigen', 'D1')
            ->set('depositoDestino', 'D2')
            ->set('comprobante', '99')
            ->call('iniciar');
    }

    public function test_muestra_la_pagina_con_los_depositos(): void
    {
        $this->fakeSis();

        Livewire::test(TransferenciaDepositos::class)
            ->assertSee('Cabecera de la transferencia')
            ->assertSee('Depósito Central')
            ->assertSee('Depósito Sucursal');
    }

    public function test_inicia_la_transferencia_y_guarda_un_borrador(): void
    {
        $this->fakeSis();

        $component = $this->iniciarTransferencia();

        $this->assertNotNull($component->get('transferencia'));

        $this->assertDatabaseHas('transferencias', [
            'deposito_origen_cod' => 'D1',
            'deposito_destino_cod' => 'D2',
            'comprobante' => '99',
            'estado' => 'borrador',
            'user_id' => 'test-user',
        ]);
    }

    public function test_no_inicia_si_el_deposito_destino_es_igual_al_origen(): void
    {
        $this->fakeSis();

        $component = Livewire::test(TransferenciaDepositos::class)
            ->set('depositoOrigen', 'D1')
            ->set('depositoDestino', 'D1')
            ->call('iniciar');

        $this->assertNull($component->get('transferencia'));

        $this->assertDatabaseCount('transferencias', 0);
    }

    public function test_el_comprobante_es_opcional_con_numeracion_automatica(): void
    {
        $this->fakeSis([
            '*/stock/movimientos/numeracion/*' => Http::response(['manual' => false]),
        ]);

        $component = Livewire::test(TransferenciaDepositos::class)
            ->set('depositoOrigen', 'D1')
            ->set('depositoDestino', 'D2')
            ->call('iniciar');

        $this->assertNotNull($component->get('transferencia'));

        $this->assertDatabaseHas('transferencias', ['comprobante' => '']);
    }

    public function test_cada_captura_del_mismo_articulo_es_un_renglon(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                [
                    'articulo' => ['codigo' => 'A1', 'descripcion' => 'Artículo Test'],
                    'stock' => 10,
                ],
            ]),
        ]);

        $component = $this->iniciarTransferencia()
            ->set('codigo', '7790000000001')
            ->set('cantidad', 6)
            ->call('agregar')
            ->set('codigo', '7790000000001')
            ->set('cantidad', 2)
            ->call('agregar');

        $this->assertDatabaseCount('transferencia_detalles', 2);

        $detalles = TransferenciaDetalle::orderBy('id')->get();

        $this->assertSame('A1', $detalles[0]->artcod);
        $this->assertSame('Artículo Test', $detalles[0]->artdes);
        $this->assertEquals(6, $detalles[0]->cantidad);

        $this->assertSame('A1', $detalles[1]->artcod);
        $this->assertEquals(2, $detalles[1]->cantidad);

        // Quitar el primer renglón deja sólo el segundo.
        $component->call('eliminar', $detalles[0]->id);

        $this->assertDatabaseCount('transferencia_detalles', 1);
        $this->assertEquals(2, TransferenciaDetalle::first()->cantidad);
    }

    public function test_no_agrega_si_no_hay_stock_en_el_deposito_origen(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([]),
        ]);

        $this->iniciarTransferencia()
            ->set('codigo', 'NO-EXISTE')
            ->set('cantidad', 1)
            ->call('agregar');

        $this->assertDatabaseCount('transferencia_detalles', 0);
    }

    public function test_al_encontrar_varios_articulos_muestra_la_lista_para_elegir(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                ['articulo' => ['codigo' => 'A1', 'descripcion' => 'Cepillo Dental Uno'], 'stock' => 5],
                ['articulo' => ['codigo' => 'A2', 'descripcion' => 'Cepillo Dental Dos'], 'stock' => 8],
            ]),
        ]);

        $component = $this->iniciarTransferencia()
            ->set('codigo', 'CEPILLO')
            ->call('agregar');

        $this->assertDatabaseCount('transferencia_detalles', 0);

        $this->assertCount(2, $component->get('resultados'));

        $component->assertSee('Cepillo Dental Uno')
            ->assertSee('Cepillo Dental Dos');
    }

    public function test_al_elegir_un_articulo_de_la_lista_lo_agrega(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                ['articulo' => ['codigo' => 'A1', 'descripcion' => 'Cepillo Dental Uno'], 'stock' => 5],
                ['articulo' => ['codigo' => 'A2', 'descripcion' => 'Cepillo Dental Dos'], 'stock' => 8],
            ]),
        ]);

        $component = $this->iniciarTransferencia()
            ->set('codigo', 'CEPILLO')
            ->call('agregar')
            ->call('seleccionar', 'A2');

        $this->assertDatabaseCount('transferencia_detalles', 1);

        $detalle = TransferenciaDetalle::first();

        $this->assertSame('A2', $detalle->artcod);
        $this->assertSame('Cepillo Dental Dos', $detalle->artdes);

        $this->assertCount(0, $component->get('resultados'));
        $this->assertSame('', $component->get('codigo'));
    }

    public function test_finaliza_y_envia_la_transferencia_al_erp(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                [
                    'articulo' => ['codigo' => 'A1', 'descripcion' => 'Artículo Test'],
                    'stock' => 10,
                ],
            ]),
            '*/stock/movimientos/*' => Http::response([
                'message' => 'Movimiento de stock creado',
                'aditional-data' => ['cbtnro' => '555'],
            ]),
        ]);

        $this->iniciarTransferencia()
            ->set('codigo', 'A1')
            ->set('cantidad', 7)
            ->call('agregar')
            ->call('finalizar');

        $transferencia = Transferencia::first();

        $this->assertSame('finalizada', $transferencia->estado);
        $this->assertSame('Movimiento de stock creado', $transferencia->mensaje);
        $this->assertSame('555', $transferencia->cbtnro);
        $this->assertCount(1, $transferencia->detalles);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/stock/movimientos/')
                && $request['tmscod'] == 66
                && $request['depcod'] === 'D1'
                && $request['depdstcod'] === 'D2'
                && $request['cbtnro'] === '99'
                && $request['articulos'][0]['artcod'] === 'A1'
                && $request['articulos'][0]['stkcan'] == 7;
        });
    }

    public function test_finaliza_enviando_un_renglon_por_cada_captura(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                [
                    'articulo' => ['codigo' => 'A1', 'descripcion' => 'Artículo Test'],
                    'stock' => 10,
                ],
            ]),
            '*/stock/movimientos/*' => Http::response([
                'message' => 'Movimiento de stock creado',
                'aditional-data' => ['cbtnro' => '556'],
            ]),
        ]);

        $this->iniciarTransferencia()
            ->set('codigo', 'A1')
            ->set('cantidad', 4)
            ->call('agregar')
            ->set('codigo', 'A1')
            ->set('cantidad', 5)
            ->call('agregar')
            ->call('finalizar');

        $this->assertDatabaseCount('transferencias', 1);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/stock/movimientos/')
                && count($request['articulos']) === 2
                && $request['articulos'][0]['artcod'] === 'A1'
                && $request['articulos'][0]['stkcan'] == 4
                && $request['articulos'][1]['artcod'] === 'A1'
                && $request['articulos'][1]['stkcan'] == 5;
        });
    }

    public function test_marca_error_si_el_erp_rechaza_la_transferencia(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                [
                    'articulo' => ['codigo' => 'A1', 'descripcion' => 'Artículo Test'],
                    'stock' => 10,
                ],
            ]),
            '*/stock/movimientos/*' => Http::response(['message' => 'No se pudo crear el movimiento'], 400),
        ]);

        $this->iniciarTransferencia()
            ->set('codigo', 'A1')
            ->set('cantidad', 1)
            ->call('agregar')
            ->call('finalizar');

        $transferencia = Transferencia::first();

        $this->assertSame('error', $transferencia->estado);
        $this->assertSame('No se pudo crear el movimiento', $transferencia->mensaje);
    }

    public function test_cancelar_borra_el_borrador(): void
    {
        $this->fakeSis([
            '*/consulta-stock/*' => Http::response([
                [
                    'articulo' => ['codigo' => 'A1', 'descripcion' => 'Artículo Test'],
                    'stock' => 10,
                ],
            ]),
        ]);

        $this->iniciarTransferencia()
            ->set('codigo', 'A1')
            ->set('cantidad', 1)
            ->call('agregar')
            ->call('cancelar');

        $this->assertDatabaseCount('transferencias', 0);
        $this->assertDatabaseCount('transferencia_detalles', 0);
    }

    public function test_puede_ocultar_y_desplegar_la_cabecera(): void
    {
        $this->fakeSis();

        // Al iniciar la cabecera queda plegada (queda el campo de carga a la vista).
        $component = $this->iniciarTransferencia()
            ->assertSee('Cabecera oculta')
            ->assertDontSee('Actualizar cabecera');

        // Se despliega para volver a editar los datos de la transferencia.
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

        $this->iniciarTransferencia()
            ->assertSee('Cabecera oculta')
            ->assertSee('Depósito Central')
            ->assertSee('Depósito Sucursal')
            ->assertDontSee('Actualizar cabecera');
    }

    public function test_muestra_en_pantalla_si_falta_la_configuracion_de_la_api(): void
    {
        config(['sis.token' => null]);

        Livewire::test(TransferenciaDepositos::class)
            ->assertSee('SIS_API_TOKEN')
            ->assertSee('config:clear');
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\ConsultaStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConsultaStockTest extends TestCase
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
        Http::fake(array_merge($adicional, [
            '*' => Http::response([]),
        ]));
    }

    private function fila(string $artcod, string $artdes, string $depcod, string $depnom, float $stock): array
    {
        return [
            'articulo' => ['codigo' => $artcod, 'descripcion' => $artdes],
            'deposito' => ['codigo' => $depcod, 'nombre' => $depnom],
            'stock' => $stock,
        ];
    }

    public function test_muestra_la_pagina_con_el_campo_de_codigo(): void
    {
        $this->fakeSis();

        Livewire::test(ConsultaStock::class)
            ->assertSee('Consulta de stock')
            ->assertSee('Escanee el código de barras');
    }

    public function test_la_pagina_esta_en_el_menu_y_se_puede_abrir(): void
    {
        $this->fakeSis();

        $response = $this->get('/admin/consulta-stock');

        $response->assertOk();
        $response->assertSee('Consulta de Stock');
    }

    public function test_muestra_el_codigo_la_descripcion_y_el_stock_por_deposito(): void
    {
        $this->fakeSis([
            '*/stock/consulta-stock/*' => Http::response([
                $this->fila('A1', 'Artículo Test', 'D1', 'Depósito Central', 3),
                $this->fila('A1', 'Artículo Test', 'D1', 'Depósito Central', 7),
                $this->fila('A1', 'Artículo Test', 'D2', 'Depósito Sucursal', 2),
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', '7790000000001')
            ->call('consultar')
            ->assertSee('A1')
            ->assertSee('Artículo Test')
            ->assertSee('Depósito Central')
            ->assertSee('Depósito Sucursal')
            ->assertSee('LIMPIAR');

        $saldos = collect($component->get('saldos'));

        $this->assertCount(2, $saldos);
        $this->assertEquals(10, $saldos->firstWhere('depcod', 'D1')['stock']);
        $this->assertEquals(2, $saldos->firstWhere('depcod', 'D2')['stock']);
        $this->assertSame('', $component->get('codigo'));
    }

    public function test_ordena_los_saldos_por_numero_de_deposito(): void
    {
        // El ERP devuelve los depósitos en cualquier orden: la app los lista
        // por número de depósito (1000, 3000, 4000, 5000, 6000, 8000, 9000).
        $this->fakeSis([
            '*/stock/consulta-stock/*' => Http::response([
                $this->fila('A1', 'Artículo Test', '9000', 'CAB', 1),
                $this->fila('A1', 'Artículo Test', '4000', 'Depósito Jacinto Arauz', 1),
                $this->fila('A1', 'Artículo Test', '1000', 'Depósito Darregueira', 1),
                $this->fila('A1', 'Artículo Test', '8000', 'Depósito Felipe Sola', 1),
                $this->fila('A1', 'Artículo Test', '3000', 'Depósito Salliquelo', 1),
                $this->fila('A1', 'Artículo Test', '6000', 'Depósito Doblas', 1),
                $this->fila('A1', 'Artículo Test', '5000', 'Depósito Villa Iris', 1),
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', '7790000000001')
            ->call('consultar');

        $orden = collect($component->get('saldos'))->pluck('depcod')->all();

        $this->assertSame(['1000', '3000', '4000', '5000', '6000', '8000', '9000'], $orden);
    }

    public function test_muestra_el_articulo_aunque_no_tenga_stock(): void
    {
        $this->fakeSis([
            '*/stock/consulta-stock/*' => Http::response([]),
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Artículo Test'],
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', '7790000000001')
            ->call('consultar')
            ->assertSee('A1')
            ->assertSee('Artículo Test')
            ->assertSee('Sin stock en ningún depósito')
            ->assertNotified('Sin stock');

        $this->assertCount(0, $component->get('saldos'));
        $this->assertSame('A1', $component->get('articulo')['artcod']);
    }

    public function test_avisa_si_no_encuentra_el_codigo(): void
    {
        $this->fakeSis([
            '*/stock/consulta-stock/*' => Http::response([]),
            '*/articulos/*' => Http::response([]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', 'NO-EXISTE')
            ->call('consultar')
            ->assertNotified('Código no encontrado');

        $this->assertNull($component->get('articulo'));
        $this->assertCount(0, $component->get('saldos'));
    }

    public function test_limpia_la_consulta(): void
    {
        $this->fakeSis([
            '*/stock/consulta-stock/*' => Http::response([
                $this->fila('A1', 'Artículo Test', 'D1', 'Depósito Central', 3),
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', '7790000000001')
            ->call('consultar')
            ->call('limpiar');

        $this->assertNull($component->get('articulo'));
        $this->assertCount(0, $component->get('saldos'));
        $this->assertSame('', $component->get('codigo'));
    }

    public function test_si_el_erp_no_responde_muestra_el_aviso(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', '7790000000001')
            ->call('consultar')
            ->assertNotified('API del ERP');

        $this->assertNull($component->get('articulo'));
        $this->assertCount(0, $component->get('saldos'));
    }

    public function test_busca_por_descripcion_y_muestra_varias_coincidencias(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental'],
                ['artcod' => 'A2', 'artdes' => 'Cepillo de Dientes'],
                ['artcod' => 'A3', 'artdes' => 'Cepillo de Pelo'],
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', 'cepillo')
            ->call('consultar')
            ->assertSee('3 artículos coinciden')
            ->assertSee('Cepillo Dental')
            ->assertSee('Cepillo de Dientes')
            ->assertSee('Cepillo de Pelo');

        $this->assertCount(3, $component->get('resultados'));
        $this->assertNull($component->get('articulo'));
    }

    public function test_al_elegir_un_coincidencia_muestra_su_stock(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental'],
                ['artcod' => 'A2', 'artdes' => 'Cepillo de Dientes'],
            ]),
            '*/stock/consulta-stock/*artcod=A2*' => Http::response([
                $this->fila('A2', 'Cepillo de Dientes', 'D1', 'Depósito Central', 5),
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', 'cepillo')
            ->call('consultar')
            ->assertSee('2 artículos coinciden')
            ->call('seleccionar', 'A2')
            ->assertSee('A2')
            ->assertSee('Cepillo de Dientes')
            ->assertSee('Depósito Central')
            ->assertDontSee('artículos coinciden');

        $this->assertCount(0, $component->get('resultados'));
        $this->assertSame('A2', $component->get('articulo')['artcod']);
        $this->assertCount(1, $component->get('saldos'));
    }

    public function test_puede_descartar_las_coincidencias(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental'],
                ['artcod' => 'A2', 'artdes' => 'Cepillo de Dientes'],
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', 'cepillo')
            ->call('consultar')
            ->assertSee('2 artículos coinciden')
            ->call('descartarResultados')
            ->assertDontSee('artículos coinciden');

        $this->assertCount(0, $component->get('resultados'));
    }

    public function test_si_hay_una_sola_coincidencia_no_muestra_la_lista(): void
    {
        $this->fakeSis([
            '*/articulos/*' => Http::response([
                ['artcod' => 'A1', 'artdes' => 'Cepillo Dental'],
            ]),
            '*/stock/consulta-stock/*' => Http::response([
                $this->fila('A1', 'Cepillo Dental', 'D1', 'Depósito Central', 10),
            ]),
        ]);

        $component = Livewire::test(ConsultaStock::class)
            ->set('codigo', 'cepillo')
            ->call('consultar')
            ->assertDontSee('artículos coinciden')
            ->assertSee('Cepillo Dental');

        $this->assertCount(0, $component->get('resultados'));
        $this->assertSame('A1', $component->get('articulo')['artcod']);
    }
}

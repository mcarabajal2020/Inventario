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
}

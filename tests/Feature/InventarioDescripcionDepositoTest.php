<?php

namespace Tests\Feature;

use App\Filament\Resources\Inventarios\InventarioResource;
use App\Filament\Resources\Inventarios\Pages\CreateInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InventarioDescripcionDepositoTest extends TestCase
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

    private function fakeDepositos(array $adicional = []): void
    {
        Http::fake(array_merge($adicional, [
            '*/stock/depositos/' => Http::response([
                ['depcod' => '1000', 'depnom' => 'Depósito Darregueira'],
                ['depcod' => '3000', 'depnom' => 'Depósito Salliquelo'],
                ['depcod' => '9000', 'depnom' => 'CAB'],
            ]),
            '*' => Http::response([]),
        ]));
    }

    public function test_el_formulario_tiene_descripcion_y_deposito_con_los_depositos_del_erp(): void
    {
        $this->fakeDepositos();

        $response = $this->get('/admin/inventarios/create');

        $response->assertOk();
        $response->assertSee('Descripción');
        $response->assertSee('Depósito');
        $response->assertSee('Depósito Darregueira');
        $response->assertSee('Depósito Salliquelo');
    }

    public function test_deposito_es_obligatorio_al_crear(): void
    {
        $this->fakeDepositos();

        Livewire::test(CreateInventario::class)
            ->fillForm([
                'descripcion' => 'Inventario test',
                'fecha_inicio' => now()->format('Y-m-d H:i:s'),
                'estado' => 'abierto',
            ])
            ->call('create')
            ->assertHasFormErrors(['deposito' => 'required']);

        $this->assertDatabaseCount('inventarios', 0);
    }

    public function test_crea_el_inventario_con_la_descripcion_y_el_codigo_del_deposito(): void
    {
        $this->fakeDepositos();

        Livewire::test(CreateInventario::class)
            ->fillForm([
                'descripcion' => 'Inventario test',
                'deposito' => '3000',
                'fecha_inicio' => now()->format('Y-m-d H:i:s'),
                'estado' => 'abierto',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $inventario = DB::table('inventarios')->first();

        $this->assertSame('Inventario test', $inventario->descripcion);
        // Se guarda el código (3000), no el nombre.
        $this->assertSame('3000', $inventario->deposito);
    }

    public function test_el_listado_muestra_la_descripcion_y_el_nombre_del_deposito(): void
    {
        $this->fakeDepositos();

        $ahora = date('Y-m-d H:i:s');

        DB::table('inventarios')->insert([
            'descripcion' => 'Inventario de prueba',
            'deposito' => '1000',
            'fecha_inicio' => $ahora,
            'estado' => 'abierto',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('Inventario de prueba');
        // La columna depósito resuelve el nombre desde el ERP.
        $response->assertSee('Depósito Darregueira');
    }

    public function test_si_el_erp_no_responde_el_formulario_no_se_rompe(): void
    {
        Http::fake([
            '*/stock/depositos/' => Http::response('sin conexión', 500),
            '*' => Http::response([]),
        ]);

        $response = $this->get('/admin/inventarios/create');

        $response->assertOk();
        $response->assertSee('Descripción');

        $this->assertSame([], InventarioResource::opcionesDepositos());
    }

    public function test_el_listado_no_se_rompe_si_falta_la_configuracion_de_la_api(): void
    {
        config(['sis.token' => null]);

        $ahora = date('Y-m-d H:i:s');

        DB::table('inventarios')->insert([
            'descripcion' => 'Sin configuración',
            'deposito' => '1000',
            'fecha_inicio' => $ahora,
            'estado' => 'abierto',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('Sin configuración');
    }
}

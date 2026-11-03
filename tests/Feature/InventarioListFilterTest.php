<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventarioListFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('inventarios')->insert([
            [
                'descripcion' => 'DESC-ABIERTO-TEST',
                'deposito' => '1000',
                'fecha_inicio' => '2026-10-06 10:00:00',
                'estado' => 'abierto',
                'user_id' => 'test',
                'created_at' => '2026-10-06 10:00:00',
                'updated_at' => '2026-10-06 10:00:00',
            ],
            [
                'descripcion' => 'DESC-CERRADO-TEST',
                'deposito' => '3000',
                'fecha_inicio' => '2026-10-06 11:00:00',
                'estado' => 'cerrado',
                'user_id' => 'test',
                'created_at' => '2026-10-06 11:00:00',
                'updated_at' => '2026-10-06 11:00:00',
            ],
        ]);

        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        $this->actingAs($user);

        // El listado resuelve el nombre del depósito contra el ERP.
        Http::fake([
            '*/stock/depositos/' => Http::response([
                ['depcod' => '1000', 'depnom' => 'Depósito Darregueira'],
                ['depcod' => '3000', 'depnom' => 'Depósito Salliquelo'],
            ]),
            '*' => Http::response([]),
        ]);
    }

    public function test_por_defecto_solo_muestra_inventarios_abiertos(): void
    {
        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('DESC-ABIERTO-TEST');
        $response->assertDontSee('DESC-CERRADO-TEST');
    }

    public function test_el_filtro_de_estado_muestra_los_cerrados(): void
    {
        $response = $this->get('/admin/inventarios?filters%5Bestado%5D%5Bvalue%5D=cerrado');

        $response->assertOk();
        $response->assertSee('DESC-CERRADO-TEST');
        $response->assertDontSee('DESC-ABIERTO-TEST');
    }
}

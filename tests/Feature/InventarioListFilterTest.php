<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioListFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('inventarios')->insert([
            [
                'sucursal' => 'SUC-ABIERTO-TEST',
                'fecha_inicio' => '2026-10-06 10:00:00',
                'estado' => 'abierto',
                'user_id' => 'test',
                'created_at' => '2026-10-06 10:00:00',
                'updated_at' => '2026-10-06 10:00:00',
            ],
            [
                'sucursal' => 'SUC-CERRADO-TEST',
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
    }

    public function test_por_defecto_solo_muestra_inventarios_abiertos(): void
    {
        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('SUC-ABIERTO-TEST');
        $response->assertDontSee('SUC-CERRADO-TEST');
    }

    public function test_el_filtro_de_estado_muestra_los_cerrados(): void
    {
        $response = $this->get('/admin/inventarios?filters%5Bestado%5D%5Bvalue%5D=cerrado');

        $response->assertOk();
        $response->assertSee('SUC-CERRADO-TEST');
        $response->assertDontSee('SUC-ABIERTO-TEST');
    }
}

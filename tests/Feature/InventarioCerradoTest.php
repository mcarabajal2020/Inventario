<?php

namespace Tests\Feature;

use App\Filament\Pages\TomarInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InventarioCerradoTest extends TestCase
{
    use RefreshDatabase;

    private int $abiertoId;

    private int $cerradoId;

    protected function setUp(): void
    {
        parent::setUp();

        $ahora = date('Y-m-d H:i:s');

        $this->abiertoId = DB::table('inventarios')->insertGetId([
            'sucursal' => 'SUC-ABIERTO-TEST',
            'fecha_inicio' => $ahora,
            'estado' => 'abierto',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $this->cerradoId = DB::table('inventarios')->insertGetId([
            'sucursal' => 'SUC-CERRADO-TEST',
            'fecha_inicio' => $ahora,
            'estado' => 'cerrado',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        $this->actingAs($user);
    }

    public function test_el_boton_tomar_desaparece_cuando_el_inventario_esta_cerrado(): void
    {
        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('/admin/tomar-inventario/' . $this->abiertoId);
        $response->assertDontSee('/admin/tomar-inventario/' . $this->cerradoId);
    }

    public function test_no_se_pueden_cargar_movimientos_en_un_inventario_cerrado(): void
    {
        Livewire::test(TomarInventario::class, ['inventario' => $this->cerradoId])
            ->set('codigo_barra', '00000')
            ->set('cantidad', 1)
            ->call('agregar')
            ->assertNotified();

        $this->assertSame(0, DB::table('inventario_movimientos')
            ->where('inventario_id', $this->cerradoId)
            ->count());
    }

    public function test_la_colectora_muestra_un_aviso_si_el_inventario_esta_cerrado(): void
    {
        Livewire::test(TomarInventario::class, ['inventario' => $this->cerradoId])
            ->assertSee('Inventario cerrado');
    }

    public function test_la_colectora_permite_cargar_si_el_inventario_esta_abierto(): void
    {
        Livewire::test(TomarInventario::class, ['inventario' => $this->abiertoId])
            ->assertDontSee('Inventario cerrado');
    }
}

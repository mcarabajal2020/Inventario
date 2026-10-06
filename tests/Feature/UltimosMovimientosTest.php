<?php

namespace Tests\Feature;

use App\Filament\Pages\TomarInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class UltimosMovimientosTest extends TestCase
{
    use RefreshDatabase;

    private int $inventarioId;

    protected function setUp(): void
    {
        parent::setUp();

        $ahora = date('Y-m-d H:i:s');

        $this->inventarioId = DB::table('inventarios')->insertGetId([
            'sucursal' => 'SUC-TEST',
            'fecha_inicio' => $ahora,
            'estado' => 'abierto',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        $this->actingAs($user);
    }

    private function articuloConDescripcion(): object
    {
        $articulo = DB::connection('mutualnew')
            ->table('stkartic0')
            ->whereNotNull('artdes')
            ->where('artdes', '!=', '')
            ->first();

        if (! $articulo) {
            $this->markTestSkipped('No hay artículos con descripción en mutualnew');
        }

        return $articulo;
    }

    public function test_los_ultimos_movimientos_muestran_la_descripcion(): void
    {
        $articulo = $this->articuloConDescripcion();

        DB::table('inventario_movimientos')->insert([
            'inventario_id' => $this->inventarioId,
            'artcod' => $articulo->artcod,
            'codigo_barra' => $articulo->artcod,
            'cantidad' => 5,
            'ubicacion' => 'DEPOSITO',
            'usuario' => 'test-user',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Livewire::test(TomarInventario::class, ['inventario' => $this->inventarioId])
            ->assertSee($articulo->artdes);
    }

    public function test_los_ultimos_movimientos_no_muestran_descripcion_si_el_articulo_no_existe(): void
    {
        DB::table('inventario_movimientos')->insert([
            'inventario_id' => $this->inventarioId,
            'artcod' => 'NO-EXISTE-999',
            'codigo_barra' => 'NO-EXISTE-999',
            'cantidad' => 1,
            'ubicacion' => 'DEPOSITO',
            'usuario' => 'test-user',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $component = Livewire::test(TomarInventario::class, ['inventario' => $this->inventarioId]);

        $this->assertCount(1, $component->get('movimientos'));
        $this->assertSame('', $component->get('movimientos')[0]->artdes);
    }
}

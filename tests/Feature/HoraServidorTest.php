<?php

namespace Tests\Feature;

use App\Models\InventarioMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HoraServidorTest extends TestCase
{
    use RefreshDatabase;

    private int $inventarioId;

    protected function setUp(): void
    {
        parent::setUp();

        $ahora = date('Y-m-d H:i:s');

        $this->inventarioId = DB::table('inventarios')->insertGetId([
            'sucursal' => 'SUC-HORA-TEST',
            'fecha_inicio' => $ahora,
            'estado' => 'abierto',
            'user_id' => 'test',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function test_la_aplicacion_usa_la_zona_horaria_del_servidor(): void
    {
        $this->assertSame('America/Argentina/Buenos_Aires', config('app.timezone'));

        $this->assertSame(config('app.timezone'), date_default_timezone_get());

        $this->assertNotSame('UTC', config('app.timezone'));
    }

    public function test_el_movimiento_se_registra_con_la_fecha_y_hora_local_del_servidor(): void
    {
        // Mismo alta que hace TomarInventario::agregar().
        InventarioMovimiento::create([
            'inventario_id' => $this->inventarioId,
            'artcod' => '000001',
            'artdes' => 'Artículo de prueba',
            'codigo_barra' => '000001',
            'cantidad' => 1,
            'ubicacion' => 'DEPOSITO',
            'usuario' => 'test-user',
            'created_at' => now(),
        ]);

        $guardado = DB::table('inventario_movimientos')
            ->where('inventario_id', $this->inventarioId)
            ->value('created_at');

        $utc = now()->setTimezone('UTC')->format('Y-m-d H:i:s');

        $this->assertSame(now()->format('Y-m-d H:i:s'), $guardado);

        $this->assertNotSame($utc, $guardado);
    }
}

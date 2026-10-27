<?php

namespace Tests\Unit;

use App\Services\ComprobanteErp;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La validación de duplicados es por **proveedor + punto de venta +
 * comprobante**: se prueban las dos tablas que consulta el ERP
 * (`mutualnew.comcbt` y `mutualnew.stkmov`) contra una base en memoria.
 */
class ComprobanteErpTest extends TestCase
{
    private ComprobanteErp $erp;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mutualnew' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mutualnew');

        $schema = DB::connection('mutualnew')->getSchemaBuilder();

        $schema->create('comcbt', function ($table) {
            $table->increments('id');
            $table->integer('cemcod');
            $table->integer('cbtnro');
            $table->integer('cbtcod');
            $table->string('cbtfec')->nullable();
            $table->string('ctanom')->nullable();
            $table->string('ctacod');
            $table->string('usrregis')->nullable();
        });

        $schema->create('stkmov', function ($table) {
            $table->increments('id');
            $table->integer('cemcod');
            $table->integer('cbtnro');
            $table->integer('cbtcod');
            $table->integer('tmscod');
            $table->integer('inactivo');
            $table->string('cbtfec')->nullable();
            $table->string('cmpctacod');
            $table->string('usrregis')->nullable();
        });

        $schema->create('stkmovart', function ($table) {
            $table->integer('id');
        });

        $this->erp = new ComprobanteErp();
    }

    protected function tearDown(): void
    {
        DB::purge('mutualnew');

        parent::tearDown();
    }

    private function comcbt(int $puntoDeVenta, int $comprobante, string $proveedorCod): void
    {
        DB::connection('mutualnew')->table('comcbt')->insert([
            'cemcod' => $puntoDeVenta,
            'cbtnro' => $comprobante,
            'cbtcod' => 5,
            'cbtfec' => '2026-10-07',
            'ctanom' => 'PROVEEDOR ' . $proveedorCod,
            'ctacod' => $proveedorCod,
            'usrregis' => 'Carlos',
        ]);
    }

    private function stkmov(int $puntoDeVenta, int $comprobante, string $proveedorCod, int $detalles = 1): void
    {
        $id = DB::connection('mutualnew')->table('stkmov')->insertGetId([
            'cemcod' => $puntoDeVenta,
            'cbtnro' => $comprobante,
            'cbtcod' => 80,
            'tmscod' => 6,
            'inactivo' => 0,
            'cbtfec' => '2026-10-07',
            'cmpctacod' => $proveedorCod,
            'usrregis' => 'Carlos',
        ]);

        for ($i = 0; $i < $detalles; $i++) {
            DB::connection('mutualnew')->table('stkmovart')->insert(['id' => $id]);
        }
    }

    public function test_encuentra_el_comprobante_del_mismo_proveedor_en_comcbt(): void
    {
        $this->comcbt(1, 2, '14487');

        $fila = $this->erp->buscar(1, 2, 14487);

        $this->assertNotNull($fila);
        $this->assertSame('comcbt', $fila['origen']);
        $this->assertSame('14487', $fila['proveedor_cod']);
    }

    public function test_no_bloquea_en_comcbt_si_el_comprobante_es_de_otro_proveedor(): void
    {
        $this->comcbt(1, 2, '12023');

        $this->assertNull($this->erp->buscar(1, 2, 14487));
    }

    public function test_encuentra_el_movimiento_del_mismo_proveedor_en_stkmov(): void
    {
        $this->stkmov(1, 2, '14487');

        $fila = $this->erp->buscar(1, 2, 14487);

        $this->assertNotNull($fila);
        $this->assertSame('stkmov', $fila['origen']);
        $this->assertSame('14487', $fila['proveedor_cod']);
        $this->assertFalse($fila['parcial']);
    }

    public function test_no_bloquea_en_stkmov_si_el_movimiento_es_de_otro_proveedor(): void
    {
        // El caso real: comprobante 1-2 cargado a nombre del proveedor
        // 12023 y ahora se quiere cargar con el 14487.
        $this->stkmov(1, 2, '12023');

        $this->assertNull($this->erp->buscar(1, 2, 14487));
    }

    public function test_sigue_bloqueando_la_carga_interrumpida_del_mismo_proveedor(): void
    {
        $this->stkmov(1, 2, '14487', detalles: 0);

        $fila = $this->erp->buscar(1, 2, 14487);

        $this->assertNotNull($fila);
        $this->assertTrue($fila['parcial']);
    }

    public function test_sin_proveedor_compara_solo_por_punto_de_venta_y_comprobante(): void
    {
        $this->stkmov(1, 2, '12023');

        $this->assertNotNull($this->erp->buscar(1, 2));
    }

    public function test_no_encuentra_el_comprobante_de_otro_punto_de_venta(): void
    {
        $this->stkmov(1, 2, '14487');

        $this->assertNull($this->erp->buscar(2, 2, 14487));
        $this->assertNull($this->erp->buscar(1, 3, 14487));
    }
}

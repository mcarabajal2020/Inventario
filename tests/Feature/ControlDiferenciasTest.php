<?php

namespace Tests\Feature;

use App\Filament\Pages\ControlDiferencias;
use App\Models\Inventario;
use App\Models\User;
use App\Services\ComparadorInventario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * El control compara lo contado en un inventario con el stock del depósito
 * en el ERP (`stksaldos`), usando una base `mutualnew` en memoria.
 */
class ControlDiferenciasTest extends TestCase
{
    use RefreshDatabase;

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

        $schema->create('stksaldos', function (Blueprint $table) {
            $table->string('artcod');
            $table->string('depcod');
            $table->decimal('stkcan', 18, 6)->nullable();
        });

        $schema->create('stkartic0', function (Blueprint $table) {
            $table->string('artcod');
            $table->string('artdes')->nullable();
        });

        $schema->create('stkdepos', function (Blueprint $table) {
            $table->string('depcod');
            $table->string('depnom');
        });

        $schema->create('stkmov', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('cbtfec');
            $table->integer('tmscod')->default(0);
            $table->string('inactivo')->default('0');
        });

        $schema->create('stkmovart', function (Blueprint $table) {
            $table->string('id');
            $table->integer('sec')->default(0);
            $table->string('artcod');
            $table->string('depcod');
            $table->decimal('stkcan', 18, 6)->default(0);
            $table->primary(['id', 'sec']);
        });

        $schema->create('stktmstk', function (Blueprint $table) {
            $table->integer('tmscod')->primary();
            $table->integer('afestk')->default(1);
        });

        // Tipos usados por los tests: uno que suma y uno que resta.
        DB::connection('mutualnew')->table('stktmstk')->insert([
            ['tmscod' => 2, 'afestk' => 1],
            ['tmscod' => 51, 'afestk' => -1],
        ]);

        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        DB::purge('mutualnew');

        parent::tearDown();
    }

    /** Inventario de prueba con depósito 1000 (o el indicado). */
    private function inventario(?string $depcod = '1000'): int
    {
        return DB::table('inventarios')->insertGetId([
            'descripcion' => 'Inventario test',
            'deposito' => $depcod,
            'fecha_inicio' => date('Y-m-d H:i:s'),
            'estado' => 'abierto',
            'user_id' => 'test-user',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Captura de un artículo en el inventario (un renglón por captura). */
    private function contar(int $inventarioId, string $artcod, float $cantidad): void
    {
        DB::table('inventario_movimientos')->insert([
            'inventario_id' => $inventarioId,
            'artcod' => $artcod,
            'cantidad' => $cantidad,
            'usuario' => 'test-user',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Existencia de un artículo en el depósito, según el ERP (reemplaza). */
    private function stock(string $artcod, string $depcod, float $stkcan): void
    {
        $actualizados = DB::connection('mutualnew')->table('stksaldos')
            ->where('artcod', $artcod)
            ->where('depcod', $depcod)
            ->update(['stkcan' => $stkcan]);

        if ($actualizados === 0) {
            DB::connection('mutualnew')->table('stksaldos')->insert([
                'artcod' => $artcod,
                'depcod' => $depcod,
                'stkcan' => $stkcan,
            ]);
        }
    }

    private function articulo(string $artcod, string $artdes): void
    {
        DB::connection('mutualnew')->table('stkartic0')->insert([
            'artcod' => $artcod,
            'artdes' => $artdes,
        ]);
    }

    /** Cabecera de un movimiento en el ERP (fecha = cbtfec). */
    private function movimiento(string $id, string $cbtfec, int $tmscod, string $inactivo = '0'): void
    {
        DB::connection('mutualnew')->table('stkmov')->insert([
            'id' => $id,
            'cbtfec' => $cbtfec,
            'tmscod' => $tmscod,
            'inactivo' => $inactivo,
        ]);
    }

    /** Renglón de un movimiento: el efecto es stkcan × afestk del tipo. */
    private function renglon(string $id, string $artcod, string $depcod, float $stkcan, int $sec = 1): void
    {
        DB::connection('mutualnew')->table('stkmovart')->insert([
            'id' => $id,
            'sec' => $sec,
            'artcod' => $artcod,
            'depcod' => $depcod,
            'stkcan' => $stkcan,
        ]);
    }

    public function test_la_pagina_esta_en_el_menu_y_se_puede_abrir(): void
    {
        $response = $this->get('/admin/control-diferencias');

        $response->assertOk();
        $response->assertSee('Control de Diferencias');
        $response->assertSee('Seleccione un inventario para comparar');
    }

    public function test_compara_lo_contado_con_el_stock_del_deposito(): void
    {
        $this->articulo('001', 'ARTICULO UNO');
        $this->articulo('002', 'ARTICULO DOS');
        $this->articulo('003', 'ARTICULO TRES');
        $this->articulo('004', 'ARTICULO CUATRO');

        // Contado 5 contra stock 3: diferencia de +2.
        $this->contar($inventario = $this->inventario(), '001', 5);
        $this->stock('001', '1000', 3);

        // Contado 4 contra stock 4: sin diferencia.
        $this->contar($inventario, '002', 4);
        $this->stock('002', '1000', 4);

        // Con stock 10 en el depósito y sin captura: no contado.
        $this->stock('003', '1000', 10);

        // Contado 2 pero sin fila de stock en el depósito: diferencia contra 0.
        $this->contar($inventario, '004', 2);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('filtro', 'todos')
            ->assertSee('001')
            ->assertSee('ARTICULO UNO')
            ->assertSee('003')
            ->assertSee('ARTICULO TRES')
            ->assertSee('No contado')
            ->assertSee('Diferencia');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertSame('diferencia', $filas->firstWhere('artcod', '001')['estado']);
        $this->assertSame(2.0, $filas->firstWhere('artcod', '001')['diferencia']);
        $this->assertSame('sin_diferencia', $filas->firstWhere('artcod', '002')['estado']);
        $this->assertSame('no_contado', $filas->firstWhere('artcod', '003')['estado']);
        $this->assertNull($filas->firstWhere('artcod', '003')['contado']);
        $this->assertSame('diferencia', $filas->firstWhere('artcod', '004')['estado']);
        $this->assertSame(0.0, $filas->firstWhere('artcod', '004')['stock']);
    }

    public function test_el_filtro_por_defecto_es_pendientes(): void
    {
        $this->articulo('001', 'ARTICULO UNO');
        $this->articulo('002', 'ARTICULO DOS');

        $inventario = $this->inventario();

        $this->contar($inventario, '001', 5);
        $this->stock('001', '1000', 3);

        $this->contar($inventario, '002', 4);
        $this->stock('002', '1000', 4);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario);

        // Por defecto sólo lo que requiere acción: la diferencia sí, la que
        // coincide no.
        $this->assertSame('pendientes', $component->get('filtro'));
        $this->assertSame(['001'], collect($component->instance()->filasPaginadas())->pluck('artcod')->all());

        $component->call('setFiltro', 'todos');

        $this->assertCount(2, $component->instance()->filasPaginadas());

        $component->call('setFiltro', 'sin_diferencia');

        $this->assertSame(['002'], collect($component->instance()->filasPaginadas())->pluck('artcod')->all());
    }

    public function test_busca_por_codigo_o_descripcion(): void
    {
        $this->articulo('001', 'CEPILLO DENTAL');
        $this->articulo('002', 'HAMBURGUESA');

        $inventario = $this->inventario();

        $this->stock('001', '1000', 5);
        $this->stock('002', '1000', 5);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('filtro', 'todos')
            ->set('busqueda', 'cepillo');

        $this->assertSame(['001'], collect($component->instance()->filasPaginadas())->pluck('artcod')->all());

        $component->set('busqueda', '002');

        $this->assertSame(['002'], collect($component->instance()->filasPaginadas())->pluck('artcod')->all());
    }

    public function test_resumen_cuenta_los_estados(): void
    {
        $this->articulo('001', 'ARTICULO UNO');
        $this->articulo('002', 'ARTICULO DOS');
        $this->articulo('003', 'ARTICULO TRES');

        $inventario = $this->inventario();

        $this->contar($inventario, '001', 4);
        $this->stock('001', '1000', 4);

        $this->contar($inventario, '002', 6);
        $this->stock('002', '1000', 4);

        $this->stock('003', '1000', 8);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario);

        $resumen = $component->instance()->resumen();

        $this->assertSame(2, $resumen['contados']);
        $this->assertSame(1, $resumen['sin_diferencia']);
        $this->assertSame(1, $resumen['diferencia']);
        $this->assertSame(1, $resumen['no_contado']);
        $this->assertSame(3, $resumen['total']);
    }

    public function test_los_codigos_con_espacios_del_erp_se_comparan_bien(): void
    {
        $this->articulo('001', 'ARTICULO UNO');

        $inventario = $this->inventario();

        $this->contar($inventario, '001', 4);

        // El ERP guarda artcod con espacios iniciales (char(20)).
        $this->stock('   001', '1000', 4);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertCount(1, $filas);
        $this->assertSame('001', $filas->first()['artcod']);
        $this->assertSame('sin_diferencia', $filas->first()['estado']);
    }

    public function test_no_muestra_los_articles_sin_existencia_y_sin_contar(): void
    {
        $this->articulo('001', 'ARTICULO UNO');

        $inventario = $this->inventario();

        // Sin stock en el depósito y sin captura: no aporta nada.
        $this->stock('001', '1000', 0);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('filtro', 'todos');

        $this->assertCount(0, $component->instance()->filasPaginadas());
        $this->assertSame(0, $component->instance()->resumen()['total']);
    }

    public function test_inventario_sin_deposito_muestra_aviso(): void
    {
        $inventario = $this->inventario(depcod: null);

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->assertSee('no tiene depósito asignado');
    }

    public function test_inventario_inexistente_muestra_aviso(): void
    {
        Livewire::test(ControlDiferencias::class)
            ->set('inventario', 999999)
            ->assertSee('no existe');
    }

    public function test_solo_compara_el_deposito_del_inventario(): void
    {
        $this->articulo('001', 'ARTICULO UNO');

        $inventario = $this->inventario('1000');

        $this->stock('001', '1000', 5);
        $this->stock('001', '3000', 9);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertCount(1, $filas);
        $this->assertSame(5.0, $filas->first()['stock']);
    }

    public function test_exporta_el_control_a_excel(): void
    {
        $this->articulo('001', 'ARTICULO UNO');

        $inventario = $this->inventario();

        $this->contar($inventario, '001', 5);
        $this->stock('001', '1000', 3);

        Excel::fake();

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->call('exportar');

        Excel::assertDownloaded(
            'control_diferencias_inventario_' . $inventario . '_' . now()->toDateString() . '.xlsx',
            fn ($export) => $export->collection()->count() === 1
                && $export->collection()->first()['estado'] === 'Diferencia'
                && $export->collection()->first()['diferencia'] === 2.0
        );
    }

    public function test_no_exporta_si_no_hay_filas(): void
    {
        Excel::fake();

        // Sin filas que exportar se avisa y no se intenta generar el Excel.
        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $this->inventario())
            ->call('exportar')
            ->assertNotified();
    }

    public function test_el_listado_de_inventarios_tiene_el_acceso_al_control(): void
    {
        $inventario = $this->inventario();

        $response = $this->get('/admin/inventarios');

        $response->assertOk();
        $response->assertSee('/admin/control-diferencias?inventario=' . $inventario, false);
    }

    /**
     * Escenario compartido por los tests de fecha: el artículo 001 tiene
     * stock vigente 47 en el depósito 1000 y tres movimientos:
     *
     *  - 2021-06-01 COMPRA (afestk +1): +50
     *  - 2026-11-01 FACTURACIÓN (afestk -1): -3
     *  - 2026-11-02 COMPRA inactiva (afestk +1): +10
     *
     * El punto medio del rango de fechas cae hacia2023, así que una fecha
     * de2026 usa la dirección "vigente − posteriores" y una de2022 la
     * dirección "suma de anteriores".
     */
    private function escenarioFecha(): int
    {
        $this->articulo('001', 'ARTICULO UNO');
        $this->stock('001', '1000', 47);

        $this->movimiento('M1', '2021-06-01 00:00:00', 2);
        $this->renglon('M1', '001', '1000', 50);

        $this->movimiento('M2', '2026-11-01 00:00:00', 51);
        $this->renglon('M2', '001', '1000', 3);

        $this->movimiento('M3', '2026-11-02 00:00:00', 2, '1');
        $this->renglon('M3', '001', '1000', 10);

        return $this->inventario();
    }

    /** Fecha reciente: stock = vigente − (−3 + 10) = 47 − 7 = 40. */
    public function test_fecha_reciente_resta_los_movimientos_posteriores(): void
    {
        $inventario = $this->escenarioFecha();

        $this->contar($inventario, '001', 40);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2026-06-01')
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertCount(1, $filas);
        $this->assertSame(40.0, $filas->first()['stock']);
        $this->assertSame('sin_diferencia', $filas->first()['estado']);
    }

    /** Fecha vieja: suma los movimientos anteriores al corte (+50). */
    public function test_fecha_vieja_suma_los_movimientos_anteriores(): void
    {
        $inventario = $this->escenarioFecha();

        $this->contar($inventario, '001', 50);

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2022-06-01')
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertCount(1, $filas);
        $this->assertSame(50.0, $filas->first()['stock']);
        $this->assertSame('sin_diferencia', $filas->first()['estado']);
    }

    /** El saldo vigente refleja los movimientos anulados: se descuentan igual. */
    public function test_los_movimientos_inactivos_tambien_descuentan(): void
    {
        $inventario = $this->escenarioFecha();

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2026-06-01')
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        //47 − (−3 + 10) = 40: el +10 inactivo también se resta.
        $this->assertSame(40.0, $filas->first()['stock']);
    }

    /** Con la fecha de hoy se usa el stock vigente sin recalcular. */
    public function test_con_la_fecha_de_hoy_se_usa_el_stock_vigente(): void
    {
        $inventario = $this->escenarioFecha();

        $component = Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', now()->toDateString())
            ->set('filtro', 'todos');

        $filas = collect($component->instance()->filasPaginadas());

        $this->assertSame(47.0, $filas->first()['stock']);

        // El stock vigente no se cachea: siempre está actualizado.
        $this->assertFalse(
            Cache::has(app(ComparadorInventario::class)->claveStock('1000', now()->toDateString()))
        );
    }

    /** El stock reconstruido se cachea y ACTUALIZAR lo vuelve a calcular. */
    public function test_cachea_el_stock_reconstruido_y_lo_olvida_al_actualizar(): void
    {
        $inventario = $this->escenarioFecha();

        $clave = app(ComparadorInventario::class)->claveStock('1000', '2026-06-01');

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2026-06-01');

        $this->assertTrue(Cache::has($clave));

        // Con el stock ya cacheado, un cambio en el ERP no se refleja hasta
        // que se pide actualizar.
        $this->stock('001', '1000', 100);

        $filas = collect(
            Livewire::test(ControlDiferencias::class)
                ->set('inventario', $inventario)
                ->set('fecha', '2026-06-01')
                ->set('filtro', 'todos')
                ->instance()
                ->filasPaginadas()
        );

        $this->assertSame(40.0, $filas->first()['stock']);

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2026-06-01')
            ->call('actualizar');

        // ACTUALIZAR borra el resultado viejo y el render del mismo request
        // lo vuelve a calcular con el stock nuevo (100 − 7 = 93).
        $this->assertSame(93.0, Cache::get($clave)['001']['stock']);

        $filas = collect(
            Livewire::test(ControlDiferencias::class)
                ->set('inventario', $inventario)
                ->set('fecha', '2026-06-01')
                ->set('filtro', 'todos')
                ->instance()
                ->filasPaginadas()
        );

        $this->assertSame(93.0, $filas->first()['stock']);
    }

    /** La fecha se ajusta al rango con datos y se avisa. */
    public function test_ajusta_la_fecha_fuera_de_rango(): void
    {
        $inventario = $this->inventario();

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2015-01-01')
            ->assertSet('fecha', ComparadorInventario::FECHA_MINIMA)
            ->assertNotified();

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', now()->addYear()->toDateString())
            ->assertSet('fecha', now()->toDateString())
            ->assertNotified();

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', 'no-es-fecha')
            ->assertSet('fecha', now()->toDateString())
            ->assertNotified();
    }

    /** En pantalla se ve contra qué fecha se está comparando. */
    public function test_muestra_en_pantalla_la_fecha_de_corte(): void
    {
        $inventario = $this->escenarioFecha();

        Livewire::test(ControlDiferencias::class)
            ->set('inventario', $inventario)
            ->set('fecha', '2026-06-01')
            ->assertSee('01/06/2026')
            ->assertSee('reconstruido con los movimientos del ERP');
    }
}

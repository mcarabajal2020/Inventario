<?php

namespace Tests\Feature;

use App\Filament\Pages\Usuarios;
use App\Models\User;
use App\Models\UsuarioRecurso;
use App\Services\ControlAcceso;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bloqueo de recursos por usuario: los usuarios son los del ERP (tabla
 * `sisusuar` en memoria) y los permisos se guardan en la tabla local
 * `usuario_recursos`. La sección Usuarios exige figurar en SIS_ADMINS.
 */
class ControlAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Usuarios del ERP en memoria: la pantalla sólo los lee.
            'database.connections.siserpy' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'recursos.admins' => ['admin-erp'],
        ]);

        DB::purge('siserpy');

        DB::connection('siserpy')->getSchemaBuilder()->create('sisusuar', function (Blueprint $table) {
            $table->string('sisusrcod');
            $table->string('sisusrnom')->nullable();
            $table->string('sisgrpcod')->nullable();
            $table->string('sisusrseg')->nullable();
            $table->integer('inactivo')->default(0);
        });

        DB::connection('siserpy')->table('sisusuar')->insert([
            ['sisusrcod' => 'admin-erp', 'sisusrnom' => 'Administrador', 'sisgrpcod' => 'admin', 'inactivo' => 0],
            ['sisusrcod' => 'operario', 'sisusrnom' => 'Operario', 'sisgrpcod' => 'deposito', 'inactivo' => 0],
        ]);

        $this->actingAs($this->usuario('operario'));
    }

    protected function tearDown(): void
    {
        DB::purge('siserpy');

        parent::tearDown();
    }

    private function usuario(string $codigo): User
    {
        $usuario = new User(['sisusrcod' => $codigo]);
        $usuario->sisusrnom = $codigo === 'admin-erp' ? 'Administrador' : 'Operario';

        return $usuario;
    }

    private function bloquear(string $codigo, string $recurso): void
    {
        UsuarioRecurso::query()->create([
            'sisusrcod' => $codigo,
            'recurso' => $recurso,
            'habilitado' => false,
        ]);
    }

    /* ----------------------------------------------------------------
     |  Mapeo de rutas a recursos
     * ------------------------------------------------------------- */

    public function test_mapea_las_rutas_del_panel_a_su_recurso(): void
    {
        $this->assertSame('dashboard', ControlAcceso::recursoDeRuta('admin'));
        $this->assertSame('inventarios', ControlAcceso::recursoDeRuta('admin/inventarios'));
        $this->assertSame('inventarios', ControlAcceso::recursoDeRuta('admin/inventarios/5/edit'));
        $this->assertSame('inventarios', ControlAcceso::recursoDeRuta('admin/tomar-inventario/5'));
        $this->assertSame('recepcion', ControlAcceso::recursoDeRuta('admin/recepcion-mercaderia'));
        $this->assertSame('transferencias', ControlAcceso::recursoDeRuta('admin/transferencias'));
        $this->assertSame('consulta-stock', ControlAcceso::recursoDeRuta('admin/consulta-stock'));
        $this->assertSame('control-diferencias', ControlAcceso::recursoDeRuta('admin/control-diferencias'));
        $this->assertSame('usuarios', ControlAcceso::recursoDeRuta('admin/usuarios'));
    }

    public function test_las_rutas_fuera_del_catalogo_no_se_bloquean(): void
    {
        // Login, perfil, Livewire, assets: quedan afuera del control.
        $this->assertNull(ControlAcceso::recursoDeRuta('admin/login'));
        $this->assertNull(ControlAcceso::recursoDeRuta('admin/user/profile'));
        $this->assertNull(ControlAcceso::recursoDeRuta('livewire/update'));
        $this->assertNull(ControlAcceso::recursoDeRuta('css/app.css'));
    }

    /* ----------------------------------------------------------------
     |  Acceso por defecto: todo habilitado
     * ------------------------------------------------------------- */

    public function test_por_defecto_todos_los_recursos_estan_habilitados(): void
    {
        foreach (array_keys(ControlAcceso::catalogo()) as $recurso) {
            // La sección Usuarios exige ser admin por .env (SIS_ADMINS),
            // no depende de la tabla: se aserta aparte.
            if ($recurso === 'usuarios') {
                continue;
            }

            $this->assertTrue(
                ControlAcceso::habilitado($this->usuario('operario'), $recurso),
                "El recurso {$recurso} debería estar habilitado sin fila en la tabla"
            );
        }

        $this->assertFalse(
            ControlAcceso::habilitado($this->usuario('operario'), 'usuarios'),
            'La sección Usuarios no debería estar habilitada para un no admin'
        );

        $this->get('/admin/inventarios')->assertOk();
        $this->get('/admin/consulta-stock')->assertOk();
        $this->get('/admin/control-diferencias')->assertOk();
        $this->get('/admin/transferencias')->assertOk();
    }

    /* ----------------------------------------------------------------
     |  Bloqueo de URLs
     * ------------------------------------------------------------- */

    public function test_bloquea_la_url_de_un_recurso_bloqueado(): void
    {
        $this->bloquear('operario', 'recepcion');

        $this->get('/admin/recepcion-mercaderia')->assertRedirect('/admin');
    }

    public function test_bloquea_tambien_las_rutas_hijas(): void
    {
        $this->bloquear('operario', 'inventarios');

        // La colectora y el editar del recurso comparten la raíz.
        $this->get('/admin/tomar-inventario/5')->assertRedirect('/admin');
        $this->get('/admin/inventarios/5/edit')->assertRedirect('/admin');
    }

    public function test_redirige_a_la_primera_seccion_habilitada(): void
    {
        // Sin dashboard, cae en el primer recurso habilitado del catálogo.
        $this->bloquear('operario', 'dashboard');

        $this->get('/admin')->assertRedirect('/admin/inventarios');
    }

    public function test_sin_recursos_habilitados_responde_403(): void
    {
        foreach (array_keys(ControlAcceso::catalogo()) as $recurso) {
            $this->bloquear('operario', $recurso);
        }

        $this->get('/admin')->assertStatus(403);
    }

    public function test_el_login_sigue_accesible(): void
    {
        foreach (array_keys(ControlAcceso::catalogo()) as $recurso) {
            $this->bloquear('operario', $recurso);
        }

        // Sin sesión: el control no debe cortar el login (no está en el
        // catálogo). Con sesión, Filament redirige al panel igual.
        Auth::logout();

        $this->get('/admin/login')->assertOk();
    }

    /* ----------------------------------------------------------------
     |  Menú
     * ------------------------------------------------------------- */

    public function test_el_menu_oculta_los_recursos_bloqueados(): void
    {
        $this->bloquear('operario', 'recepcion');
        $this->bloquear('operario', 'consulta-stock');

        $respuesta = $this->get('/admin');

        $respuesta->assertOk();
        $respuesta->assertDontSee('/admin/recepcion-mercaderia', false);
        $respuesta->assertDontSee('/admin/consulta-stock', false);
        $respuesta->assertSee('/admin/inventarios', false);
    }

    public function test_el_menu_de_usuarios_no_se_ve_para_no_admins(): void
    {
        // Operario: no es admin por .env, no lo ve.
        $respuesta = $this->get('/admin');

        $respuesta->assertOk();
        $respuesta->assertDontSee('/admin/usuarios', false);
    }

    public function test_el_menu_de_usuarios_se_ve_para_admins(): void
    {
        // En un request aparte: el NavigationManager (scoped) cachea la
        // navegación construida en el primer GET de la instancia.
        $this->actingAs($this->usuario('admin-erp'));

        $respuesta = $this->get('/admin');

        $respuesta->assertOk();
        $respuesta->assertSee('/admin/usuarios', false);
    }

    /* ----------------------------------------------------------------
     |  Sección Usuarios
     * ------------------------------------------------------------- */

    public function test_la_seccion_usuarios_solo_es_accesible_para_admins(): void
    {
        // Operario: no entra aunque la fila diga habilitado.
        UsuarioRecurso::query()->create([
            'sisusrcod' => 'operario',
            'recurso' => 'usuarios',
            'habilitado' => true,
        ]);

        $this->get('/admin/usuarios')->assertRedirect('/admin');

        // Admin: entra.
        $this->actingAs($this->usuario('admin-erp'));

        $this->get('/admin/usuarios')->assertOk();
    }

    public function test_el_admin_ve_la_pantalla_de_usuarios_con_el_catalogo(): void
    {
        $this->actingAs($this->usuario('admin-erp'));

        Livewire::test(Usuarios::class)
            ->assertSee('Seleccionar usuario')
            ->assertSee('Inventarios')
            ->assertSee('Control de Diferencias')
            ->assertSee('Usuarios');
    }

    public function test_guarda_el_estado_de_un_recurso_desde_la_pantalla(): void
    {
        Livewire::test(Usuarios::class)
            ->set('usuario', 'operario')
            ->call('alternar', 'recepcion')
            ->assertNotified();

        $this->assertDatabaseHas('usuario_recursos', [
            'sisusrcod' => 'operario',
            'recurso' => 'recepcion',
            'habilitado' => false,
        ]);

        // Y se puede volver a habilitar.
        Livewire::test(Usuarios::class)
            ->set('usuario', 'operario')
            ->call('alternar', 'recepcion')
            ->assertNotified();

        $this->assertDatabaseHas('usuario_recursos', [
            'sisusrcod' => 'operario',
            'recurso' => 'recepcion',
            'habilitado' => true,
        ]);
    }

    public function test_no_permite_quitarse_la_seccion_usuarios_a_si_mismo(): void
    {
        $this->actingAs($this->usuario('admin-erp'));

        Livewire::test(Usuarios::class)
            ->set('usuario', 'admin-erp')
            ->call('alternar', 'usuarios')
            ->assertNotified();

        $this->assertDatabaseMissing('usuario_recursos', [
            'sisusrcod' => 'admin-erp',
            'recurso' => 'usuarios',
        ]);

        // Sí puede bloquearle la sección a otro admin.
        Livewire::test(Usuarios::class)
            ->set('usuario', 'operario')
            ->call('alternar', 'usuarios');

        $this->assertDatabaseHas('usuario_recursos', [
            'sisusrcod' => 'operario',
            'recurso' => 'usuarios',
            'habilitado' => false,
        ]);
    }

    public function test_muestra_el_estado_actual_de_los_recursos(): void
    {
        $this->bloquear('operario', 'recepcion');

        $component = Livewire::test(Usuarios::class)
            ->set('usuario', 'operario');

        $permisos = $component->instance()->permisos;

        $this->assertFalse($permisos['recepcion']);
        $this->assertTrue($permisos['inventarios']);
        $this->assertTrue($permisos['consulta-stock']);
    }

    public function test_busca_usuarios_por_codigo_o_nombre(): void
    {
        Livewire::test(Usuarios::class)
            ->set('busqueda', 'admin')
            ->assertSee('admin-erp')
            ->assertDontSee('operario');

        Livewire::test(Usuarios::class)
            ->set('busqueda', 'operario')
            ->assertSee('Operario');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterfazEnEspanolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $ahora = date('Y-m-d H:i:s');

        DB::table('inventarios')->insert([
            'sucursal' => 'SUC-ES-TEST',
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

    public function test_la_aplicacion_usa_el_espanol_como_idioma(): void
    {
        $this->assertSame('es', config('app.locale'));

        $this->assertSame('es', app()->getLocale());
    }

    public function test_el_listado_de_inventarios_muestra_la_interfaz_en_espanol(): void
    {
        $response = $this->get('/admin/inventarios');

        $response->assertOk();

        $response->assertSee('Crear inventario');
        $response->assertSee('Filtros');
        $response->assertSee('Editar');

        $response->assertDontSee('New inventario');
    }

    public function test_los_mensajes_de_validacion_estan_en_espanol(): void
    {
        $errores = validator(
            ['fecha_inicio' => 'no-es-fecha', 'cantidad' => 'texto'],
            [
                'sucursal' => 'required',
                'fecha_inicio' => 'date',
                'cantidad' => 'numeric|max:10',
            ]
        )->errors();

        $this->assertSame(
            'El campo sucursal es obligatorio.',
            $errores->first('sucursal')
        );

        $this->assertSame(
            'El campo fecha de inicio no es una fecha válida.',
            $errores->first('fecha_inicio')
        );

        $this->assertSame(
            'El campo cantidad debe ser un número.',
            $errores->first('cantidad')
        );
    }
}

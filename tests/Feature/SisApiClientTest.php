<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiNoRespondeException;
use App\Services\SisApiClient;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SisApiClientTest extends TestCase
{
    use RefreshDatabase;

    private bool $conPassword = true;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'sis.empresa' => 'TEST',
            'sis.username' => null,
            'sis.password' => null,
        ]);
    }

    private function actuarComoUsuario(): void
    {
        $user = new User(['sisusrcod' => 'test-user']);

        $user->sisusrnom = 'Usuario Test';

        if ($this->conPassword) {
            // Formato del ERP: carácter de control + contraseña + relleno.
            $user->sisusrseg = 'Nfrase123 1234567890123456';
        }

        $this->actingAs($user);
    }

    private function fakeSis(array $adicional = []): void
    {
        Http::fake(array_merge([
            '*/sis/login/' => Http::response([], 200, ['Session' => 'sesion-123']),
            '*/stock/depositos/' => Http::response([['depcod' => 'D1', 'depnom' => 'Depósito Central', 'succod' => 1]]),
        ], $adicional, [
            '*' => Http::response([]),
        ]));
    }

    public function test_abre_la_sesion_con_el_usuario_erp_logueado(): void
    {
        $this->actuarComoUsuario();

        $this->fakeSis();

        $depositos = app(SisApiClient::class)->depositos();

        $this->assertCount(1, $depositos);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sis/login/')
                && $request['sisusrcod'] === 'test-user'
                && $request['sisusrpwd'] === 'frase123';
        });

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sis/conectar-empresa/')
                && $request['sisempcod'] === 'TEST'
                && $request->hasHeader('Session', 'sesion-123');
        });

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/stock/depositos/')
                && $request->hasHeader('Session', 'sesion-123')
                && $request->hasHeader('Authorization', 'Token ' . config('sis.token'));
        });
    }

    public function test_cachea_la_sesion_y_no_vuelve_a_loguear(): void
    {
        $this->actuarComoUsuario();

        $this->fakeSis();

        $cliente = app(SisApiClient::class);

        $cliente->depositos();
        $cliente->proveedores();

        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sis/login/'))->count());
    }

    public function test_no_loguea_si_el_usuario_no_tiene_credenciales(): void
    {
        $this->conPassword = false;

        $this->actuarComoUsuario();

        $this->fakeSis();

        app(SisApiClient::class)->depositos();

        $this->assertSame(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sis/login/'))->count());
    }

    public function test_usa_las_credenciales_fijas_de_env_como_respaldo(): void
    {
        $this->conPassword = false;

        $this->actuarComoUsuario();

        config(['sis.username' => 'servicio', 'sis.password' => 'clave-servicio']);

        $this->fakeSis([
            '*/sis/login/' => Http::response([], 200, ['Session' => 'sesion-env']),
        ]);

        app(SisApiClient::class)->depositos();

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sis/login/')
                && $request['sisusrcod'] === 'servicio'
                && $request['sisusrpwd'] === 'clave-servicio';
        });
    }

    public function test_si_el_erp_no_responde_no_vuelve_a_llamar_en_los_siguientes_segundos(): void
    {
        $this->actuarComoUsuario();

        $llamadas = 0;

        Http::fake([
            '*/sis/login/' => Http::response([], 200, ['Session' => 'sesion-123']),
            '*/sis/conectar-empresa/' => Http::response(['message' => 'Conexión establecida']),
            // El ERP se quedó sin responder: todo lo que no sea la sesión falla.
            '*' => function ($request) use (&$llamadas) {
                if (str_contains($request->url(), '/sis/')) {
                    return null;
                }

                $llamadas++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $cliente = app(SisApiClient::class);

        try {
            $cliente->depositos();

            $this->fail('Debía avisar que el ERP no responde.');
        } catch (ApiNoRespondeException $e) {
            $this->assertStringContainsString('/stock/depositos/', $e->getMessage());
        }

        $this->assertSame(1, $llamadas);

        // Siguiente pantalla: falla rápido sin volver a golpear la API.
        try {
            $cliente->proveedores();

            $this->fail('Debía avisar que el ERP no responde.');
        } catch (ApiNoRespondeException $e) {
            $this->assertStringContainsString('no responde', $e->getMessage());
        }

        $this->assertSame(1, $llamadas);
    }

    public function test_si_no_hay_credenciales_el_aviso_es_de_credenciales(): void
    {
        $this->conPassword = false;

        $this->actuarComoUsuario();

        Http::fake([
            '*' => Http::response(['message' => 'Authentication credentials were not provided.'], 401),
        ]);

        try {
            app(SisApiClient::class)->depositos();

            $this->fail('Debía avisar que faltan credenciales.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Revise su usuario y contraseña', $e->getMessage());
        }
    }

    public function test_la_numeracion_pide_el_codigo_de_sucursal(): void
    {
        $this->actuarComoUsuario();

        $this->fakeSis([
            '*/api/sucursales/' => Http::response([['succod' => 7, 'sucnom' => 'Sucursal Test']]),
            '*/stock/movimientos/numeracion/*' => Http::response(['manual' => false]),
        ]);

        $numeracion = app(SisApiClient::class)->numeracion();

        $this->assertFalse($numeracion['manual']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'numeracion/66/?succod=7'));
    }

    public function test_avisa_cual_configuracion_falta_para_hablar_con_la_api(): void
    {
        config(['sis.token' => null, 'sis.empresa' => 'Mutual']);

        $this->assertStringContainsString('SIS_API_TOKEN', (string) app(SisApiClient::class)->configFaltante());

        config(['sis.token' => 'token-de-pruebas', 'sis.empresa' => null]);

        $this->assertStringContainsString('SIS_EMPRESA', (string) app(SisApiClient::class)->configFaltante());

        config(['sis.empresa' => 'Mutual']);

        $this->assertNull(app(SisApiClient::class)->configFaltante());
    }

    public function test_sin_token_la_llamada_falla_con_el_mensaje_de_configuracion(): void
    {
        config(['sis.token' => null]);

        $this->expectExceptionMessage('SIS_API_TOKEN');

        app(SisApiClient::class)->depositos();
    }
}

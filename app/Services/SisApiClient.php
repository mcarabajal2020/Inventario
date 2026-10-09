<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SisApiClient
{
    public function __construct(
        protected ?string $empresa = null,
    ) {
        $this->empresa = $empresa ?? config('sis.empresa');
    }

    /**
     * Código de empresa que usa la API (SIS_EMPRESA).
     */
    public function empresa(): string
    {
        return (string) $this->empresa;
    }

    /**
     * Depósitos del ERP: [{depcod, depnom}, ...]
     */
    public function depositos(): array
    {
        return $this->desdeCache('sis.depositos', fn () => $this->collection($this->get("/{$this->empresa()}/stock/depositos/")));
    }

    /**
     * Proveedores del ERP: [{ctacod, ctanom}, ...]
     */
    public function proveedores(): array
    {
        return $this->desdeCache('sis.proveedores', fn () => $this->collection($this->get("/{$this->empresa()}/compras-proveedores/cuentas/?ordering=ctanom")));
    }

    /**
     * Numeración de movimientos: {manual: bool, ...}
     *
     * La API exige el código de sucursal: se toma el del depósito indicado
     * (o la primera sucursal de la empresa como respaldo).
     */
    public function numeracion(string $depcod = ''): array
    {
        $deposito = collect($this->depositos())->firstWhere('depcod', $depcod);

        $succod = is_array($deposito) ? ($deposito['succod'] ?? null) : null;

        if (blank($succod)) {
            $succod = $this->sucursales()[0]['succod'] ?? null;
        }

        if (blank($succod)) {
            return [];
        }

        $succod = (string) $succod;

        return $this->desdeCache("sis.numeracion.{$succod}", fn () => $this->collection($this->get("/{$this->empresa()}/stock/movimientos/numeracion/66/?succod={$succod}")));
    }

    /**
     * Sucursales de la empresa: [{succod, sucnom}, ...]
     */
    public function sucursales(): array
    {
        return $this->desdeCache('sis.sucursales', fn () => $this->collection($this->get("/{$this->empresa()}/api/sucursales/")));
    }

    /**
     * Listas que cambian poco: se cachean para no consultar el ERP en
     * cada render de las pantallas.
     *
     * Si el ERP no contesta se guarda una marca de falla: durante unos
     * segundos las siguientes consultas fallan rápido en lugar de esperar
     * el timeout en cada render.
     */
    protected function desdeCache(string $clave, callable $obtener): array
    {
        $lista = Cache::get($clave);

        if (! is_null($lista)) {
            return $lista;
        }

        if (Cache::get('sis.api.fallo')) {
            throw new ApiNoRespondeException('La API del ERP no responde. Inténtelo de nuevo en unos segundos.');
        }

        try {
            $lista = $obtener();
        } catch (ApiNoRespondeException $e) {
            Cache::put('sis.api.fallo', true, now()->addSeconds(30));

            throw $e;
        }

        if (is_array($lista) && $lista !== []) {
            Cache::forget('sis.api.fallo');

            Cache::put($clave, $lista, now()->addMinutes(30));
        }

        return is_array($lista) ? $lista : [];
    }

    /**
     * Busca un artículo por código de barras, código interno o descripción.
     */
    public function buscarArticulo(string $codigo): array
    {
        $codigo = trim($codigo);

        $base = "/{$this->empresa()}/articulos/?inactivo=0&";

        if ($codigo === '') {
            return [];
        }

        $urls = preg_match('/[A-Za-z]/', $codigo)
            ? [$base . "artdes__icontains={$codigo}"]
            : [
                $base . "artcodbar={$codigo}",
                $base . "artcod={$codigo}",
                $base . "artdes__icontains={$codigo}",
            ];

        foreach ($urls as $url) {
            $data = $this->get($url);

            if (! empty($data)) {
                return $this->collection($data);
            }
        }

        return [];
    }

    /**
     * Stock de un artículo en un depósito determinado.
     */
    public function stockPorDeposito(string $codigo, string $depcod): array
    {
        $codigo = trim($codigo);

        $base = "/{$this->empresa()}/stock/consulta-stock/?";

        $urls = [
            "{$base}artcodbar={$codigo}&depcod={$depcod}",
            "{$base}artcod={$codigo}&depcod={$depcod}",
            "{$base}artdes__icontains={$codigo}&depcod={$depcod}",
        ];

        foreach ($urls as $url) {
            $data = $this->get($url);

            if (! empty($data)) {
                return $this->collection($data);
            }
        }

        return [];
    }

    /**
     * Stock de un artículo en todos los depósitos, sin filtrar por depósito
     * (lo usa la pantalla de consulta de stock).
     *
     * Devuelve una fila por depósito con el código y la descripción del
     * artículo, sumando las filas repetidas que devuelve el ERP.
     */
    public function stockPorArticulo(string $codigo): array
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return [];
        }

        $base = "/{$this->empresa()}/stock/consulta-stock/?";

        $urls = [
            "{$base}artcodbar={$codigo}",
            "{$base}artcod={$codigo}",
            "{$base}artdes__icontains={$codigo}",
        ];

        foreach ($urls as $url) {
            $data = $this->get($url);

            if (! empty($data)) {
                return $this->agruparPorDeposito($this->collection($data));
            }
        }

        return [];
    }

    /**
     * Agrupa el stock por depósito: el ERP devuelve varias filas por
     * depósito y hay que sumarlas.
     */
    protected function agruparPorDeposito(array $filas): array
    {
        $agrupado = [];

        foreach ($filas as $fila) {
            $deposito = is_array($fila['deposito'] ?? null) ? $fila['deposito'] : [];
            $depcod = $deposito['codigo'] ?? null;

            if (blank($depcod)) {
                continue;
            }

            if (! isset($agrupado[$depcod])) {
                $articulo = is_array($fila['articulo'] ?? null) ? $fila['articulo'] : [];

                $agrupado[$depcod] = [
                    'artcod' => $articulo['codigo'] ?? null,
                    'artdes' => $articulo['descripcion'] ?? null,
                    'depcod' => $depcod,
                    'depnom' => $deposito['nombre'] ?? null,
                    'stock' => 0,
                ];
            }

            $agrupado[$depcod]['stock'] += (float) ($fila['stock'] ?? 0);
        }

        return collect($agrupado)
            ->sortBy(fn ($saldo) => $saldo['depnom'] ?? '', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(function ($saldo) {
                $saldo['stock'] = round($saldo['stock'], 2);

                return $saldo;
            })
            ->all();
    }

    /**
     * Recepción de mercadería - paso 1: valida depósito y proveedor.
     * Devuelve el mensaje de la API (string).
     */
    public function recepcionPaso1(string $fecha, string $deposito, string $proveedor): string
    {
        return $this->mensaje($this->post("/{$this->empresa()}/compras-proveedores/recepcion-mercaderia/paso1/", [
            'fecha' => $fecha,
            'deposito' => $deposito,
            'proveedor' => $proveedor,
        ]));
    }

    /**
     * Recepción de mercadería - paso 2: punto de venta y hoja.
     */
    public function recepcionPaso2(string $fecha, string $puntoVenta, string $hoja): string
    {
        return $this->mensaje($this->post("/{$this->empresa()}/compras-proveedores/recepcion-mercaderia/paso2/", [
            'fecha_emision' => $fecha,
            'punto_venta' => $puntoVenta,
            'hoja' => $hoja,
        ]));
    }

    /**
     * Recepción de mercadería - paso 3: guarda la factura con su detalle.
     *
     * @param  array{artcod: string, stkcan: float}[]  $articulos
     */
    public function recepcionPaso3(string $fecha, string $puntoVenta, string $proveedor, string $comprobante, string $deposito, array $articulos): array
    {
        $data = $this->post("/{$this->empresa()}/compras-proveedores/recepcion-mercaderia/paso3/", [
            'cemcod' => $puntoVenta,
            'cbtfec' => $fecha,
            'ctacod' => $proveedor,
            'cbtnro' => $comprobante,
            'remcbtfec' => $fecha,
            'succod' => 1,
            'depcod' => $deposito,
            'articulos' => $articulos,
        ]);

        if (is_array($data) && isset($data['error'])) {
            return ['message' => (string) $data['error']];
        }

        return is_array($data) ? $data : ['message' => (string) $data];
    }

    /**
     * Transferencia entre depósitos.
     *
     * @param  array{artcod: string, stkcan: float}[]  $articulos
     * @return array{message: ?string, cbtnro: ?string}
     */
    public function transferir(string $comprobante, string $depositoOrigen, string $depositoDestino, array $articulos): array
    {
        $data = $this->post("/{$this->empresa()}/stock/movimientos/", [
            'cbtnro' => $comprobante,
            'cbtcod' => 0,
            'tmscod' => 66,
            'depcod' => (string) $depositoOrigen,
            'depdstcod' => (string) $depositoDestino,
            'articulos' => $articulos,
        ]);

        if (! is_array($data)) {
            return ['message' => (string) $data, 'cbtnro' => null];
        }

        return [
            'message' => $data['message'] ?? $data['error'] ?? null,
            'cbtnro' => $data['aditional-data']['cbtnro'] ?? null,
        ];
    }

    /**
     * Cabecera de sesión que envía la API del SIS (se cachea por usuario y empresa).
     *
     * Se autentica con el usuario ERP que tiene la sesión abierta en la app.
     * `sisusrseg` guarda la contraseña precedida por un carácter de control y
     * separada del resto del campo por espacios, así que se prueban un par de
     * variantes. Como respaldo se pueden fijar SIS_API_USER / SIS_API_PASSWORD.
     */
    public function sesion(): ?string
    {
        $clave = $this->claveSesion();

        $sesion = Cache::get($clave);

        if (filled($sesion)) {
            return $sesion;
        }

        // Si el login falló hace un rato no se vuelve a martillar la API.
        if (Cache::get($clave . '.sin-sesion')) {
            return null;
        }

        foreach ($this->candidatas() as [$username, $password]) {
            $sesion = $this->loguear($username, $password);

            if (filled($sesion)) {
                Cache::forget($clave . '.sin-sesion');
                Cache::put($clave, $sesion, now()->addHours(8));

                return $sesion;
            }
        }

        Cache::put($clave . '.sin-sesion', true, now()->addSeconds(60));

        return null;
    }

    /**
     * Intenta abrir sesión y conectar la empresa de trabajo.
     */
    protected function loguear(string $username, string $password): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . config('sis.token'),
                'Accept' => 'application/json',
            ])
                ->timeout(config('sis.timeout'))
                ->asForm()
                ->post(rtrim(config('sis.url'), '/') . '/sis/login/', [
                    'sisusrcod' => $username,
                    'sisusrpwd' => $password,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('SIS API sin respuesta al iniciar sesión: ' . $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('SIS API rechazó la sesión', ['status' => $response->status(), 'usuario' => $username]);

            return null;
        }

        $sesion = collect($response->header('Session'))->first() ?? null;

        if (blank($sesion)) {
            Log::warning('SIS API no devolvió la cabecera Session', ['usuario' => $username]);

            return null;
        }

        return $this->conectarEmpresa($sesion) ? $sesion : null;
    }

    /**
     * La sesión sólo sirve para la empresa con la que se conectó.
     */
    protected function conectarEmpresa(string $sesion): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . config('sis.token'),
                'Accept' => 'application/json',
                'Session' => $sesion,
            ])
                ->timeout(config('sis.timeout'))
                ->asForm()
                ->post(rtrim(config('sis.url'), '/') . '/sis/conectar-empresa/', [
                    'sisempcod' => $this->empresa(),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('SIS API sin respuesta al conectar la empresa: ' . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::warning('SIS API no conectó la empresa', [
                'empresa' => $this->empresa(),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Olvida la sesión cacheada (para reintentar después de un 401/403).
     */
    public function olvidarSesion(): void
    {
        Cache::forget($this->claveSesion());
        Cache::forget($this->claveSesion() . '.sin-sesion');
    }

    /**
     * Pares usuario/contraseña a probar, en orden de prioridad.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function candidatas(): array
    {
        $usuario = auth()->user();

        $variantes = [];

        if (filled($usuario?->sisusrcod) && filled($usuario?->sisusrseg)) {
            $username = (string) $usuario->sisusrcod;

            foreach ($this->variantesDeClave((string) $usuario->sisusrseg) as $clave) {
                $variantes[] = [$username, $clave];
            }
        }

        if (filled(config('sis.username')) && filled(config('sis.password'))) {
            $variantes[] = [(string) config('sis.username'), (string) config('sis.password')];
        }

        return $variantes;
    }

    /**
     * Variantes posibles de `sisusrseg`: la contraseña real ocupa hasta el
     * primer espacio después del carácter de control inicial.
     *
     * @return array<int, string>
     */
    protected function variantesDeClave(string $seg): array
    {
        $seg = trim($seg);

        $variantes = [];

        if (preg_match('/^.(.+?)(?:\s|$)/', $seg, $coincide)) {
            $variantes[] = $coincide[1];
        }

        if ($seg !== '') {
            $variantes[] = $seg;
        }

        foreach (preg_split('/\s+/', $seg, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
            $variantes[] = $parte;
        }

        return array_values(array_unique($variantes));
    }

    /**
     * La sesión se guarda por usuario y empresa: no debe mezclarse entre cuentas.
     */
    protected function claveSesion(): string
    {
        [$username] = $this->candidatas()[0] ?? [null];

        return config('sis.session_cache') . '.' . ($username ?: 'anonimo') . '.' . $this->empresa();
    }

    /**
     * @return array<string, string>
     */
    protected function autorizacion(): array
    {
        $headers = [
            'Authorization' => 'Token ' . config('sis.token'),
            'Accept' => 'application/json',
        ];

        if (filled($sesion = $this->sesion())) {
            $headers['Session'] = $sesion;
        }

        return $headers;
    }

    protected function get(string $url): mixed
    {
        return $this->llamar('get', $url);
    }

    protected function post(string $url, array $data): mixed
    {
        return $this->llamar('post', $url, $data);
    }

    protected function llamar(string $metodo, string $url, array $data = [], bool $reintentado = false): mixed
    {
        $absoluta = rtrim(config('sis.url'), '/') . $url;

        $autorizacion = $this->autorizacion();

        try {
            $http = Http::withHeaders($autorizacion)
                ->timeout(config('sis.timeout'));

            $response = $metodo === 'post'
                ? $http->post($absoluta, $data)
                : $http->get($absoluta);
        } catch (ConnectionException $e) {
            Log::error('SIS API sin respuesta', ['url' => $absoluta, 'error' => $e->getMessage()]);

            throw new ApiNoRespondeException('No se pudo conectar con la API del ERP (' . $absoluta . ').');
        }

        if ($response->unauthorized() || $response->forbidden()) {
            // Si la sesión ya no sirve se pide otra y se reintenta una vez.
            if (isset($autorizacion['Session']) && ! $reintentado) {
                $this->olvidarSesion();

                return $this->llamar($metodo, $url, $data, true);
            }

            if (! isset($autorizacion['Session'])) {
                throw new Exception('No se pudo iniciar sesión en la API del ERP. Revise su usuario y contraseña.');
            }

            throw new Exception('La sesión con la API del ERP expiró. Vuelva a intentarlo.');
        }

        if ($response->failed()) {
            Log::error('SIS API devolvió error', [
                'url' => $absoluta,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $json = $response->json();

            // La API responde los errores de negocio como {"message": "..."}
            if (is_array($json) && isset($json['message'])) {
                return ['error' => $json['message']];
            }

            return ['error' => 'Error ' . $response->status() . ' al consultar la API del ERP.'];
        }

        $json = $response->json();

        Log::debug('SIS API', ['url' => $absoluta, 'status' => $response->status()]);

        return $json;
    }

    /**
     * Normaliza la respuesta de la API (lista directa o paginada).
     */
    protected function collection(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['results']) && is_array($data['results'])) {
            return $data['results'];
        }

        if (isset($data['error'])) {
            return [];
        }

        return $data;
    }

    /**
     * Extrae el mensaje de una respuesta de la API.
     */
    protected function mensaje(mixed $data): string
    {
        if (is_array($data)) {
            if (isset($data['error'])) {
                return (string) $data['error'];
            }

            return (string) ($data['message'] ?? '');
        }

        return (string) $data;
    }
}

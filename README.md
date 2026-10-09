<p align="center"><img src="public/images/fondo.jpg" width="520" alt="Mutual La Emancipación"></p>

# Sistema de Inventario

Aplicación web para **tomar el inventario por sucursal** de la Mutual La Emancipación. Permite abrir un inventario, cargar los artículos escaneados con la colectora, consultar los últimos movimientos, exportar los resultados a Excel, **registrar recepción de mercadería** y **transferencias entre depósitos** y **consultar el stock de un artículo en cada depósito** contra la API del ERP (SIS).

Construida sobre **Laravel 13** con **Filament 5** (panel de administración) y **Livewire**.

---

## Funcionalidades

### Autenticación
- Login con los **usuarios del ERP** (base `siserpy`, tabla `sisusuar`): campos *Usuario* (`sisusrcod`) y *Contraseña*.
- El proveedor de autenticación `siserpy` valida contra el campo `sisusrseg` sin hashing, para mantener la compatibilidad con las contraseñas del ERP.

### Inventarios
- **Alta / edición / baja** de inventarios con sucursal, fecha de inicio y estado (`abierto` / `cerrado`).
- **Listado** con columnas ID, Sucursal, Estado (badge verde/rojo) y Fecha; búsqueda por sucursal, orden por ID descendente y **filtro de estado con "Abierto" por defecto**.
- **Acciones por fila**:
  - `Editar` — modificar la cabecera del inventario (incluido el estado).
  - `Tomar` — abre la colectora; **solo visible mientras el inventario esté abierto**.
  - `Exportar Excel` — resumen por artículo (`ARTICULO`, `DESCRIPCION`, `CANTIDAD` total).
  - `Exportar Movimientos` — detalle (`ID`, `ARTICULO`, `DESCRIPCION`, `CODIGO_BARRA`, `CANTIDAD`, `UBICACION`, `USUARIO`, `FECHA`).
- Para **cerrar** un inventario: `Editar` → estado *Cerrado*. Al cerrarlo desaparece el botón `Tomar` y la colectora rechaza cualquier carga (verificación del estado en el servidor, no solo en la interfaz).

### Colectora de Inventario (`/admin/tomar-inventario/{id}`)
- Campo **Código de barras / artículo**: busca por `artbar.artcodbar` (lector) o por `stkartic0.artcod` (código interno). Si no existe, avisa *Artículo no encontrado*.
- **Cantidad** (con decimales) y **Ubicación** (Góndola / Depósito / Exhibidor).
- Muestra el artículo encontrado (código, descripción y cantidad) antes de guardar.
- Al pulsar **GUARDAR** registra el movimiento y **vuelve a enfocar el campo de escaneo** para seguir sin tocar el mouse.
- Sección **Últimos movimientos**: los 10 últimos artículos cargados **por el usuario logueado**, con su descripción.
- Si el inventario está **cerrado**, no se muestran los inputs ni el botón de guardar y aparece el aviso correspondiente.

### Dashboard
- Pantalla de inicio con el **logo de la Mutual** a todo el ancho.

### Recepción de Mercadería (`/admin/recepcion-mercaderia`)
- Cabecera de la factura: **fecha, depósito, proveedor, punto de venta, hoja y comprobante** (depósitos y proveedores se leen de la API del ERP). El proveedor se puede **buscar por nombre, CUIT o número de cuenta**: al tipear se filtra el desplegable localmente (la lista de 3.095 cuentas viene cacheada 30 minutos), cada opción muestra *nombre (cuenta N - CUIT N)*, si no hay coincidencias se avisa y el proveedor ya elegido se mantiene visible aunque no coincida. El campo **hoja** nace con `1` y vuelve a `1` al cancelar o finalizar.
- Al pulsar **INICIAR RECEPCIÓN** se valida que el **comprobante ya no esté cargado** por ningún usuario (mismo punto de venta + hoja + comprobante, comparados como números: `0001` = `1`). Si está, no se abre la carga y se avisa quién lo cargó, cuándo y en qué estado. También corta el comprobante que quedó en estado `error` cuando el ERP lo rechazó por duplicado (*"Se encontraron duplicados del número de comprobante…"*), porque reintentar siempre termina en el mismo error; los demás errores (p. ej. proveedor inexistente) sí permiten reintentar.
- Además se consulta el **comprobante en el ERP** contra `mutualnew.comcbt` (`cemcod` = punto de venta, `cbtnro` = número; ~2 ms por el índice primario): si ya existe —aunque se haya cargado desde otro sistema o directo en el ERP— no se abre la carga y se avisa fecha, proveedor y usuario que lo registró. La API del SIS no expone esa consulta (el `GET` de `recepcion-mercaderia/` da 500 y el índice del módulo sólo publica `zonas` y `cuentas`). Si la base del negocio no responde, no se bloquea la carga.
- Al escanear, el artículo se busca en `/{empresa}/articulos/` (código de barras → código interno → descripción). Si la búsqueda trae **varios artículos** (típico al buscar por descripción) se muestra una lista con código, descripción y stock para elegir cuál agregar; si hay uno solo se agrega directo. Cada captura genera **un renglón nuevo**, aunque se repita el artículo (no se suman): *Quitar* borra sólo ese renglón y el envío al ERP lleva un renglón por cada uno.
- Botón **CANCELAR CARGA** borra el borrador; **GUARDAR EN EL ERP** ejecuta los 3 pasos de la API (`paso1` → `paso2` → `paso3`) con la fecha en formato `d-m-Y`.
- Se detectan los errores conocidos (`El proveedor no se encontró.`, `Error al guardar informe de recepción`, comprobante duplicado / manual) y el estado queda `finalizada` o `error`.
- Sección **Últimas recepciones**: historial con estado y mensaje del ERP; el comprobante se muestra como `punto de venta-comprobante / hoja` (ej.: `4-12345 / 7`).
- El borrador queda guardado por usuario y se retoma si la página se recarga.
- La cabecera se puede **plegar** con el botón *Ocultar cabecera* (y se pliega sola al iniciar la recepción): queda una línea con el resumen (fecha · depósito · proveedor · Pto Vta · Hoja · Comprobante) y el campo de escaneo queda a la vista sin hacer scroll, sobre todo en el móvil. *Mostrar cabecera* la despliega de nuevo; al cancelar o finalizar vuelve a quedar desplegada.

### Transferencia entre Depósitos (`/admin/transferencias`)
- Cabecera: **fecha, depósito origen, depósito destino** y comprobante.
- El comprobante es **obligatorio sólo si la numeración del ERP es manual** (`/{empresa}/stock/movimientos/numeracion/66/?succod=...`, con la sucursal del depósito seleccionado); si es automática lo asigna el ERP.
- Al escanear se consulta el stock en el depósito origen (`/{empresa}/stock/consulta-stock/`); repetir el artículo genera **un renglón nuevo** (no se suma la cantidad) y *Quitar* borra sólo ese renglón. Si la búsqueda trae **varios artículos** se muestra la lista con código, descripción y stock para elegir cuál agregar.
- **GUARDAR EN EL ERP** envía `POST /{empresa}/stock/movimientos/` (`tmscod: 66`) y guarda el `cbtnro` devuelto.
- Origen y destino deben ser distintos; historial de las últimas transferencias con estado y mensaje.
- La cabecera también se **pliega** con el botón *Ocultar cabecera* (y sola al iniciar la transferencia): resumen de una línea (fecha · origen → destino · comprobante) para que el campo de escaneo quede arriba sin scroll en el móvil.

### Consulta de Stock (`/admin/consulta-stock`)
- Al escanear el código de barras o el código del artículo se muestra el **stock de cada depósito** (`GET /{empresa}/stock/consulta-stock/` sin `depcod`), con una fila por depósito y total al pie.
- En la vista se muestran el **código y la descripción** del artículo consultado, tomados de la respuesta del ERP.
- El ERP devuelve varias filas por depósito (por lote o ubicación): se **suman** y se ordenan por nombre de depósito.
- Si el artículo no tiene stock en ningún depósito se busca igual en `/{empresa}/articulos/` para mostrar su código y descripción con el aviso *Sin stock en ningún depósito*; si no existe, avisa *Código no encontrado*.
- Si el ERP no responde muestra el aviso correspondiente en lugar de un error.

### API del SIS (ERP)
- Cliente propio: `app/Services/SisApiClient.php` + `config/sis.php`.
- Headers `Authorization: Token ...` y `Session`. La sesión se abre con `POST /sis/login/` **usando el usuario ERP logueado** y se cachea **8 horas por usuario y empresa**. `SIS_API_USER` / `SIS_API_PASSWORD` sirven sólo como respaldo si no hay sesión de aplicación.
- La contraseña sale de `sisusrseg`, que la guarda con un **carácter de control al inicio y relleno con espacios** (`N<clave>   <relleno>`): se manda la clave hasta el primer espacio y, si el ERP la rechaza, se prueban el resto de variantes. Tras loguear se llama a `POST /sis/conectar-empresa/` porque **cada sesión queda atada a una empresa**.
- Si la API contesta 401/403 se olvida la sesión, se vuelve a loguear y se reintenta **una sola vez**; si el login falla no se insiste durante 60 segundos. Si no hay sesión (401 sin credenciales) el aviso dice *"No se pudo iniciar sesión en la API del ERP. Revise su usuario y contraseña."*.
- Si el ERP no responde, las pantallas muestran los listados vacíos con el aviso correspondiente en vez de un error 500. Además se marca la falla **30 segundos**: durante ese tiempo los listados fallan rápido (sin esperar el timeout de 20 s en cada render) y se vuelven a consultar solos.
- Depósitos, proveedores, sucursales y numeración se cachean **30 minutos** para no consultar el ERP en cada render.
- Variables en `.env`: `SIS_API_URL`, `SIS_API_TOKEN` (obligatorio: no está en el código), `SIS_EMPRESA`; opcionales `SIS_API_USER`, `SIS_API_PASSWORD`, `SIS_API_TIMEOUT`. Si falta alguna, las pantallas de recepción y transferencia muestran en rojo **cuál variable falta** (y la indicación de `php artisan config:clear` si la configuración quedó cacheada) en lugar del aviso genérico.

### Exportaciones a Excel
- Generadas con `maatwebsite/excel` 4 (`InventarioExport` y `InventarioMovimientosExport`).
- Las descripciones se resuelven contra `mutualnew.stkartic0`.

---

## Modelo de datos

| Tabla | Columnas |
| --- | --- |
| `inventarios` | `id`, `sucursal`, `fecha_inicio`, `estado`, `user_id`, `created_at`, `updated_at` |
| `inventario_movimientos` | `id`, `inventario_id`, `artcod`, `codigo_barra`, `cantidad`, `ubicacion`, `usuario`, `created_at` |
| `recepciones` | `id`, `fecha`, `deposito_cod`, `deposito_nom`, `proveedor_cod`, `proveedor_nom`, `ptovta`, `hoja`, `comprobante`, `estado`, `mensaje`, `user_id`, timestamps |
| `recepcion_detalles` | `id`, `recepcion_id`, `artcod`, `artdes`, `cantidad`, timestamps |
| `transferencias` | `id`, `fecha`, `deposito_origen_cod`, `deposito_origen_nom`, `deposito_destino_cod`, `deposito_destino_nom`, `comprobante`, `cbtnro`, `estado`, `mensaje`, `user_id`, timestamps |
| `transferencia_detalles` | `id`, `transferencia_id`, `artcod`, `artdes`, `cantidad`, timestamps |

> La descripción del artículo **no se almacena** en los movimientos de inventario: se resuelve en pantalla y en los Excel desde `mutualnew`. En recepciones y transferencias sí se guarda `artdes` para el historial.

## Conexiones a bases de datos

| Conexión | Origen | Uso |
| --- | --- | --- |
| `default` | SQLite (`database/database.sqlite`) | Inventario de la aplicación (migraciones, sesiones, colas) |
| `siserpy` | MySQL | Usuarios / login (`sisusuar`) |
| `mutualnew` | MySQL | Artículos (`stkartic0`), códigos de barras (`artbar`) y comprobantes de compras del ERP (`comcbt`) |
| API `SIS_API_URL` | HTTP (SIS) | Depósitos, proveedores, artículos, stock y movimientos (recepción / transferencia) |

---

## Stack tecnológico

- PHP **^8.3** · Laravel **^13** · Filament **^5.9** · Livewire
- `maatwebsite/excel` **^4.0** · PHPUnit **^12** · Vite (assets)

## Requisitos

- PHP >= 8.3 con las extensiones `pdo_sqlite` y `pdo_mysql`.
- Composer.
- Node.js 20+ (sólo para compilar assets).
- Acceso a las bases MySQL `siserpy` y `mutualnew`.
- Acceso de red a la API del SIS (`SIS_API_URL`) para recepción, transferencias y consulta de stock.

## Instalación

```bash
git clone https://github.com/mcarabajal2020/Inventario.git
cd Inventario

composer install
cp .env.example .env
php artisan key:generate
```

Completar en `.env` las conexiones del ERP:

```env
DB_CONNECTION=sqlite

# Hora del servidor: todos los registros (inventarios, movimientos, etc.)
# se guardan con esta zona horaria
APP_TIMEZONE=America/Argentina/Buenos_Aires

# Interfaz en español (Filament) y mensajes de validación (lang/es)
APP_LOCALE=es

DB_SISERPY_HOST=...
DB_SISERPY_PORT=3306
DB_SISERPY_DATABASE=...
DB_SISERPY_USERNAME=...
DB_SISERPY_PASSWORD=...

DB_MUTUALNEW_HOST=...
DB_MUTUALNEW_PORT=3306
DB_MUTUALNEW_DATABASE=...
DB_MUTUALNEW_USERNAME=...
DB_MUTUALNEW_PASSWORD=...

# API del SIS (recepción de mercadería y transferencias)
SIS_API_URL=http://10.0.0.45:8000
SIS_EMPRESA=Mutual
# opcionales:
# SIS_API_TOKEN=...
# SIS_API_USER=...
# SIS_API_PASSWORD=...
```

Continuar con:

```bash
touch database/database.sqlite
php artisan migrate --force

npm install
npm run build

php artisan serve        # http://127.0.0.1:8000
```

> **Nota:** el servidor necesita acceso de red a las bases MySQL; sin ellas el login y la búsqueda de artículos no funcionan.

---

## Uso rápido

1. Ingresar con un usuario del ERP.
2. **Inventarios → + Nuevo**: cargar sucursal, fecha y estado *Abierto*.
3. En el listado (filtra abiertos por defecto) presionar **Tomar**.
4. Escanear el código de barras → verificar artículo → cantidad y ubicación → **GUARDAR**.
5. Repetir; los últimos 10 movimientos aparecen abajo.
6. **Editar** → estado *Cerrado* para finalizar la toma.
7. **Exportar Excel** / **Exportar Movimientos** para descargar los resultados.

### Recepción de mercadería
1. **Recepción de Mercadería** → cargar fecha, depósito, proveedor (buscar por nombre, CUIT o número de cuenta), punto de venta, hoja y comprobante → **Iniciar recepción**.
2. Escanear los artículos y confirmar la cantidad (cada captura es un renglón).
3. **GUARDAR EN EL ERP** y verificar el estado en *Últimas recepciones*.

### Transferencia entre depósitos
1. **Transferencia entre Depósitos** → fecha, depósito origen y destino (+ comprobante si la numeración es manual) → **Iniciar transferencia**.
2. Escanear los artículos que salen del depósito origen.
3. **GUARDAR EN EL ERP**: se guarda el número de comprobante (`cbtnro`) devuelto por la API.

### Consulta de stock
1. **Consulta de Stock** → escanear el código de barras (o el código del artículo) → **CONSULTAR** (o Enter).
2. Se muestra el artículo (código y descripción) y su stock en cada depósito, con total al pie.
3. **LIMPIAR** o volver a escanear para consultar otro artículo.

---

## Tests

```bash
php artisan test
```

72 tests que cubren: filtro de estado del listado, bloqueo de inventarios cerrados, descripciones en los últimos movimientos, el logo del dashboard, la zona horaria del servidor (`APP_TIMEZONE`) en los registros, la interfaz en español (`APP_LOCALE=es`, listado y validaciones), la sesión de la API del SIS (usuario logueado, cacheo, respaldo en `.env`, aviso cuando faltan credenciales y marca de falla cuando el ERP no responde) y los flujos de recepción de mercadería (hoja por defecto, comprobante duplicado local y comprobante ya existente en el ERP, búsqueda de proveedor por CUIT o número de cuenta), transferencia entre depósitos y consulta de stock (agrupación por depósito y artículos sin stock), con `Http::fake()` sobre la API del ERP. En recepción y transferencia se verifica además que **cada captura sea un renglón** (aunque se repita el artículo), que *Quitar* borre sólo ese renglón, que al finalizar se envíe un renglón por cada captura y que la **cabecera se pueda plegar/desplegar** (queda plegada con resumen al iniciar).

## Estructura

```
app/
├── Exports/                  # InventarioExport, InventarioMovimientosExport
├── Filament/
│   ├── Pages/                # TomarInventario (colectora), RecepcionMercaderia,
│   │                         # TransferenciaDepositos, ConsultaStock y Login
│   ├── Resources/Inventarios # CRUD + tabla + acciones
│   └── Widgets/              # LogoMutual (dashboard)
├── Models/                   # Inventario, InventarioMovimiento, Recepcion*, Transferencia*, User, Mutualnew\*
├── Services/SisApiClient.php # Cliente HTTP de la API del SIS
└── Providers/                # Proveedor de autenticación siserpy
resources/views/filament/     # Vistas de colectora, recepción, transferencia, consulta de stock y widget
tests/Feature/                # Tests funcionales
```

## Consideraciones

- Las contraseñas del ERP se comparan en texto plano (`str_contains` sobre `sisusrseg`): es un requisito de compatibilidad con el sistema existente.
- `vendor/bin/pint --test` muestra deudas de estilo heredadas del código original; no se aplicó para no reescribir todo el proyecto.
- El logo utilizado en el dashboard es `public/images/fondo.jpg`.

## Licencia

MIT.

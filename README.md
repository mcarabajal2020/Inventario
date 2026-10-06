<p align="center"><img src="public/images/fondo.jpg" width="520" alt="Mutual La Emancipación"></p>

# Sistema de Inventario

Aplicación web para **tomar el inventario por sucursal** de la Mutual La Emancipación. Permite abrir un inventario, cargar los artículos escaneados con la colectora, consultar los últimos movimientos y exportar los resultados a Excel.

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

### Exportaciones a Excel
- Generadas con `maatwebsite/excel` 4 (`InventarioExport` y `InventarioMovimientosExport`).
- Las descripciones se resuelven contra `mutualnew.stkartic0`.

---

## Modelo de datos

| Tabla | Columnas |
| --- | --- |
| `inventarios` | `id`, `sucursal`, `fecha_inicio`, `estado`, `user_id`, `created_at`, `updated_at` |
| `inventario_movimientos` | `id`, `inventario_id`, `artcod`, `codigo_barra`, `cantidad`, `ubicacion`, `usuario`, `created_at` |

> La descripción del artículo **no se almacena** en los movimientos: se resuelve en pantalla y en los Excel desde `mutualnew`.

## Conexiones a bases de datos

| Conexión | Origen | Uso |
| --- | --- | --- |
| `default` | SQLite (`database/database.sqlite`) | Inventario de la aplicación (migraciones, sesiones, colas) |
| `siserpy` | MySQL | Usuarios / login (`sisusuar`) |
| `mutualnew` | MySQL | Artículos (`stkartic0`) y códigos de barras (`artbar`) |

---

## Stack tecnológico

- PHP **^8.3** · Laravel **^13** · Filament **^5.9** · Livewire
- `maatwebsite/excel` **^4.0** · PHPUnit **^12** · Vite (assets)

## Requisitos

- PHP >= 8.3 con las extensiones `pdo_sqlite` y `pdo_mysql`.
- Composer.
- Node.js 20+ (sólo para compilar assets).
- Acceso a las bases MySQL `siserpy` y `mutualnew`.

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

---

## Tests

```bash
php artisan test
```

12 tests que cubren: filtro de estado del listado, bloqueo de inventarios cerrados, descripciones en los últimos movimientos y el logo del dashboard.

## Estructura

```
app/
├── Exports/                  # InventarioExport, InventarioMovimientosExport
├── Filament/
│   ├── Pages/                # TomarInventario (colectora) y Login
│   ├── Resources/Inventarios # CRUD + tabla + acciones
│   └── Widgets/              # LogoMutual (dashboard)
├── Models/                   # Inventario, InventarioMovimiento, User, Mutualnew\*
└── Providers/                # Proveedor de autenticación siserpy
resources/views/filament/     # Vistas de colectora y widget
tests/Feature/                # Tests funcionales
```

## Consideraciones

- Las contraseñas del ERP se comparan en texto plano (`str_contains` sobre `sisusrseg`): es un requisito de compatibilidad con el sistema existente.
- `vendor/bin/pint --test` muestra deudas de estilo heredadas del código original; no se aplicó para no reescribir todo el proyecto.
- El logo utilizado en el dashboard es `public/images/fondo.jpg`.

## Licencia

MIT.

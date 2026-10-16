/*
 * Baja el CSS publicado a un nivel compatible con Chrome 99+.
 *
 * Filament 5 y Tailwind 4 generan oklch(), color-mix(), @property y otras
 * features que en Chrome llegaron recien en la 111+. En Chrome 99-110 el
 * motor descarta esas reglas y en algunos casos llega a crashear el
 * renderer (pagina de error de Chrome).
 *
 * Uso:
 *   node scripts/downlevel-css.mjs public/css/inventario.css [mas.css ...]
 *
 * Corre automaticamente desde `php artisan assets:compat` (invocado en
 * post-autoload-dump de composer) y desde `npm run build:css`. Es
 * idempotente: si el CSS ya esta bajado, lo deja igual.
 *
 * Requiere Node y el paquete lightningcss (ya presente como dependencia
 * de Tailwind 4 en este proyecto).
 */
import fs from 'node:fs';
import { transform } from 'lightningcss';

const TARGET_CHROME_99 = 99 << 16;
const archivos = process.argv.slice(2);

if (archivos.length === 0) {
    console.error('Uso: node scripts/downlevel-css.mjs <archivo.css> [archivo2.css ...]');
    process.exit(1);
}

let exitCode = 0;

for (const archivo of archivos) {
    if (!fs.existsSync(archivo)) {
        console.error(`[downlevel-css] No existe ${archivo}, se omite.`);
        exitCode = 1;
        continue;
    }

    const original = fs.readFileSync(archivo);
    const antesOklch = (original.toString('utf8').match(/oklch\(/g) || []).length;
    const antesMix = (original.toString('utf8').match(/color-mix\(/g) || []).length;

    let code;

    try {
        code = transform({
            filename: archivo,
            code: original,
            minify: true,
            targets: { chrome: TARGET_CHROME_99 },
            errorRecovery: true,
        }).code;
    } catch (error) {
        console.error(`[downlevel-css] Error transformando ${archivo}: ${error.message}`);
        exitCode = 1;
        continue;
    }

    let css = code.toString('utf8');

    // Red de seguridad: si alguna oklch/color-mix queda de pie en una
    // declaracion (valores con var() que lightningcss no puede resolver),
    // eliminamos la declaracion completa. En Chrome 99-110 la declaracion
    // moderna es invalida e ignoria; sacarla no cambia nada visual ahi.
    // En Chrome moderno tampoco se pierde lo esencial: Tailwind/Filament
    // siempre emiten antes la alternativa en rgb()/var() (o el bloque
    // esta dentro de un @supports que en Chrome viejo ya no aplica).
    css = eliminarDeclaracionesModernas(css);

    // Lo unico que puede quedar son funciones dentro de condiciones
    // @supports (...): eso es seguro, el navegador viejo simplemente
    // descarta el bloque entero sin llegar a parsear su contenido.
    const residuales = fueraDeSoportes(css);

    fs.writeFileSync(archivo, css, 'utf8');

    console.log(
        `[downlevel-css] ${archivo}: oklch ${antesOklch}→${(residuales.match(/oklch\(/g) || []).length} (declaraciones), `
        .concat(`color-mix ${antesMix}→${(residuales.match(/color-mix\(/g) || []).length} (declaraciones)`)
    );

    if (residuales.match(/oklch\(/) || residuales.match(/color-mix\(/)) {
        console.warn(`[downlevel-css] WARN: ${archivo} todavia tiene funciones modernas fuera de @supports; revise visualmente.`);
    }
}

/**
 * Devuelve el CSS sin las condiciones de @supports (lo que hay entre
 * "@supports" y el "{" que abre el bloque). Lo que queda es lo que el
 * navegador va a parsear siempre.
 */
function fueraDeSoportes(css) {
    return css.replace(/@supports[^{]+/g, '');
}

process.exit(exitCode);

/**
 * Elimina declaraciones (propiedad: valor;) que contengan oklch() o
 * color-mix() sin resolver. Parser a nivel de caracteres: respeta
 * llaves/corchetas y comentarios; no necesita ser un parser CSS completo
 * porque solo busca funciones de color dentro de declaraciones.
 */
function eliminarDeclaracionesModernas(css) {
    let salida = '';
    let buffer = '';
    let profundidad = 0;
    let enComentario = false;

    for (let i = 0; i < css.length; i++) {
        const ch = css[i];
        const sig = css[i + 1];

        if (enComentario) {
            buffer += ch;
            if (ch === '*' && sig === '/') {
                buffer += sig;
                i++;
                enComentario = false;
            }
            continue;
        }

        if (ch === '/' && sig === '*') {
            buffer += ch + sig;
            i++;
            enComentario = true;
            continue;
        }

        if (ch === '{') {
            salida += buffer + ch;
            buffer = '';
            profundidad++;
            continue;
        }

        if (ch === '}') {
            salida += cerrarDeclaracion(buffer);
            salida += ch;
            buffer = '';
            if (profundidad > 0) profundidad--;
            continue;
        }

        if (ch === ';' && profundidad > 0) {
            salida += cerrarDeclaracion(buffer, ';');
            buffer = '';
            continue;
        }

        buffer += ch;
    }

    salida += buffer;
    return salida;
}

function cerrarDeclaracion(declaracion, terminador = '') {
    if (profundidadEsDeclaracion(declaracion) && modernaEn(declaracion)) {
        return terminador === ';' ? terminador : '';
    }

    return declaracion + terminador;
}

function modernaEn(texto) {
    return /\b(oklch|color-mix)\s*\(/.test(texto);
}

function profundidadEsDeclaracion(texto) {
    // Una declaracion tiene "propiedad : valor". Un selector o bloque
    // residual lleva ":" tambien, pero al cerrar con '}' el buffer solo
    // contiene la ultima declaracion del bloque (o whitespace).
    return texto.indexOf(':') !== -1 && !/^\s*@/.test(texto);
}

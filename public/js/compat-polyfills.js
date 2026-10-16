/*
 * Polyfills para Chrome 99-110 (el piso soportado de la app).
 *
 * Filament 5 y Livewire 3 usan algunas APIs de ES2023 que en Chrome
 * llegaron recien en la 110 (toSorted/toReversed/toSpliced) o mas
 * adelante (withResolvers). Sin esto, en Chrome 99-109 Livewire tira
 * TypeError y los botones/escaner dejan de responder.
 *
 * Este archivo se carga en <head> (ver AdminPanelProvider) ANTES que
 * livewire.js y los bundles de Filament, y esta escrito en ES5 a proposito:
 * tiene que poder parsearse en el navegador mas viejo soportado.
 *
 * No requiere build: se sirve tal cual desde public/js/.
 */
(function () {
    'use strict';

    if (!Array.prototype.toSorted) {
        Array.prototype.toSorted = function (compareFn) {
            var clone = Array.prototype.slice.call(this);

            if (typeof compareFn === 'function') {
                clone.sort(compareFn);
            } else {
                clone.sort();
            }

            return clone;
        };
    }

    if (!Array.prototype.toReversed) {
        Array.prototype.toReversed = function () {
            return Array.prototype.slice.call(this).reverse();
        };
    }

    if (!Array.prototype.toSpliced) {
        Array.prototype.toSpliced = function (start, deleteCount) {
            var clone = Array.prototype.slice.call(this);
            var args = Array.prototype.slice.call(arguments);

            Array.prototype.splice.apply(clone, args);

            return clone;
        };
    }

    if (typeof Promise !== 'undefined' && !Promise.withResolvers) {
        Promise.withResolvers = function () {
            var resolve = null;
            var reject = null;

            var promise = new Promise(function (res, rej) {
                resolve = res;
                reject = rej;
            });

            return { promise: promise, resolve: resolve, reject: reject };
        };
    }
})();

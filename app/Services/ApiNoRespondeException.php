<?php

namespace App\Services;

use Exception;

/**
 * La API del ERP no contestó (timeout, red caída, servidor ocupado).
 *
 * Se distingue de los demás errores para que las consultas de listados
 * no vuelvan a golpear la API durante unos segundos.
 */
class ApiNoRespondeException extends Exception
{
}

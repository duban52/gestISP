<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Falló la generación de una copia de seguridad.
 *
 * Existe como excepción propia para que quien la reciba pueda
 * distinguirla de cualquier otro error y mostrarle a la persona un
 * mensaje que se entienda, en vez de un volcado de error de MySQL.
 */
class BackupException extends RuntimeException
{
}

<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Genera el volcado comprimido de la base de datos.
 *
 * Tres decisiones que conviene no deshacer:
 *
 *  1. La contraseña NUNCA va en la línea de comandos. Se escribe en un
 *     archivo de opciones temporal con permisos 0600 y se pasa con
 *     --defaults-extra-file. Con `-pclave` cualquier usuario del
 *     servidor vería la contraseña de la base de datos entera con un
 *     simple `ps aux` mientras dura el volcado.
 *
 *  2. La salida se comprime SOBRE LA MARCHA. mysqldump escupe SQL por
 *     su salida estándar y aquí se va escribiendo en el .gz a medida
 *     que llega. Nunca hay un archivo .sql completo en el disco ni el
 *     volcado entero en memoria: un volcado de 4 GB pasa por un
 *     servidor con 512 MB de PHP sin despeinarlo.
 *
 *  3. El resultado se VERIFICA. mysqldump termina su salida con la
 *     línea "-- Dump completed on ...". Si el disco se llena a mitad,
 *     el archivo .gz existe, pesa lo suyo y parece correcto, pero le
 *     falta el final; la copia que no se comprueba es la que falla el
 *     día que hace falta. Como la salida ya pasa por aquí, se guardan
 *     los últimos bytes escritos y se comprueba la marca al terminar,
 *     sin coste añadido.
 */
class DatabaseBackup
{
    /** Bytes finales que se conservan para verificar el cierre. */
    private const COLA_BYTES = 512;

    public function __construct(private readonly BackupRepository $repositorio)
    {
    }

    /**
     * Genera una copia y devuelve el archivo resultante.
     *
     * @param  string  $origen  BackupFile::ORIGEN_MANUAL | ORIGEN_AUTOMATICO
     *
     * @throws BackupException
     */
    public function generar(string $origen = BackupFile::ORIGEN_MANUAL): BackupFile
    {
        $inicio = microtime(true);
        $conexion = $this->configuracionDeConexion();

        $destino = rtrim($this->repositorio->directorio(), '/\\')
            . DIRECTORY_SEPARATOR
            . BackupFile::nombreNuevo($origen, CarbonImmutable::now());

        $credenciales = $this->escribirArchivoDeCredenciales($conexion);

        try {
            $this->volcarA($destino, $credenciales, $conexion);
        } finally {
            // Pase lo que pase, la contraseña no se queda en /tmp
            @unlink($credenciales);
        }

        $copia = BackupFile::desdeRuta($destino);

        if ($copia === null) {
            throw new BackupException('La copia se generó pero no se pudo leer del disco: ' . basename($destino));
        }

        Log::info('Copia de seguridad generada', [
            'archivo' => $copia->nombre,
            'tamano' => $copia->tamanoLegible(),
            'segundos' => round(microtime(true) - $inicio, 1),
            'origen' => $origen,
        ]);

        return $copia;
    }

    /**
     * Ejecuta mysqldump escribiendo directamente en el .gz.
     *
     * @param  array<string, mixed>  $conexion
     *
     * @throws BackupException
     */
    private function volcarA(string $destino, string $credenciales, array $conexion): void
    {
        $gz = @gzopen($destino, 'wb' . max(1, min(9, (int) config('backup.compression_level', 6))));

        if ($gz === false) {
            throw new BackupException("No se pudo crear el archivo de la copia en {$destino}. Revise los permisos de la carpeta.");
        }

        // Últimos bytes escritos, para comprobar al final que el
        // volcado se cerró correctamente
        $cola = '';
        $escritos = 0;

        try {
            foreach ($this->comandos($credenciales, $conexion) as $comando) {
                $this->ejecutar($comando, $gz, $cola, $escritos);
            }
        } catch (\Throwable $e) {
            gzclose($gz);
            // Un archivo a medias es peor que ninguno: alguien lo
            // encontraría en el listado y confiaría en él
            @unlink($destino);

            throw $e;
        }

        gzclose($gz);

        if ($escritos === 0) {
            @unlink($destino);

            throw new BackupException('mysqldump no devolvió ningún dato. Compruebe que el usuario de la base de datos tiene permiso de lectura.');
        }

        if (!str_contains($cola, 'Dump completed')) {
            @unlink($destino);

            throw new BackupException(
                'La copia quedó incompleta (falta la marca de cierre de mysqldump). '
                . 'La causa habitual es que el disco se llenó a mitad del volcado; '
                . 'compruebe el espacio libre del servidor.'
            );
        }
    }

    /**
     * Lanza un mysqldump y va escribiendo su salida en el .gz.
     *
     * @param  list<string>  $comando
     * @param  resource  $gz
     *
     * @throws BackupException
     */
    private function ejecutar(array $comando, $gz, string &$cola, int &$escritos): void
    {
        $proceso = new Process($comando, base_path(), null, null, (float) config('backup.timeout', 900));
        $errorAlEscribir = null;

        $proceso->run(function (string $tipo, string $buffer) use ($gz, &$cola, &$escritos, &$errorAlEscribir) {
            if ($tipo !== Process::OUT || $buffer === '' || $errorAlEscribir !== null) {
                return;
            }

            $bytes = @gzwrite($gz, $buffer);

            if ($bytes === false || $bytes < strlen($buffer)) {
                // Disco lleno o cuota agotada. No se puede lanzar la
                // excepción desde aquí (estamos dentro del callback
                // del proceso), así que se anota y se comprueba al
                // salir.
                $errorAlEscribir = 'No se pudo escribir en el archivo de la copia: el disco del servidor está lleno.';

                return;
            }

            $escritos += $bytes;
            $cola = substr($cola . $buffer, -self::COLA_BYTES);
        });

        if ($errorAlEscribir !== null) {
            throw new BackupException($errorAlEscribir);
        }

        if (!$proceso->isSuccessful()) {
            throw new BackupException($this->explicarFallo($proceso));
        }
    }

    /**
     * Convierte el error de mysqldump en algo accionable.
     */
    private function explicarFallo(Process $proceso): string
    {
        $salida = trim($proceso->getErrorOutput()) ?: trim($proceso->getOutput());

        if ($proceso->getExitCode() === 127 || str_contains($salida, 'not found')) {
            return 'No se encontró el programa mysqldump en el servidor. '
                . 'Instálelo (en Ubuntu: apt install mysql-client) o indique su ruta en BACKUP_MYSQLDUMP.';
        }

        if (str_contains($salida, 'Access denied')) {
            return 'La base de datos rechazó las credenciales del archivo .env. '
                . 'Compruebe DB_USERNAME y DB_PASSWORD.';
        }

        if (str_contains($salida, 'PROCESS privilege')) {
            return 'El usuario de la base de datos no tiene el privilegio PROCESS. '
                . 'Mantenga la opción --no-tablespaces en config/backup.php.';
        }

        return 'mysqldump terminó con error: ' . mb_substr($salida, 0, 500);
    }

    /**
     * Los comandos que hay que ejecutar, en orden.
     *
     * Normalmente uno solo. Si config('backup.exclude_data_tables')
     * tiene tablas, son dos: el primero vuelca todo menos esas tablas
     * y el segundo copia SOLO SU ESTRUCTURA, para que existan al
     * restaurar aunque lleguen vacías.
     *
     * @param  array<string, mixed>  $conexion
     * @return list<list<string>>
     */
    private function comandos(string $credenciales, array $conexion): array
    {
        $binario = (string) config('backup.mysqldump', 'mysqldump');
        $base = (string) $conexion['database'];
        $opciones = array_values((array) config('backup.dump_options', []));
        $excluidas = array_values(array_filter((array) config('backup.exclude_data_tables', [])));

        $principal = array_merge(
            [$binario, "--defaults-extra-file={$credenciales}"],
            $opciones,
            array_map(fn (string $tabla) => "--ignore-table={$base}.{$tabla}", $excluidas),
            [$base],
        );

        if ($excluidas === []) {
            return [$principal];
        }

        // En un volcado de tablas sueltas no caben las opciones que
        // solo tienen sentido sobre la base completa: mysqldump avisa
        // de que las ignora y ensucia la salida de error.
        $porTabla = array_values(array_diff($opciones, ['--routines', '--events']));

        $estructura = array_merge(
            [$binario, "--defaults-extra-file={$credenciales}", '--no-data'],
            $porTabla,
            [$base],
            $excluidas,
        );

        return [$principal, $estructura];
    }

    /**
     * Escribe el archivo de opciones temporal con las credenciales.
     *
     * @param  array<string, mixed>  $conexion
     *
     * @throws BackupException
     */
    private function escribirArchivoDeCredenciales(array $conexion): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'gestisp-backup-');

        if ($ruta === false) {
            throw new BackupException('No se pudo crear el archivo temporal de credenciales.');
        }

        // Antes de escribir nada: solo el propietario puede leerlo
        @chmod($ruta, 0600);

        $lineas = ['[client]'];

        if (!empty($conexion['unix_socket'])) {
            $lineas[] = 'socket=' . $this->entrecomillar((string) $conexion['unix_socket']);
        } else {
            $lineas[] = 'host=' . $this->entrecomillar((string) ($conexion['host'] ?? '127.0.0.1'));
            $lineas[] = 'port=' . (int) ($conexion['port'] ?? 3306);
        }

        $lineas[] = 'user=' . $this->entrecomillar((string) ($conexion['username'] ?? ''));
        $lineas[] = 'password=' . $this->entrecomillar((string) ($conexion['password'] ?? ''));

        file_put_contents($ruta, implode(PHP_EOL, $lineas) . PHP_EOL);

        return $ruta;
    }

    /**
     * Entrecomilla un valor para un archivo de opciones de MySQL.
     *
     * Sin esto, una contraseña con almohadilla o con espacios al final
     * llegaría recortada: MySQL trata la almohadilla como comienzo de
     * comentario y descarta los espacios sobrantes.
     */
    private function entrecomillar(string $valor): string
    {
        return '"' . str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $valor
        ) . '"';
    }

    /**
     * Configuración de la conexión por defecto, validada.
     *
     * @return array<string, mixed>
     *
     * @throws BackupException
     */
    private function configuracionDeConexion(): array
    {
        $nombre = config('database.default');
        $conexion = config("database.connections.{$nombre}");

        if (!is_array($conexion) || ($conexion['driver'] ?? null) !== 'mysql') {
            throw new BackupException(
                'Las copias de seguridad están implementadas para MySQL/MariaDB '
                . "y la conexión activa es «{$nombre}»."
            );
        }

        if (empty($conexion['database'])) {
            throw new BackupException('No hay ninguna base de datos configurada en DB_DATABASE.');
        }

        return $conexion;
    }
}

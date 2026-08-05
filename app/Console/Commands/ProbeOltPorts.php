<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\Snmp\SnmpClientFactory;
use Illuminate\Console\Command;

/**
 * Comprueba contra el equipo real los OIDs del descubrimiento.
 *
 * PARA QUÉ SIRVE
 * --------------
 * El descubrimiento se apoya en la IF-MIB estándar, que responde en
 * cualquier equipo. Pero dos cosas son propietarias de Huawei y NO
 * están verificadas contra el equipo real: la potencia óptica del
 * puerto PON y el nombre de la tarjeta. Este comando dice si SU OLT
 * las responde y con qué valores, para poder ajustar config/olt_snmp.php
 * sin tocar el código.
 *
 * También lista las interfaces tal como las nombra el equipo, que es
 * lo que hace falta si los patrones de reconocimiento no encajan: si
 * su OLT llama a los puertos de otra forma, aquí se ve y se corrige el
 * patrón en la configuración.
 *
 *   php artisan olt:probe-ports 3
 *   php artisan olt:probe-ports 3 --interfaces
 */
class ProbeOltPorts extends Command
{
    protected $signature = 'olt:probe-ports
                            {olt : ID de la OLT}
                            {--interfaces : Lista todas las interfaces tal como las nombra el equipo}';

    protected $description = 'Comprueba contra la OLT los OIDs que usa el descubrimiento de puertos';

    public function __construct(private readonly SnmpClientFactory $clientes)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $olt = Olt::find($this->argument('olt'));

        if (!$olt) {
            $this->error('No existe una OLT con ese ID.');

            return self::FAILURE;
        }

        $cliente = $this->clientes->forOlt($olt);

        if (!$cliente) {
            $this->error('La OLT no tiene community SNMP de lectura, o falta la extensión SNMP de PHP.');

            return self::FAILURE;
        }

        if (!$cliente->isReachable()) {
            $this->error("La OLT {$olt->name} no responde por SNMP en {$olt->ip_address}.");
            $cliente->close();

            return self::FAILURE;
        }

        $marca = strtolower($olt->brand ?: 'huawei');
        $config = config("olt_snmp.brands.{$marca}", []);

        $this->info("OLT {$olt->name} ({$olt->ip_address}) — marca «{$marca}»");
        $this->newLine();

        // ---- Interfaces y patrones ----
        $descripciones = $cliente->walk($config['if_descr'] ?? '.1.3.6.1.2.1.2.2.1.2');
        $this->line('Interfaces publicadas: <info>' . count($descripciones) . '</info>');

        $patronPon = $config['pon_discovery_pattern'] ?? null;
        $patronUplink = $config['uplink_discovery_pattern'] ?? null;

        $pon = [];
        $uplink = [];
        $otras = [];

        foreach ($descripciones as $indice => $descripcion) {
            $descripcion = trim($descripcion);
            $ifIndex = (int) ltrim($indice, '.');

            if ($patronPon && preg_match($patronPon, $descripcion)) {
                $pon[$ifIndex] = $descripcion;
            } elseif ($patronUplink && preg_match($patronUplink, $descripcion)) {
                $uplink[$ifIndex] = $descripcion;
            } else {
                $otras[$ifIndex] = $descripcion;
            }
        }

        $this->line('  Reconocidas como puerto PON: <info>' . count($pon) . '</info>');
        $this->line('  Reconocidas como uplink:     <info>' . count($uplink) . '</info>');
        $this->line('  Sin clasificar:              <comment>' . count($otras) . '</comment>');

        if (count($pon) === 0) {
            $this->newLine();
            $this->warn('Ninguna interfaz encajó con el patrón de puerto PON:');
            $this->line('  ' . $patronPon);
            $this->line('Ajústelo en config/olt_snmp.php («pon_discovery_pattern»).');
            $this->line('Use --interfaces para ver cómo nombra el equipo sus puertos.');
        }

        if ($this->option('interfaces')) {
            $this->newLine();
            $this->line('<comment>Todas las interfaces:</comment>');

            $filas = [];
            foreach ($descripciones as $indice => $descripcion) {
                $ifIndex = (int) ltrim($indice, '.');
                $filas[] = [
                    $ifIndex,
                    trim($descripcion),
                    isset($pon[$ifIndex]) ? 'PON' : (isset($uplink[$ifIndex]) ? 'uplink' : '—'),
                ];
            }

            $this->table(['ifIndex', 'ifDescr', 'Reconocida como'], $filas);
        }

        // ---- OIDs propietarios, los que no están verificados ----
        $this->newLine();
        $this->line('<comment>OIDs propietarios (los que no están verificados):</comment>');

        $this->probarTabla(
            $cliente,
            'Potencia Tx del puerto PON',
            $config['pon_optical']['tx_power']['oid'] ?? null,
            $config['pon_optical']['tx_power']['scale'] ?? 1,
            array_keys($pon),
        );

        $this->probarTabla($cliente, 'Nombre de la tarjeta', $config['boards']['name'] ?? null);
        $this->probarTabla($cliente, 'Estado de la tarjeta', $config['boards']['status'] ?? null);

        $cliente->close();

        $this->newLine();
        $this->line('Si alguno responde valores absurdos, corrija su «oid» o su «scale»');
        $this->line('en config/olt_snmp.php. Ponerlo en null deja de consultarlo y no');
        $this->line('rompe nada: el descubrimiento no depende de estos OIDs.');

        return self::SUCCESS;
    }

    /**
     * Recorre un OID y muestra una muestra de lo que devuelve.
     *
     * @param  array<int, int>  $soloEstos  ifIndex que interesan (vacío = todos)
     */
    private function probarTabla($cliente, string $titulo, ?string $oid, float $escala = 1, array $soloEstos = []): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$titulo}</>");

        if (!$oid) {
            $this->line('    <comment>Desactivado en la configuración (null).</comment>');

            return;
        }

        $this->line("    OID: {$oid}");

        $valores = $cliente->walk($oid);

        if (empty($valores)) {
            $this->line('    <comment>No responde. Este dato no se mostrará (y no pasa nada).</comment>');

            return;
        }

        $this->line('    Responde <info>' . count($valores) . '</info> valor(es). Muestra:');

        $mostrados = 0;

        foreach ($valores as $indice => $crudo) {
            $ifIndex = (int) ltrim($indice, '.');

            if ($soloEstos !== [] && !in_array($ifIndex, $soloEstos, true)) {
                continue;
            }

            $convertido = is_numeric($crudo) && $escala != 1
                ? ' → ' . round(((float) $crudo) * $escala, 2)
                : '';

            $this->line("      [{$ifIndex}] {$crudo}{$convertido}");

            if (++$mostrados >= 5) {
                break;
            }
        }
    }
}

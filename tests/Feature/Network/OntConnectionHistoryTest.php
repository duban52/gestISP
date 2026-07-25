<?php

namespace Tests\Feature\Network;

use App\Http\Controllers\OntController;
use App\Services\OltSnmpService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Historial de conexión de la ONT (última conexión, última caída,
 * causa y tiempo en línea).
 *
 * Esos cuatro datos aparecían siempre vacíos porque los OID nunca se
 * habían declarado. Aquí se comprueba la decodificación de las fechas
 * del equipo, el mapeo de las causas y el cálculo del tiempo en línea.
 *
 * Los valores de ejemplo son los que devolvió la OLT real: la consola
 * mostraba "Last up time: 2026-07-23 19:40:06-05:00" para los mismos
 * bytes que se usan aquí.
 */
class OntConnectionHistoryTest extends TestCase
{
    private function decodificar(string $bytes): ?string
    {
        $metodo = new ReflectionMethod(OltSnmpService::class, 'decodeDateAndTime');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(OltSnmpService::class), $bytes);
    }

    private function normalizar(?string $raw, array $def): array
    {
        $metodo = new ReflectionMethod(OltSnmpService::class, 'normalize');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(OltSnmpService::class), $raw, $def);
    }

    private function tiempoEnLinea(?string $desde, ?string $estado): ?string
    {
        $metodo = new ReflectionMethod(OntController::class, 'calcularTiempoEnLinea');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(OntController::class), $desde, $estado);
    }

    // ==================== Fechas del equipo ====================

    public function test_decodifica_la_fecha_que_entrega_la_olt(): void
    {
        // Bytes reales de la OLT: 2026-07-23 19:37:00 (-05:00)
        $bytes = hex2bin('07ea0717132500002d0500');

        $this->assertSame('23/07/2026 19:37', $this->decodificar($bytes));
    }

    public function test_una_ont_sin_registro_no_muestra_fecha(): void
    {
        // La OLT devuelve todo ceros cuando nunca hubo conexión
        $this->assertNull($this->decodificar(hex2bin('0000000000000000000000')));
    }

    public function test_acepta_la_fecha_en_hexadecimal_legible(): void
    {
        // Algunos agentes la entregan como texto "07 EA 07 17 ..."
        $this->assertSame(
            '23/07/2026 19:37',
            $this->decodificar('07 EA 07 17 13 25 00 00 2D 05 00')
        );
    }

    public function test_descarta_una_fecha_imposible(): void
    {
        // Mes 99: el equipo devolvió basura
        $this->assertNull($this->decodificar(hex2bin('07ea6317132500002d0500')));
    }

    public function test_la_metrica_de_fecha_se_normaliza_como_fecha(): void
    {
        $resultado = $this->normalizar(
            hex2bin('07ea0717132500002d0500'),
            ['type' => 'datetime', 'label' => 'Última conexión']
        );

        // Sin el tipo 'datetime', el normalizador borraba los bytes
        // no numéricos y la fecha se perdía.
        $this->assertSame('23/07/2026 19:37', $resultado['value']);
    }

    // ==================== Causa de la caída ====================

    public function test_traduce_las_causas_verificadas_en_el_equipo(): void
    {
        $def = config('olt_snmp.brands.huawei.ont_metrics.last_down_cause');

        $esperado = [
            '2' => 'LOSi/LOBi',
            '3' => 'LOFi',
            '13' => 'dying-gasp',
            '35' => 'Operator check failure',
        ];

        foreach ($esperado as $codigo => $texto) {
            $this->assertSame($texto, $this->normalizar($codigo, $def)['value']);
        }
    }

    public function test_sin_causa_registrada_no_muestra_nada(): void
    {
        $def = config('olt_snmp.brands.huawei.ont_metrics.last_down_cause');

        // -1 es el valor de "sin registro"
        $this->assertNull($this->normalizar('-1', $def)['value']);
    }

    public function test_un_codigo_desconocido_se_muestra_tal_cual(): void
    {
        $def = config('olt_snmp.brands.huawei.ont_metrics.last_down_cause');

        // No se le inventa un significado
        $this->assertSame('7', $this->normalizar('7', $def)['value']);
    }

    // ==================== Tiempo en línea ====================

    public function test_calcula_el_tiempo_que_lleva_conectada(): void
    {
        $desde = now()->subDays(1)->subHours(22)->format('d/m/Y H:i');

        $this->assertSame('1 día 22 h', $this->tiempoEnLinea($desde, 'online'));
    }

    public function test_una_conexion_reciente_se_expresa_en_minutos(): void
    {
        $desde = now()->subMinutes(7)->format('d/m/Y H:i');

        $this->assertSame('7 min', $this->tiempoEnLinea($desde, 'online'));
    }

    public function test_si_la_ont_esta_caida_no_hay_tiempo_en_linea(): void
    {
        $desde = now()->subDays(3)->format('d/m/Y H:i');

        $this->assertNull($this->tiempoEnLinea($desde, 'offline'));
    }

    public function test_sin_fecha_de_conexion_no_calcula_nada(): void
    {
        $this->assertNull($this->tiempoEnLinea(null, 'online'));
    }

    // ============ No encarecer el sondeo masivo ============

    public function test_el_historial_queda_fuera_del_muestreo_masivo(): void
    {
        $metricas = config('olt_snmp.brands.huawei.ont_metrics');

        // Estas tres son de consulta puntual: incluirlas en el sondeo
        // de miles de ONTs cada 5 minutos lo encarecería sin aportar
        // nada al historial de gráficas.
        foreach (['last_up_time', 'last_down_time', 'last_down_cause'] as $clave) {
            $this->assertFalse(
                $metricas[$clave]['bulk'] ?? true,
                "La métrica {$clave} debería quedar fuera del muestreo masivo."
            );
        }

        // Las que sí se grafican siguen incluidas
        foreach (['rx_power', 'tx_power', 'run_status'] as $clave) {
            $this->assertTrue($metricas[$clave]['bulk'] ?? true);
        }
    }
}

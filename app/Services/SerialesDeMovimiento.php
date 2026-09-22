<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Material;

/**
 * Los números de serie de un movimiento de almacén: cuáles valen y por
 * qué no los demás.
 *
 * Una sola regla para las dos puertas: la carga de un archivo —que
 * enseña el resultado ANTES de agregar el material— y el guardado del
 * movimiento, que la vuelve a aplicar porque lo que manda el navegador
 * no es de fiar.
 *
 *  - Entrada: el serial NO puede estar ya en el inventario. Un equipo
 *    físico está en un solo sitio; si ya figura, o se está ingresando
 *    dos veces o el archivo trae el serial de otro.
 *  - Salida y transferencia: el serial TIENE que estar en el almacén de
 *    origen, y de ese material. Antes solo se contaba cuántos venían:
 *    un serial inexistente dejaba un movimiento registrado sin mover
 *    nada del inventario.
 */
class SerialesDeMovimiento
{
    /** Tope por material y movimiento: un pedido grande, no un inventario entero. */
    public const MAXIMO = 5000;

    /** Encabezados de columna que se reconocen en un CSV o un Excel. */
    public const ENCABEZADOS = [
        'serial', 'seriales', 'sn', 'serie', 'numerodeserie', 'numeroserie', 'nserie',
        'nodeserie', 'nroserie', 'serialnumber', 'serialno',
    ];

    /** Texto pegado: uno por línea, o separados por comas, punto y coma o tabulaciones. */
    public static function desdeTexto(?string $texto): array
    {
        return preg_split('/[\r\n,;\t]+/', (string) $texto) ?: [];
    }

    /**
     * @param  array<int, mixed>  $seriales  tal como llegan
     * @return array{validos: array<int, string>, problemas: array<int, array{serial: string, motivo: string}>}
     */
    public function revisar(array $seriales, string $tipo, Material $material, ?int $origenId): array
    {
        $problemas = [];
        $vistos = [];

        foreach ($seriales as $crudo) {
            $serial = trim((string) $crudo);

            if ($serial === '') {
                continue;
            }

            // Excel guarda como NÚMERO una columna de puros dígitos y, pasados
            // los 15, la redondea: «4857544312345678» llega «4.8575443123457E+15».
            // Ese serial ya no es el del equipo y no hay forma de recuperarlo.
            if (preg_match('/^\d+(\.\d+)?E[+-]?\d+$/i', $serial)) {
                $problemas[] = ['serial' => $serial, 'motivo' => 'Excel lo convirtió en número y perdió dígitos: dé formato de texto a la columna y vuelva a exportar.'];
                continue;
            }

            if (mb_strlen($serial) > 100) {
                $problemas[] = ['serial' => mb_substr($serial, 0, 40) . '…', 'motivo' => 'Demasiado largo para ser un número de serie.'];
                continue;
            }

            $clave = mb_strtolower($serial);

            if (isset($vistos[$clave])) {
                $problemas[] = ['serial' => $serial, 'motivo' => 'Repetido en la lista.'];
                continue;
            }

            $vistos[$clave] = $serial;
        }

        if (count($vistos) > self::MAXIMO) {
            return ['validos' => [], 'problemas' => [[
                'serial' => '—',
                'motivo' => sprintf('Son %d seriales y el máximo por material es %d. Divida el movimiento.', count($vistos), self::MAXIMO),
            ]]];
        }

        $validos = [];

        foreach (array_chunk($vistos, 1000, true) as $tanda) {
            $encontrados = $this->enInventario(array_values($tanda));

            foreach ($tanda as $clave => $serial) {
                $fila = $encontrados[$clave] ?? null;

                if ($tipo === 'Entrada') {
                    if ($fila) {
                        $problemas[] = ['serial' => $serial, 'motivo' => sprintf(
                            'Ya está en el inventario: %s en «%s».',
                            $fila->material?->name ?? 'otro material',
                            $fila->warehouse?->description ?? 'otro almacén',
                        )];
                        continue;
                    }

                    $validos[] = $serial;
                    continue;
                }

                // Salida y transferencia: tiene que estar AQUÍ y ser de este material.
                if ($fila && (int) $fila->warehouse_id === (int) $origenId && (int) $fila->material_id === (int) $material->id) {
                    // El que está guardado, no el escrito: mayúsculas y
                    // minúsculas pueden no coincidir con el del archivo.
                    $validos[] = $fila->serial_number;
                    continue;
                }

                $problemas[] = ['serial' => $serial, 'motivo' => match (true) {
                    !$fila => 'No existe en el inventario.',
                    (int) $fila->material_id !== (int) $material->id => sprintf('Es de otro material: %s.', $fila->material?->name ?? '—'),
                    default => sprintf('No está en el almacén de origen: está en «%s».', $fila->warehouse?->description ?? 'otro almacén'),
                }];
            }
        }

        return ['validos' => $validos, 'problemas' => $problemas];
    }

    /**
     * Dónde está cada serial, por serial en minúsculas.
     *
     * Solo en almacenes de la empresa: el alcance de empresa de Warehouse
     * se aplica dentro del whereHas, y el mensaje no puede contar dónde
     * guarda sus equipos otra empresa.
     *
     * @param  array<int, string>  $seriales
     * @return array<string, Inventory>
     */
    private function enInventario(array $seriales): array
    {
        return Inventory::whereIn('serial_number', $seriales)
            ->whereHas('warehouse')
            ->with(['warehouse', 'material'])
            ->get()
            ->keyBy(fn (Inventory $i) => mb_strtolower((string) $i->serial_number))
            ->all();
    }
}

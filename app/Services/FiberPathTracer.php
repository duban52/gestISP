<?php

namespace App\Services;

use App\Models\CableStrand;
use App\Models\FiberCable;
use App\Models\NapBox;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\Splice;
use App\Models\SpliceClosure;
use App\Models\Splitter;

/**
 * Recorre la planta de fibra para responder las dos preguntas que
 * justifican haberla documentado.
 *
 *   «Si corto esta mufla, ¿a quién dejo sin servicio?»
 *   «¿Por dónde va este cliente?»
 *
 * Hoy esas dos se contestan llamando al que lleva más años en la
 * empresa. Un inventario que no las responda es una lista que envejece.
 *
 * CÓMO FUNCIONA EL CÁLCULO DE IMPACTO
 * -----------------------------------
 * No se intenta adivinar la dirección de la señal, que es donde se
 * equivocan estas herramientas. Se hace por simulación:
 *
 *   1. Se arma el grafo de hilos conectados entre sí (fusiones y
 *      splitters).
 *   2. Se ve qué cajas NAP quedan alcanzables desde las OLTs.
 *   3. Se quita del grafo el elemento que se va a cortar y se repite.
 *   4. Lo que dejó de ser alcanzable es lo que se cae.
 *
 * La ventaja de hacerlo así es que un anillo o una ruta redundante
 * salen bien SIN escribir una sola línea sobre redundancia: si la caja
 * sigue alcanzable por otro camino, no aparece en el impacto.
 *
 * POR QUÉ SE CARGA TODO EN MEMORIA
 * --------------------------------
 * Porque cabe de sobra. Una red grande son unas decenas de miles de
 * hilos y unos miles de fusiones; recorrer eso en PHP tarda
 * milisegundos, mientras que hacerlo con consultas recursivas en SQL
 * sería lento y mucho más difícil de leer.
 */
class FiberPathTracer
{
    /** @var array<int, array<int, int>> hilo => hilos conectados */
    private array $grafo = [];

    /** @var array<int, int> hilo => id de la caja NAP que alimenta */
    private array $cajasPorHilo = [];

    /** @var array<int, int> hilo => id del cable al que pertenece */
    private array $cablePorHilo = [];

    /** @var array<int, int> hilo => id de la mufla donde está fusionado (una de ellas) */
    private array $muflasPorFusion = [];

    private bool $cargado = false;

    private ?int $redId = null;

    // ==================== Impacto ====================

    /**
     * A qué clientes afecta cortar una mufla.
     *
     * Cortar una mufla es lo que pasa cuando se abre para trabajar en
     * ella: mientras esté abierta, todo lo que pase por sus fusiones
     * está caído.
     *
     * @return array<string, mixed>
     */
    public function impactoDeMufla(SpliceClosure $mufla): array
    {
        $this->cargar($mufla->optical_network_id);

        $fusiones = Splice::where('splice_closure_id', $mufla->id)->get(['strand_a_id', 'strand_b_id']);
        $splitters = Splitter::where('splice_closure_id', $mufla->id)->pluck('id');

        $cortadas = [];

        foreach ($fusiones as $f) {
            $cortadas[] = [(int) $f->strand_a_id, (int) $f->strand_b_id];
        }

        foreach ($this->aristasDeSplitters($splitters->all()) as $arista) {
            $cortadas[] = $arista;
        }

        return $this->calcularImpacto($cortadas, [], sprintf(
            'Abrir la mufla %s',
            $mufla->code,
        ));
    }

    /**
     * A qué clientes afecta cortar un cable.
     *
     * @return array<string, mixed>
     */
    public function impactoDeCable(FiberCable $cable): array
    {
        $this->cargar($cable->optical_network_id);

        $hilos = CableStrand::where('fiber_cable_id', $cable->id)->pluck('id')->all();

        return $this->calcularImpacto([], $hilos, sprintf(
            'Cortar el cable %s',
            $cable->code,
        ));
    }

    /**
     * Núcleo del cálculo: se quitan aristas o nodos y se compara.
     *
     * @param  array<int, array{0: int, 1: int}>  $aristasCortadas
     * @param  array<int, int>  $nodosCortados
     * @return array<string, mixed>
     */
    private function calcularImpacto(array $aristasCortadas, array $nodosCortados, string $accion): array
    {
        $antes = $this->cajasAlcanzables([], []);
        $despues = $this->cajasAlcanzables($aristasCortadas, $nodosCortados);

        $perdidas = array_values(array_diff($antes, $despues));

        $cajas = NapBox::whereIn('id', $perdidas)
            ->with(['ports.contract.client', 'zone'])
            ->orderBy('code')
            ->get();

        $contratos = [];

        foreach ($cajas as $caja) {
            foreach ($caja->ports as $puerto) {
                if ($puerto->contract) {
                    $contratos[] = [
                        'id' => $puerto->contract->id,
                        'numero' => $puerto->contract->numero_visible,
                        'cliente' => trim(
                            ($puerto->contract->client?->name ?? '') . ' ' .
                            ($puerto->contract->client?->last_name ?? '')
                        ) ?: null,
                        'telefono' => $puerto->contract->client?->number_phone,
                        'direccion' => $puerto->contract->address,
                        'caja' => $caja->code,
                        'puerto' => $puerto->number,
                    ];
                }
            }
        }

        return [
            'accion' => $accion,
            'cajas' => $cajas->map(fn (NapBox $c) => [
                'id' => $c->id,
                'codigo' => $c->code,
                'nombre' => $c->name,
                'direccion' => $c->address,
                'zona' => $c->zone?->name,
                'clientes' => $c->puertosOcupados(),
                'url' => route('naps.show', $c->id),
            ])->values()->all(),
            'contratos' => $contratos,
            'total_cajas' => $cajas->count(),
            'total_clientes' => count($contratos),
            // Cajas alcanzables en total, para dar contexto: «12 de 340»
            // dice mucho más que «12».
            'cajas_en_la_red' => count($antes),
        ];
    }

    /**
     * Qué cajas NAP quedan alcanzables desde las OLTs.
     *
     * @param  array<int, array{0: int, 1: int}>  $aristasCortadas
     * @param  array<int, int>  $nodosCortados
     * @return array<int, int>  ids de caja
     */
    private function cajasAlcanzables(array $aristasCortadas, array $nodosCortados): array
    {
        $prohibidas = [];

        foreach ($aristasCortadas as [$a, $b]) {
            $prohibidas[$a . '-' . $b] = true;
            $prohibidas[$b . '-' . $a] = true;
        }

        $sinNodo = array_flip($nodosCortados);

        $porVisitar = [];
        $visitados = [];

        foreach ($this->hilosDeSalida() as $hilo) {
            if (!isset($sinNodo[$hilo])) {
                $porVisitar[] = $hilo;
                $visitados[$hilo] = true;
            }
        }

        $cajas = [];

        while ($porVisitar !== []) {
            $actual = array_pop($porVisitar);

            if (isset($this->cajasPorHilo[$actual])) {
                $cajas[$this->cajasPorHilo[$actual]] = true;
            }

            foreach ($this->grafo[$actual] ?? [] as $vecino) {
                if (isset($visitados[$vecino]) || isset($sinNodo[$vecino])) {
                    continue;
                }

                if (isset($prohibidas[$actual . '-' . $vecino])) {
                    continue;
                }

                $visitados[$vecino] = true;
                $porVisitar[] = $vecino;
            }
        }

        return array_keys($cajas);
    }

    /**
     * Hilos por donde entra la señal: los de los cables que salen de
     * una OLT.
     *
     * @return array<int, int>
     */
    private function hilosDeSalida(): array
    {
        $cables = FiberCable::where('optical_network_id', $this->redId)
            ->where('from_type', Olt::class)
            ->pluck('id');

        if ($cables->isEmpty()) {
            return [];
        }

        return CableStrand::whereIn('fiber_cable_id', $cables)->pluck('id')->all();
    }

    // ==================== Ruta de un cliente ====================

    /**
     * Por dónde va una caja NAP hasta la OLT.
     *
     * Devuelve los tramos DESDE LA CABECERA HACIA EL CLIENTE, que es la
     * dirección en la que viaja la señal y en la que se lee el resto
     * del módulo (OLT → puerto PON → caja → contrato). Darla al revés
     * obligaría a leer la ficha de abajo arriba.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rutaDeCaja(NapBox $caja): array
    {
        $caja->loadMissing('feedStrand.cable', 'network');

        if (!$caja->feed_strand_id) {
            return [];
        }

        $this->cargar($caja->optical_network_id);

        // Recorrido en anchura hasta llegar a un hilo de salida,
        // guardando de dónde vino cada uno para poder reconstruir el
        // camino. En anchura y no en profundidad porque interesa la
        // ruta MÁS CORTA: es la que existe físicamente.
        $origen = (int) $caja->feed_strand_id;
        $destinos = array_flip($this->hilosDeSalida());

        $vengoDe = [$origen => null];
        $cola = [$origen];
        $final = isset($destinos[$origen]) ? $origen : null;

        while ($cola !== [] && $final === null) {
            $actual = array_shift($cola);

            foreach ($this->grafo[$actual] ?? [] as $vecino) {
                if (array_key_exists($vecino, $vengoDe)) {
                    continue;
                }

                $vengoDe[$vecino] = $actual;

                if (isset($destinos[$vecino])) {
                    $final = $vecino;
                    break;
                }

                $cola[] = $vecino;
            }
        }

        if ($final === null) {
            return [];
        }

        // Se reconstruye desde el hilo de cabecera hacia atrás, que al
        // recorrerlo así ya sale en el orden bueno: de la OLT al
        // cliente. No hace falta darle la vuelta.
        $camino = [];

        for ($nodo = $final; $nodo !== null; $nodo = $vengoDe[$nodo]) {
            $camino[] = $nodo;
        }

        return $this->describirCamino($camino);
    }

    /**
     * Convierte una lista de hilos en algo que se pueda leer.
     *
     * @param  array<int, int>  $hilos
     * @return array<int, array<string, mixed>>
     */
    private function describirCamino(array $hilos): array
    {
        $modelos = CableStrand::whereIn('id', $hilos)
            ->with('cable.from', 'cable.to')
            ->get()
            ->keyBy('id');

        $pasos = [];
        $cableAnterior = null;

        foreach ($hilos as $id) {
            $hilo = $modelos->get($id);

            if (!$hilo) {
                continue;
            }

            // Solo se anota cuando se cambia de cable: la lista
            // interesante son los TRAMOS, no cada hilo.
            if ($cableAnterior !== $hilo->fiber_cable_id) {
                $pasos[] = [
                    'cable' => $hilo->cable->code,
                    'tipo' => $hilo->cable->tipo_legible,
                    'desde' => $hilo->cable->desde_legible,
                    'hasta' => $hilo->cable->hasta_legible,
                    'hilo' => $hilo->posicion_legible,
                    'longitud_m' => $hilo->cable->length_m,
                ];

                $cableAnterior = $hilo->fiber_cable_id;
            }
        }

        return $pasos;
    }

    // ==================== Grafo ====================

    /**
     * Carga en memoria las conexiones de la red.
     *
     * Se hace de una vez y con tres consultas: hacerlo hilo por hilo
     * sería un N+1 de miles de consultas.
     */
    private function cargar(?int $redId): void
    {
        if ($this->cargado && $this->redId === $redId) {
            return;
        }

        $this->redId = $redId;
        $this->grafo = [];
        $this->cajasPorHilo = [];
        $this->cablePorHilo = [];

        $cables = FiberCable::where('optical_network_id', $redId)->pluck('id');

        foreach (CableStrand::whereIn('fiber_cable_id', $cables)->get(['id', 'fiber_cable_id']) as $hilo) {
            $this->cablePorHilo[(int) $hilo->id] = (int) $hilo->fiber_cable_id;
            $this->grafo[(int) $hilo->id] = [];
        }

        // Fusiones
        $fusiones = Splice::whereIn('strand_a_id', array_keys($this->grafo))
            ->get(['strand_a_id', 'strand_b_id', 'splice_closure_id']);

        foreach ($fusiones as $fusion) {
            $this->conectar((int) $fusion->strand_a_id, (int) $fusion->strand_b_id);
        }

        // Splitters: la entrada queda conectada a cada salida
        $splitters = Splitter::whereIn('input_strand_id', array_keys($this->grafo))
            ->with('outputs')
            ->get();

        foreach ($splitters as $splitter) {
            foreach ($splitter->outputs as $salida) {
                if ($salida->strand_id) {
                    $this->conectar((int) $splitter->input_strand_id, (int) $salida->strand_id);
                }
            }
        }

        // Qué hilo alimenta cada caja
        foreach (NapBox::whereNotNull('feed_strand_id')->get(['id', 'feed_strand_id']) as $caja) {
            $this->cajasPorHilo[(int) $caja->feed_strand_id] = (int) $caja->id;
        }

        $this->cargado = true;
    }

    private function conectar(int $a, int $b): void
    {
        $this->grafo[$a][] = $b;
        $this->grafo[$b][] = $a;
    }

    /**
     * Aristas que aporta un conjunto de splitters.
     *
     * @param  array<int, int>  $splitterIds
     * @return array<int, array{0: int, 1: int}>
     */
    private function aristasDeSplitters(array $splitterIds): array
    {
        if ($splitterIds === []) {
            return [];
        }

        $aristas = [];

        foreach (Splitter::whereIn('id', $splitterIds)->with('outputs')->get() as $splitter) {
            if (!$splitter->input_strand_id) {
                continue;
            }

            foreach ($splitter->outputs as $salida) {
                if ($salida->strand_id) {
                    $aristas[] = [(int) $splitter->input_strand_id, (int) $salida->strand_id];
                }
            }
        }

        return $aristas;
    }
}

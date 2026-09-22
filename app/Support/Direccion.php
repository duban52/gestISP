<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Una dirección colombiana armada por partes.
 *
 * POR QUÉ POR PARTES
 * ------------------
 * Escrita a mano, la misma casa llegaba como «CL 20 #19-30», «Calle 20
 * No. 19 - 30» y «cll20 19-30 apto 201». El técnico las entendía; un
 * buscador, un informe por calle o el mapa no. Aquí se eligen el tipo
 * de vía de una lista y los números van en su casilla, y la dirección
 * sale siempre con la misma forma:
 *
 *     Calle 20 # 19-30, Apto 201, Ref: frente al parque
 *
 * Lo que se GUARDA sigue siendo ese texto, en la misma columna de
 * siempre: todo lo que ya lee `address` (facturas, la DIAN, listados,
 * importaciones) sigue funcionando sin enterarse. Las partes no se
 * guardan: se deducen del texto al editar (partes()), porque el texto lo
 * escribió esta misma clase y su forma es conocida.
 *
 * El servidor ARMA la dirección con las partes que recibe; nunca se fía
 * de un texto ya armado por el navegador. Eso lo hace el middleware
 * ComponerDirecciones para todos los formularios a la vez.
 */
final class Direccion
{
    /** Vías con nomenclatura: «Calle 20 # 19-30». */
    public const VIAS = [
        'Calle', 'Carrera', 'Avenida', 'Avenida Calle', 'Avenida Carrera',
        'Diagonal', 'Transversal', 'Circular', 'Circunvalar', 'Autopista',
        'Troncal', 'Variante', 'Carretera', 'Vía', 'Pasaje',
    ];

    /** Sin nomenclatura: se describen. «Vereda La Esperanza». */
    public const DESCRIPTIVAS = [
        'Manzana', 'Kilómetro', 'Vereda', 'Corregimiento', 'Sector', 'Finca', 'Lote',
    ];

    public const CUADRANTES = ['Sur', 'Este', 'Norte', 'Oeste'];

    /** Número de vía o de placa, ya normalizado: 20, 20A, 20 Bis, 20A Bis B. */
    private const NUMERO = '\d{1,3}[A-Z]?(?: Bis)?(?: [A-Z])?';

    private const REFERENCIA = ', Ref: ';

    /**
     * La dirección en su forma única.
     *
     * @param  array<string, ?string>  $partes  tipo, numero, cuadrante, placa, placa2,
     *                                         descripcion, complemento, referencia
     */
    public static function componer(array $partes): string
    {
        $tipo = $partes['tipo'] ?? '';

        if (in_array($tipo, self::VIAS, true)) {
            $texto = $tipo . ' ' . self::normalizarNumero($partes['numero'] ?? '')
                . (filled($partes['cuadrante'] ?? null) ? ' ' . $partes['cuadrante'] : '')
                . ' # ' . self::normalizarNumero($partes['placa'] ?? '')
                . '-' . self::normalizarNumero($partes['placa2'] ?? '');
        } else {
            $texto = $tipo . ' ' . self::limpiar($partes['descripcion'] ?? '');
        }

        if (filled($partes['complemento'] ?? null)) {
            $texto .= ', ' . self::limpiar($partes['complemento']);
        }

        if (filled($partes['referencia'] ?? null)) {
            $texto .= self::REFERENCIA . self::limpiar($partes['referencia']);
        }

        return $texto;
    }

    /**
     * Las partes de una dirección ya guardada, para rellenar el
     * formulario al editar. Null si no se reconoce.
     *
     * Reconoce la forma que escribe componer() y, además, las formas
     * más comunes escritas a mano antes de que existiera («CL 20 #19-30
     * apto 2», «Cra. 5 No. 10-20»): así editar un contrato viejo no
     * obliga a volver a escribir su dirección.
     *
     * @return array<string, ?string>|null
     */
    public static function partes(?string $direccion): ?array
    {
        $direccion = self::limpiar($direccion ?? '');

        if ($direccion === '') {
            return null;
        }

        return self::partesPropias($direccion) ?? self::partesAntiguas($direccion);
    }

    /**
     * Lo que se le pregunta al buscador de mapas: sin «#», sin
     * complemento ni referencia —que el buscador no entiende— y con el
     * municipio, que es lo que evita encontrar la «Calle 20» de otra
     * ciudad.
     */
    public static function paraBuscar(?string $direccion, ?string $municipio = null, ?string $departamento = null): string
    {
        $partes = self::partes($direccion);

        if ($partes && in_array($partes['tipo'], self::VIAS, true)) {
            $base = "{$partes['tipo']} {$partes['numero']}"
                . ($partes['cuadrante'] ? " {$partes['cuadrante']}" : '')
                . " {$partes['placa']}-{$partes['placa2']}";
        } elseif ($partes) {
            $base = "{$partes['tipo']} {$partes['descripcion']}";
        } else {
            $base = (string) $direccion;
        }

        return collect([$base, $municipio, $departamento])
            ->filter(fn ($v) => filled($v) && $v !== 'N/A')
            ->push('Colombia')
            ->implode(', ');
    }

    /**
     * Arma `$campo` a partir de `{$campo}_partes` de la petición.
     *
     * Si las partes llegan vacías no toca nada: el formulario trae en
     * `$campo` la dirección que ya había, y guardarlo sin reescribirla
     * no puede borrarla.
     *
     * @throws ValidationException
     */
    public static function desdeRequest(Request $request, string $campo): void
    {
        $clave = "{$campo}_partes";
        $partes = array_map(
            fn ($v) => is_string($v) ? self::limpiar($v) : $v,
            (array) $request->input($clave, []),
        );
        $request->request->remove($clave);

        if (collect($partes)->filter(fn ($v) => filled($v))->isEmpty()) {
            return;
        }

        foreach (['numero', 'placa', 'placa2'] as $numero) {
            if (filled($partes[$numero] ?? null)) {
                $partes[$numero] = self::normalizarNumero($partes[$numero]);
            }
        }

        // Lo del otro tipo de dirección no cuenta: si alguien cambió de
        // «Vereda» a «Calle», la descripción que escribió ya no aplica.
        $esVia = in_array($partes['tipo'] ?? null, self::VIAS, true);
        $sobran = $esVia ? ['descripcion'] : ['numero', 'cuadrante', 'placa', 'placa2'];
        $partes = array_diff_key($partes, array_flip($sobran));
        $patron = '/^' . self::NUMERO . '$/';

        Validator::make([$clave => $partes], [
            "{$clave}.tipo" => ['required', Rule::in([...self::VIAS, ...self::DESCRIPTIVAS])],
            "{$clave}.numero" => [Rule::requiredIf($esVia), 'nullable', "regex:{$patron}"],
            "{$clave}.cuadrante" => ['nullable', Rule::in(self::CUADRANTES)],
            "{$clave}.placa" => [Rule::requiredIf($esVia), 'nullable', "regex:{$patron}"],
            "{$clave}.placa2" => [Rule::requiredIf($esVia), 'nullable', 'regex:/^\d{1,3}[A-Z]?$/'],
            // Sin comas: la coma es la que separa el complemento, y
            // partes() no podría volver a separarlos al editar.
            "{$clave}.descripcion" => [Rule::requiredIf(!$esVia), 'nullable', 'string', 'max:80', 'not_regex:/,/'],
            "{$clave}.complemento" => ['nullable', 'string', 'max:80', 'not_regex:/, Ref:/i'],
            "{$clave}.referencia" => ['nullable', 'string', 'max:100'],
        ], [
            'regex' => 'El :attribute no tiene la forma de una nomenclatura (ej.: 20, 20A, 20 Bis, 20A Bis B).',
            "{$clave}.placa2.regex" => 'El segundo número de la placa va sin letras extra (ej.: 30 o 30A).',
            "{$clave}.descripcion.not_regex" => 'La descripción va sin comas; lo demás, en el complemento.',
        ], [
            "{$clave}.tipo" => 'tipo de vía',
            "{$clave}.numero" => 'número de la vía',
            "{$clave}.cuadrante" => 'cuadrante',
            "{$clave}.placa" => 'número de placa',
            "{$clave}.placa2" => 'segundo número de la placa',
            "{$clave}.descripcion" => 'nombre o descripción',
            "{$clave}.complemento" => 'complemento',
            "{$clave}.referencia" => 'punto de referencia',
        ])->validate();

        $request->merge([$campo => self::componer($partes)]);
    }

    /** «20 a bis» → «20A Bis». Lo que no reconoce lo deja para que falle la validación. */
    public static function normalizarNumero(?string $numero): string
    {
        $texto = strtoupper(preg_replace('/\s+/', ' ', trim((string) $numero)));

        if (!preg_match('/^(\d{1,3}) ?([A-Z])? ?(BIS)? ?([A-Z])?$/', $texto, $m)) {
            return $texto;
        }

        return $m[1]
            . ($m[2] ?? '')
            . (!empty($m[3]) ? ' Bis' : '')
            . (!empty($m[4]) ? ' ' . $m[4] : '');
    }

    private static function limpiar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texto));
    }

    /** La forma que escribe componer(). */
    private static function partesPropias(string $direccion): ?array
    {
        // Las más largas primero: «Avenida Calle» antes que «Avenida».
        $vias = collect(self::VIAS)->sortByDesc(fn ($v) => mb_strlen($v))
            ->map(fn ($v) => preg_quote($v, '/'))->implode('|');
        $cuadrantes = implode('|', self::CUADRANTES);
        $descriptivas = collect(self::DESCRIPTIVAS)->map(fn ($v) => preg_quote($v, '/'))->implode('|');
        $n = self::NUMERO;

        if (preg_match("/^(?<tipo>{$vias}) (?<numero>{$n})(?: (?<cuadrante>{$cuadrantes}))? # (?<placa>{$n})-(?<placa2>\d{1,3}[A-Z]?)(?<resto>, .*)?$/u", $direccion, $m)) {
            return self::conResto([
                'tipo' => $m['tipo'],
                'numero' => $m['numero'],
                'cuadrante' => ($m['cuadrante'] ?? '') ?: null,
                'placa' => $m['placa'],
                'placa2' => $m['placa2'],
                'descripcion' => null,
            ], $m['resto'] ?? '');
        }

        if (preg_match("/^(?<tipo>{$descriptivas}) (?<descripcion>[^,]+)(?<resto>, .*)?$/u", $direccion, $m)) {
            return self::conResto([
                'tipo' => $m['tipo'],
                'numero' => null,
                'cuadrante' => null,
                'placa' => null,
                'placa2' => null,
                'descripcion' => $m['descripcion'],
            ], $m['resto'] ?? '');
        }

        return null;
    }

    /** Separa «, Apto 201, Ref: frente al parque» en complemento y referencia. */
    private static function conResto(array $partes, string $resto): array
    {
        $resto = (string) preg_replace('/^, /', '', $resto);
        $referencia = null;

        if (str_starts_with($resto, 'Ref: ')) {
            [$resto, $referencia] = ['', substr($resto, 5)];
        } elseif (($posicion = strrpos($resto, self::REFERENCIA)) !== false) {
            $referencia = substr($resto, $posicion + strlen(self::REFERENCIA));
            $resto = substr($resto, 0, $posicion);
        }

        return $partes + [
            'complemento' => $resto !== '' ? $resto : null,
            'referencia' => $referencia,
        ];
    }

    /** Lo escrito a mano antes: abreviaturas, «No.», espacios de cualquier forma. */
    private static function partesAntiguas(string $direccion): ?array
    {
        $abreviaturas = [
            'avenida calle' => 'Avenida Calle', 'ac' => 'Avenida Calle',
            'avenida carrera' => 'Avenida Carrera', 'ak' => 'Avenida Carrera',
            'calle' => 'Calle', 'cll' => 'Calle', 'clle' => 'Calle', 'cl' => 'Calle',
            'carrera' => 'Carrera', 'cra' => 'Carrera', 'kra' => 'Carrera', 'carr' => 'Carrera',
            'cr' => 'Carrera', 'kr' => 'Carrera',
            'avenida' => 'Avenida', 'avda' => 'Avenida', 'av' => 'Avenida',
            'diagonal' => 'Diagonal', 'diag' => 'Diagonal', 'dg' => 'Diagonal',
            'transversal' => 'Transversal', 'transv' => 'Transversal', 'tv' => 'Transversal', 'tr' => 'Transversal',
            'circular' => 'Circular', 'circunvalar' => 'Circunvalar',
        ];
        $tipos = collect(array_keys($abreviaturas))->sortByDesc(fn ($v) => strlen($v))->implode('|');
        $numero = '\d{1,3} ?[A-Za-z]?(?: ?bis)?(?: ?[A-Za-z](?![A-Za-z]))?';

        $patron = "/^(?<tipo>{$tipos})\.? ?(?<numero>{$numero}) ?(?<cuadrante>sur|este|norte|oeste)?"
            . " ?(?:#|n(?:o|ro|um|°|º)?\.?) ?(?<placa>{$numero}) ?- ?(?<placa2>\d{1,3}[A-Za-z]?)(?![A-Za-z0-9])"
            . "[\s,.\-]*(?<resto>.*)$/iu";

        if (!preg_match($patron, $direccion, $m)) {
            return null;
        }

        $resto = self::limpiar($m['resto'] ?? '');

        return [
            'tipo' => $abreviaturas[mb_strtolower($m['tipo'])],
            'numero' => self::normalizarNumero($m['numero']),
            'cuadrante' => ($m['cuadrante'] ?? '') !== '' ? ucfirst(mb_strtolower($m['cuadrante'])) : null,
            'placa' => self::normalizarNumero($m['placa']),
            'placa2' => strtoupper($m['placa2']),
            'descripcion' => null,
            'complemento' => $resto !== '' ? mb_substr($resto, 0, 80) : null,
            'referencia' => null,
        ];
    }
}

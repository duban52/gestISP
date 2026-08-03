<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\PppoeAccount;

/**
 * Generación de credenciales y comentario de una cuenta PPPoE.
 *
 * POR QUÉ ESTÁ EN EL SERVIDOR
 * ---------------------------
 * Las reglas vivían en el JavaScript del formulario. Funcionaban,
 * pero solo servían al caso "cuenta de un contrato" y no había forma
 * de saber si el usuario propuesto ya existía en el router: eso solo
 * se descubría al guardar, con un error. Trayéndolas aquí, el mismo
 * código atiende los dos casos (con contrato y sin él) y puede
 * consultar la base para entregar un usuario que de verdad esté
 * libre.
 *
 * LAS REGLAS (las de siempre)
 * ---------------------------
 *   usuario     primernombre_primerapellido_referencia
 *   contraseña  primerapellido_primeroscincodigitosdeidentidad
 *   comentario  Contrato X, CC Y Nombres Apellidos
 *
 * La "referencia" es el número de contrato cuando la cuenta pertenece
 * a uno. Cuando no —una cuenta suelta cuyo titular está en otro
 * sistema— es el número de contrato o de cliente de ESE sistema; y si
 * tampoco hay, la identificación, que es lo único que queda.
 *
 * EL DIFERENCIADOR
 * ----------------
 * Un usuario PPPoE tiene que ser único dentro de su router. Dos
 * hermanos con el mismo nombre en la misma casa, o un segundo enlace
 * del mismo titular, producen el mismo nombre base. En ese caso se
 * agrega un sufijo numérico (_2, _3, …) hasta encontrar uno libre.
 * Se busca contra la base local, que es el espejo de lo que hay en
 * el router; si aun así hubiera choque, el guardado lo rechaza con
 * un mensaje claro y nadie pisa una cuenta ajena.
 */
class PppoeCredentialGenerator
{
    /** Hasta dónde se prueba antes de rendirse y usar la hora. */
    private const MAX_INTENTOS = 50;

    /**
     * Credenciales a partir de un contrato del sistema.
     *
     * @return array{username: string, password: string, comment: string}
     */
    public function paraContrato(Contract $contrato, ?int $routerId = null): array
    {
        $cliente = $contrato->client;

        return $this->generar([
            'nombres' => $cliente?->name,
            'apellidos' => $cliente?->last_name,
            'identificacion' => $cliente?->identity_number,
            // El número VISIBLE del contrato, no el id interno: es el
            // que el cliente tiene impreso y el que se busca después.
            'referencia' => $contrato->numero_visible,
            'etiqueta_referencia' => 'Contrato',
        ], $routerId);
    }

    /**
     * Credenciales a partir de datos escritos a mano.
     *
     * Es el caso de una cuenta sin contrato en gestISP cuyo titular
     * sí existe, pero en otro sistema.
     *
     * @param  array{nombres?: ?string, apellidos?: ?string, identificacion?: ?string, referencia?: ?string, etiqueta_referencia?: ?string}  $datos
     * @return array{username: string, password: string, comment: string}
     */
    public function generar(array $datos, ?int $routerId = null): array
    {
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim((string) ($datos['apellidos'] ?? ''));
        $identificacion = preg_replace('/\D/', '', (string) ($datos['identificacion'] ?? ''));
        $referencia = trim((string) ($datos['referencia'] ?? ''));

        $primerNombre = $this->primeraPalabra($nombres);
        $primerApellido = $this->primeraPalabra($apellidos);

        // Sin referencia se usa la identificación: el usuario tiene
        // que llevar algo que lo distinga de otro homónimo.
        //
        // Se le quitan los espacios además de lo que quita normalizar:
        // es un identificador, no un nombre que haya que partir en
        // palabras, y "YV-000 42" debe quedar "yv00042" y no dejar un
        // espacio en mitad del usuario PPPoE.
        $sufijo = str_replace(' ', '', $this->normalizar($referencia ?: $identificacion));

        $base = collect([$primerNombre, $primerApellido, $sufijo])
            ->filter()
            ->implode('_');

        return [
            'username' => $base === '' ? '' : $this->usuarioLibre($base, $routerId),
            'password' => $this->contrasena($primerApellido, $identificacion),
            'comment' => $this->comentario(
                $nombres,
                $apellidos,
                (string) ($datos['identificacion'] ?? ''),
                $referencia,
                (string) ($datos['etiqueta_referencia'] ?? 'Ref.'),
            ),
        ];
    }

    /**
     * Primer usuario libre en el router a partir del nombre base.
     *
     * Sin router no se puede comprobar nada (la unicidad es por
     * router), así que se devuelve la base tal cual y será el
     * guardado quien avise si choca.
     */
    public function usuarioLibre(string $base, ?int $routerId): string
    {
        if (!$routerId) {
            return $base;
        }

        // Una sola consulta: todos los usuarios del router que
        // empiezan por la base. Comprobar uno por uno sería una
        // consulta por intento.
        $ocupados = PppoeAccount::where('router_id', $routerId)
            ->where('username', 'like', $base . '%')
            ->pluck('username')
            ->map(fn ($u) => mb_strtolower($u))
            ->flip();

        if (!isset($ocupados[mb_strtolower($base)])) {
            return $base;
        }

        for ($i = 2; $i <= self::MAX_INTENTOS; $i++) {
            $candidato = $base . '_' . $i;

            if (!isset($ocupados[mb_strtolower($candidato)])) {
                return $candidato;
            }
        }

        // Cincuenta homónimos en un router es imposible en la
        // práctica; si pasa, algo raro ocurre y es mejor un nombre
        // feo pero libre que un error sin salida.
        return $base . '_' . now()->format('His');
    }

    /**
     * Contraseña: primer apellido + primeros cinco dígitos del
     * documento. Si falta alguno de los dos, se completa con lo que
     * haya para no entregar una contraseña vacía.
     */
    private function contrasena(string $primerApellido, string $identificacion): string
    {
        $digitos = substr($identificacion, 0, 5);

        $partes = collect([$primerApellido, $digitos])->filter();

        return $partes->isEmpty() ? '' : $partes->implode('_');
    }

    /**
     * Comentario legible: es lo que se ve en el listado y en el
     * propio Mikrotik, así que va con los datos tal como se
     * escribieron (con tildes y mayúsculas), no normalizados.
     */
    private function comentario(
        string $nombres,
        string $apellidos,
        string $identificacion,
        string $referencia,
        string $etiquetaReferencia,
    ): string {
        $partes = [];

        if ($referencia !== '') {
            $partes[] = trim($etiquetaReferencia . ' ' . $referencia);
        }

        if ($identificacion !== '') {
            $partes[] = 'CC ' . $identificacion;
        }

        $nombre = trim($nombres . ' ' . $apellidos);

        if ($nombre !== '') {
            $partes[] = $nombre;
        }

        // "Contrato ENG000123, CC 12345678 Juan Pérez"
        $comentario = implode(', ', array_slice($partes, 0, 2));

        if (count($partes) > 2) {
            $comentario .= ' ' . $partes[2];
        }

        return $comentario;
    }

    /** Primera palabra ya normalizada ("De la Cruz" → "de"). */
    private function primeraPalabra(string $texto): string
    {
        $normalizado = $this->normalizar($texto);

        return $normalizado === '' ? '' : explode(' ', $normalizado)[0];
    }

    /**
     * Minúsculas, sin tildes y solo con caracteres que un usuario
     * PPPoE admite sin problemas en cualquier equipo.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        // Mapa explícito en vez de iconv: //TRANSLIT depende de la
        // configuración regional del servidor y en Windows devuelve
        // cosas como "n~" o signos de interrogación.
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        // Fuera todo lo que no sea letra, número o espacio
        $texto = preg_replace('/[^a-z0-9 ]/', '', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }
}

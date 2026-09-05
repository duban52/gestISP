<?php

namespace App\Billing\Dian;

use RuntimeException;

/**
 * Genera un certificado autofirmado, en formato .p12.
 *
 * PARA QUÉ SIRVE Y PARA QUÉ NO
 * ----------------------------
 * Sirve para ejercitar TODO el camino del documento electrónico sin
 * tener aún el certificado de verdad: armar el XML, firmarlo, validarlo
 * contra el XSD, verlo en la pantalla en estado FIRMADO con su QR, y
 * hasta mandarlo por SOAP.
 *
 * **No sirve para que la DIAN lo acepte.** La DIAN exige que el
 * certificado encadene contra una entidad de certificación acreditada
 * por la ONAC; uno autofirmado lo rechaza por política, no por
 * mecánica. La firma que produce es correcta —verifica con su clave
 * pública, los resúmenes cuadran—; lo que falla es quién la avala.
 *
 * POR QUÉ EXISTE ESTO EN LUGAR DE ESPERAR AL CERTIFICADO
 * ------------------------------------------------------
 * Porque conseguir el certificado real depende de un tercero y puede
 * tardar días o semanas, y mientras tanto el sistema entero quedaba sin
 * poder probarse más allá del XML sin firmar. Poder ver el flujo
 * completo antes es la diferencia entre descubrir un problema ahora o
 * descubrirlo el día de la habilitación.
 *
 * OPENSSL EN WINDOWS
 * ------------------
 * En Linux `openssl` encuentra su configuración solo. En Windows hay
 * que decirle dónde está `openssl.cnf` o no genera nada, y el error que
 * devuelve no lo explica. Se intenta primero sin decir nada —que es lo
 * que funciona en el servidor— y solo si falla se busca el archivo.
 */
class SelfSignedCertificate
{
    /**
     * Genera el .p12 y devuelve su contenido binario.
     *
     * @param  array<string, string>  $identidad  Campos del sujeto (CN, O, ...)
     * @param  string  $clave  Contraseña con la que se cifra el .p12
     * @param  int  $dias  Vigencia
     */
    public function generar(array $identidad, string $clave, int $dias = 365): string
    {
        $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $llave = @openssl_pkey_new($opciones);

        if ($llave === false && ($configuracion = $this->configuracionDeOpenssl()) !== null) {
            $opciones['config'] = $configuracion;
            $llave = @openssl_pkey_new($opciones);
        }

        if ($llave === false) {
            throw new RuntimeException(
                'OpenSSL no pudo generar la clave: no se encontró openssl.cnf. '
                . 'Defina la variable de entorno OPENSSL_CONF apuntando a ese archivo.'
            );
        }

        // Solo se pasa `config` si hubo que averiguarlo: pasarlo vacío
        // en Linux rompe lo que ya funcionaba.
        $extra = isset($opciones['config']) ? ['config' => $opciones['config']] : [];

        $solicitud = @openssl_csr_new($identidad, $llave, $extra + ['digest_alg' => 'sha256']);

        if ($solicitud === false) {
            throw new RuntimeException('OpenSSL no pudo crear la solicitud de certificado.');
        }

        // Firmada por su propia clave: eso es «autofirmado», y es
        // exactamente lo que la DIAN no acepta.
        $certificado = @openssl_csr_sign($solicitud, null, $llave, $dias, $extra + ['digest_alg' => 'sha256']);

        if ($certificado === false) {
            throw new RuntimeException('OpenSSL no pudo firmar el certificado.');
        }

        $p12 = '';

        if (!@openssl_pkcs12_export($certificado, $p12, $llave, $clave, $extra)) {
            throw new RuntimeException('OpenSSL no pudo exportar el .p12.');
        }

        return $p12;
    }

    /**
     * ¿Este certificado se firmó a sí mismo?
     *
     * Se compara el emisor con el sujeto. Es la comprobación barata y
     * es la que importa aquí: un certificado emitido por una entidad
     * acreditada nunca tiene el mismo emisor que titular.
     *
     * @param  array<string, mixed>|false  $datos  Lo que devuelve openssl_x509_parse()
     */
    public function esAutofirmado(array|false $datos): bool
    {
        if ($datos === false) {
            return false;
        }

        return ($datos['issuer'] ?? null) === ($datos['subject'] ?? false);
    }

    /** Dónde está openssl.cnf, si hace falta decirlo. */
    private function configuracionDeOpenssl(): ?string
    {
        $candidatos = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ];

        foreach (array_filter($candidatos) as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }
}

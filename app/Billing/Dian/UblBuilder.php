<?php

namespace App\Billing\Dian;

use App\Models\FiscalCatalog;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lo que comparten la factura y las notas al armar su XML.
 *
 * POR QUÉ UNA BASE Y NO DOS COPIAS
 * --------------------------------
 * Una factura y una nota crédito son documentos distintos —raíz
 * distinta, elementos distintos, totales con otro nombre— pero
 * describen a las MISMAS partes con las MISMAS reglas: el emisor, el
 * adquiriente, sus direcciones con código DANE, sus responsabilidades
 * fiscales, y el formato de los importes.
 *
 * Y esas reglas son justo donde están los errores que cuestan días: un
 * importe con separador de miles, un municipio que va como código y no
 * como nombre, un documento con puntos. Dos copias de eso acabarían
 * divergiendo, y la divergencia se descubriría el día que la DIAN
 * rechace solo uno de los dos tipos de documento.
 *
 * Lo que cada documento tiene de suyo —qué elementos lleva y en qué
 * orden— vive en su propia clase, porque en UBL el orden es parte del
 * contrato y no se puede generalizar.
 */
abstract class UblBuilder
{
    /** El NIT de la DIAN, que es quien autoriza. Constante del anexo. */
    protected const NIT_DIAN = '800197268';

    /** Literales que el anexo fija palabra por palabra. */
    protected const AGENCIA_ID = '195';
    protected const AGENCIA_NOMBRE = 'CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)';
    protected const PAIS = 'CO';
    protected const MONEDA = 'COP';

    /**
     * Valida un XML contra el esquema que le corresponda.
     *
     * @return array<int, string>  vacío si es válido
     */
    protected function validarContra(string $xml, string $esquema): array
    {
        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $doc->schemaValidate(resource_path('dian/xsd/maindoc/' . $esquema));

        $errores = array_map(
            fn (\LibXMLError $error) => trim($error->message),
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return $errores;
    }

    // ==================== Las partes ====================

    /**
     * El emisor.
     *
     * @param  string|null  $prefijoAutorizado  Solo lo lleva la factura:
     *   ante la DIAN el prefijo es parte de la identidad del emisor. Una
     *   nota no sale de un rango autorizado, así que no lo tiene.
     */
    protected function emisor(\DOMDocument $doc, \DOMElement $raiz, $empresa, ?string $prefijoAutorizado): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:AccountingSupplierParty');
        $this->hijo($doc, $nodo, 'cbc:AdditionalAccountID', (string) $empresa->organization_type_code);

        $parte = $this->hijo($doc, $nodo, 'cac:Party');

        $nombre = $this->hijo($doc, $parte, 'cac:PartyName');
        $this->hijo($doc, $nombre, 'cbc:Name', $empresa->nombreVisible());

        $ubicacion = $this->hijo($doc, $parte, 'cac:PhysicalLocation');
        $this->direccion($doc, $ubicacion, 'cac:Address', $empresa->municipality_dane_code, $empresa->department_dane_code, (string) $empresa->address);

        $tributario = $this->hijo($doc, $parte, 'cac:PartyTaxScheme');
        $this->hijo($doc, $tributario, 'cbc:RegistrationName', (string) $empresa->legal_name);
        $this->identificacion($doc, $tributario, $empresa->document_number, $empresa->verification_digit, $empresa->document_type_code);
        $this->hijo($doc, $tributario, 'cbc:TaxLevelCode', $this->responsabilidades($empresa));
        $this->direccion($doc, $tributario, 'cac:RegistrationAddress', $empresa->municipality_dane_code, $empresa->department_dane_code, (string) $empresa->address);
        $this->esquemaIva($doc, $tributario);

        $legal = $this->hijo($doc, $parte, 'cac:PartyLegalEntity');
        $this->hijo($doc, $legal, 'cbc:RegistrationName', (string) $empresa->legal_name);
        $this->identificacion($doc, $legal, $empresa->document_number, $empresa->verification_digit, $empresa->document_type_code);

        if ($prefijoAutorizado !== null) {
            $registro = $this->hijo($doc, $legal, 'cac:CorporateRegistrationScheme');
            $this->hijo($doc, $registro, 'cbc:ID', $prefijoAutorizado);
        }

        $contacto = $this->hijo($doc, $parte, 'cac:Contact');
        if ($empresa->phone) {
            $this->hijo($doc, $contacto, 'cbc:Telephone', (string) $empresa->phone);
        }
        $this->hijo($doc, $contacto, 'cbc:ElectronicMail', (string) $empresa->email);
    }

    /** El adquiriente. */
    protected function adquiriente(\DOMDocument $doc, \DOMElement $raiz, $cliente): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:AccountingCustomerParty');
        $this->hijo($doc, $nodo, 'cbc:AdditionalAccountID', (string) $cliente->organization_type_code);

        $parte = $this->hijo($doc, $nodo, 'cac:Party');

        $nombre = $this->hijo($doc, $parte, 'cac:PartyName');
        $this->hijo($doc, $nombre, 'cbc:Name', $cliente->fullName());

        $ubicacion = $this->hijo($doc, $parte, 'cac:PhysicalLocation');
        $this->direccion($doc, $ubicacion, 'cac:Address', $cliente->municipality_dane_code, $cliente->department_dane_code, (string) $cliente->fiscal_address);

        $tributario = $this->hijo($doc, $parte, 'cac:PartyTaxScheme');
        $this->hijo($doc, $tributario, 'cbc:RegistrationName', $cliente->fullName());
        $this->identificacion($doc, $tributario, $cliente->identity_number, $cliente->verification_digit, $cliente->document_type_code);
        $this->hijo($doc, $tributario, 'cbc:TaxLevelCode', $this->responsabilidades($cliente));
        $this->direccion($doc, $tributario, 'cac:RegistrationAddress', $cliente->municipality_dane_code, $cliente->department_dane_code, (string) $cliente->fiscal_address);
        $this->esquemaIva($doc, $tributario);

        $legal = $this->hijo($doc, $parte, 'cac:PartyLegalEntity');
        $this->hijo($doc, $legal, 'cbc:RegistrationName', $cliente->fullName());
        $this->identificacion($doc, $legal, $cliente->identity_number, $cliente->verification_digit, $cliente->document_type_code);

        $contacto = $this->hijo($doc, $parte, 'cac:Contact');
        if ($cliente->number_phone) {
            $this->hijo($doc, $contacto, 'cbc:Telephone', (string) $cliente->number_phone);
        }
        $this->hijo($doc, $contacto, 'cbc:ElectronicMail', (string) $cliente->email);
    }

    // ==================== Piezas sueltas ====================

    protected function hijo(\DOMDocument $doc, \DOMElement $padre, string $nombre, ?string $valor = null): \DOMElement
    {
        // El texto se añade como nodo y no como segundo argumento de
        // createElement: así se escapan &, < y > en vez de romper el XML
        // con el nombre de una empresa que lleve «&».
        $nodo = $doc->createElement($nombre);

        if ($valor !== null && $valor !== '') {
            $nodo->appendChild($doc->createTextNode($valor));
        }

        $padre->appendChild($nodo);

        return $nodo;
    }

    protected function importe(\DOMDocument $doc, \DOMElement $padre, string $nombre, float $valor): \DOMElement
    {
        $nodo = $this->hijo($doc, $padre, $nombre, number_format($valor, 2, '.', ''));
        $nodo->setAttribute('currencyID', self::MONEDA);

        return $nodo;
    }

    protected function esquemaIva(\DOMDocument $doc, \DOMElement $padre): void
    {
        $esquema = $this->hijo($doc, $padre, 'cac:TaxScheme');
        $this->hijo($doc, $esquema, 'cbc:ID', '01');
        $this->hijo($doc, $esquema, 'cbc:Name', 'IVA');
    }

    protected function identificacion(
        \DOMDocument $doc,
        \DOMElement $padre,
        ?string $numero,
        ?string $digito,
        ?string $tipo,
    ): void {
        $id = $this->hijo($doc, $padre, 'cbc:CompanyID', (string) $numero);
        $id->setAttribute('schemeID', (string) ($digito ?? '0'));
        $id->setAttribute('schemeName', (string) $tipo);
        $id->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $id->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);
    }

    /**
     * Una dirección, igual para el emisor y para el adquiriente.
     *
     * El `cbc:ID` de la dirección es el código DANE del MUNICIPIO, no un
     * identificador interno. Es el dato que más se equivoca, y el que la
     * DIAN usa para ubicar la operación.
     */
    protected function direccion(
        \DOMDocument $doc,
        \DOMElement $padre,
        string $etiqueta,
        ?string $municipio,
        ?string $departamento,
        string $calle,
    ): void {
        $nodo = $this->hijo($doc, $padre, $etiqueta);

        $this->hijo($doc, $nodo, 'cbc:ID', (string) $municipio);
        $this->hijo($doc, $nodo, 'cbc:CityName', FiscalCatalog::nombre(FiscalCatalog::MUNICIPIO, $municipio) ?? '');
        $this->hijo($doc, $nodo, 'cbc:CountrySubentity', FiscalCatalog::nombre(FiscalCatalog::DEPARTAMENTO, $departamento) ?? '');
        $this->hijo($doc, $nodo, 'cbc:CountrySubentityCode', (string) $departamento);

        $linea = $this->hijo($doc, $nodo, 'cac:AddressLine');
        $this->hijo($doc, $linea, 'cbc:Line', $calle);

        $pais = $this->hijo($doc, $nodo, 'cac:Country');
        $this->hijo($doc, $pais, 'cbc:IdentificationCode', self::PAIS);
        $nombrePais = $this->hijo($doc, $pais, 'cbc:Name', 'Colombia');
        $nombrePais->setAttribute('languageID', 'es');
    }

    /**
     * Las responsabilidades fiscales, separadas por punto y coma.
     *
     * Sin ninguna se informa `R-99-PN` («no aplica»), que es lo que
     * corresponde a quien no tiene responsabilidades especiales — y no
     * dejarlo vacío, que la DIAN rechaza.
     */
    protected function responsabilidades($parte): string
    {
        $codigos = $parte->taxResponsibilities->pluck('responsibility_code')->all();

        return $codigos === [] ? 'R-99-PN' : implode(';', $codigos);
    }

    /**
     * La fecha y hora de emisión, en la zona horaria de Colombia.
     *
     * El huso NO es opcional: entra en el CUFE y en el CUDE. Y tiene que
     * ser el de Colombia aunque el servidor esté en UTC, o el código
     * saldría distinto del que espera la DIAN.
     */
    protected function momentoDe($documento): Carbon
    {
        $creado = $documento->created_at ? Carbon::parse($documento->created_at) : Carbon::now();
        $fecha = $documento->issue_date ? Carbon::parse($documento->issue_date) : $creado;

        return $fecha->copy()
            ->setTime((int) $creado->format('H'), (int) $creado->format('i'), (int) $creado->format('s'))
            ->setTimezone('America/Bogota');
    }

    /**
     * Comprueba que están los datos sin los que el XML no vale.
     *
     * Falla nombrando lo que falta. Un XML incompleto lo rechaza la DIAN
     * con un código que no explica nada, y el informe de completitud
     * fiscal existe justamente para no llegar hasta aquí a ciegas.
     */
    protected function exigirDatos($empresa, $cliente): void
    {
        if (!$empresa) {
            throw new RuntimeException('El documento no tiene empresa emisora.');
        }

        if (!$cliente) {
            throw new RuntimeException('El documento no tiene cliente: no se puede identificar al adquiriente.');
        }

        $faltan = [];

        foreach ([
            'document_number' => 'NIT de la empresa',
            'legal_name' => 'razón social de la empresa',
            'organization_type_code' => 'tipo de organización de la empresa',
            'municipality_dane_code' => 'municipio (DANE) de la empresa',
            'department_dane_code' => 'departamento (DANE) de la empresa',
            'address' => 'dirección de la empresa',
            'email' => 'correo de la empresa',
        ] as $campo => $etiqueta) {
            if (blank($empresa->{$campo})) {
                $faltan[] = $etiqueta;
            }
        }

        foreach ([
            'identity_number' => 'documento del cliente',
            'document_type_code' => 'tipo de documento del cliente',
            'organization_type_code' => 'tipo de organización del cliente',
            'municipality_dane_code' => 'municipio (DANE) del cliente',
            'department_dane_code' => 'departamento (DANE) del cliente',
            'fiscal_address' => 'dirección fiscal del cliente',
            'email' => 'correo del cliente',
        ] as $campo => $etiqueta) {
            if (blank($cliente->{$campo})) {
                $faltan[] = $etiqueta;
            }
        }

        if ($faltan !== []) {
            throw new RuntimeException(
                'Faltan datos fiscales para armar el XML: ' . implode(', ', $faltan)
                . '. Complételos antes de emitir (informe de completitud fiscal).'
            );
        }
    }
}

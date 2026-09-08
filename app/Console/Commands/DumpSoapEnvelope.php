<?php

namespace App\Console\Commands;

use App\Billing\Dian\Transport\DianEndpoints;
use App\Billing\Dian\Transport\SoapDianTransport;
use App\Models\DianCertificate;
use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Enseña el sobre SOAP que se le mandaría a la DIAN, sin mandarlo.
 *
 *     php artisan dian:sobre --documento=1
 *     php artisan dian:sobre --documento=1 --guardar=/tmp/sobre.xml
 *
 * PARA QUÉ
 * --------
 * Para poder MIRAR lo que se envía. Cuando la DIAN rechaza un sobre
 * contesta `wsse:InvalidSecurity` — «An error occurred when verifying
 * security for the message»— y nada más: no dice qué encabezado falta,
 * ni qué referencia no cuadra, ni qué algoritmo no esperaba.
 *
 * Sin ver el sobre, depurar eso es cambiar una cosa, desplegar y
 * reenviar. Cada vuelta cuesta un intento y no descarta casi nada.
 * Teniéndolo delante se comprueban de golpe las referencias de la
 * firma, los identificadores, los algoritmos y qué va firmado.
 *
 * NO ENVÍA NADA
 * -------------
 * Arma el sobre y lo escribe. No abre ninguna conexión, no toca el
 * documento y no cuenta como intento.
 *
 * SÍ USA EL CERTIFICADO
 * ---------------------
 * Tiene que hacerlo: la firma es justamente lo que se quiere mirar. El
 * sobre resultante lleva el certificado público dentro —como el que se
 * envía— así que **no es un archivo para andar compartiendo sin
 * mirarlo**. La clave privada no aparece, pero sí el documento firmado.
 */
class DumpSoapEnvelope extends Command
{
    protected $signature = 'dian:sobre
                            {--documento= : Id del electronic_document}
                            {--guardar= : Archivo donde escribirlo. Sin esto, sale por pantalla}';

    protected $description = 'Enseña el sobre SOAP que se le mandaría a la DIAN, sin enviarlo';

    public function handle(SoapDianTransport $transporte, DianEndpoints $endpoints): int
    {
        $documento = $this->documento();

        if (!$documento) {
            return self::FAILURE;
        }

        $certificado = DianCertificate::withoutGlobalScopes()
            ->where('company_id', $documento->company_id)
            ->where('active', true)
            ->get()
            ->first(fn (DianCertificate $c) => $c->vigente());

        if (!$certificado) {
            $this->error('La empresa no tiene certificado vigente: sin él no hay sobre que armar.');

            return self::FAILURE;
        }

        $url = $endpoints->para(
            $documento->environment_code,
            $documento->company?->dianConfiguration?->endpoint_override,
        );

        if (!$url) {
            $this->error('No hay URL para el ambiente de este documento.');

            return self::FAILURE;
        }

        $sobre = $transporte->sobreDe($documento, $url, $certificado);

        if ($destino = $this->option('guardar')) {
            file_put_contents($destino, $sobre);
            $this->info(sprintf('Sobre escrito en %s (%s bytes).', $destino, number_format(strlen($sobre))));
        } else {
            $this->line($sobre);
        }

        $this->newLine();
        $this->resumir($sobre);

        return self::SUCCESS;
    }

    /**
     * Lo que hay que mirar primero, ya extraído.
     *
     * El sobre entero son decenas de miles de caracteres —el documento
     * va comprimido y en base64— y lo que interesa para depurar la
     * seguridad son cuatro cosas.
     */
    private function resumir(string $sobre): void
    {
        $doc = new \DOMDocument();
        $doc->loadXML($sobre);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('wsu', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');

        $this->line('<options=bold>Qué va firmado</>');

        $filas = [];

        foreach ($xpath->query('//ds:Reference') as $referencia) {
            $uri = ltrim($referencia->getAttribute('URI'), '#');

            // A quién apunta esa referencia.
            $apuntado = $xpath->query(sprintf('//*[@wsu:Id="%s"]', $uri))->item(0);

            $filas[] = [
                $uri,
                $apuntado instanceof \DOMElement ? $apuntado->localName : '⚠ NO EXISTE',
                $xpath->query('.//ds:DigestMethod/@Algorithm', $referencia)->item(0)?->value ?? '—',
            ];
        }

        $this->table(['Referencia', 'Apunta a', 'Resumen'], $filas);

        $algoritmo = $xpath->query('//ds:SignatureMethod/@Algorithm')->item(0)?->value;
        $c14n = $xpath->query('//ds:CanonicalizationMethod/@Algorithm')->item(0)?->value;

        $this->line('Firma:            ' . ($algoritmo ?? '—'));
        $this->line('Canonicalización: ' . ($c14n ?? '—'));
        $this->line('Tamaño del sobre: ' . number_format(strlen($sobre)) . ' bytes');
    }

    private function documento(): ?ElectronicDocument
    {
        $id = $this->option('documento');

        if (!$id) {
            $pendientes = ElectronicDocument::withoutGlobalScopes()
                ->where('status', ElectronicDocument::FIRMADO)
                ->latest('id')
                ->limit(5)
                ->get(['id', 'invoice_id', 'cufe']);

            if ($pendientes->isEmpty()) {
                $this->error('No hay documentos firmados. Indique uno con --documento=');

                return null;
            }

            $this->error('Indique el documento con --documento=. Firmados sin transmitir:');

            foreach ($pendientes as $uno) {
                $this->line(sprintf('  %d  factura %s', $uno->id, $uno->invoice_id));
            }

            return null;
        }

        $documento = ElectronicDocument::withoutGlobalScopes()->with('company.dianConfiguration')->find($id);

        if (!$documento) {
            $this->error('No existe el documento ' . $id . '.');
        }

        return $documento;
    }
}

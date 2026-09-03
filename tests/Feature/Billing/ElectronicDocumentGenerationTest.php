<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Services\InvoiceGenerator;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\NumberingRange;

/**
 * Emitir una factura electrónica crea su documento DIAN.
 *
 * QUÉ CIERRA ESTA PIEZA
 * ---------------------
 * Hasta ahora las tablas de la DIAN existían y nadie escribía en ellas:
 * una factura electrónica sacaba su número del rango autorizado y ahí se
 * acababa todo. El CUFE se sabía calcular pero no lo calculaba nadie.
 *
 * Con el enganche a `InvoiceIssued`, emitir produce el documento: su
 * XML, su CUFE y su QR, guardados y listos para firmar y transmitir.
 *
 * LO QUE MÁS SE DEFIENDE AQUÍ
 * ---------------------------
 * Que un fallo **no tumbe la facturación**. La factura ya está emitida y
 * ya gastó un consecutivo autorizado; que al cliente le falte el
 * municipio no puede deshacer eso ni reventar la corrida mensual a
 * mitad. Tiene que quedar anotado y seguir.
 */
class ElectronicDocumentGenerationTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->empresa()->update([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_type_code' => '31',
            'document_number' => '900374637',
            'verification_digit' => '9',
            'organization_type_code' => '1',
            'address' => 'Calle 50 # 40-30',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'facturacion@fibraandina.co',
            'electronic_invoicing_enabled' => true,
        ]);

        DianConfiguration::create([
            'company_id' => $this->branch->company_id,
            'environment_code' => DianConfiguration::PRODUCCION,
            'enabled_at' => now(),
            'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
            'software_pin' => '12345',
        ]);

        $resolucion = DianResolution::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => '693ff6f2a553c3646a063436fd4dd9ded0311471',
        ]);

        NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'branch_id' => $this->branch->id,
            'prefix' => 'SETP',
            'range_start' => 990000000,
            'range_end' => 995000000,
            'current_number' => 0,
        ]);
    }

    // ==================== El camino feliz ====================

    public function test_emitir_una_factura_electronica_crea_su_documento(): void
    {
        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->first();

        $this->assertNotNull($documento, 'No se creó el documento electrónico.');
        $this->assertSame(ElectronicDocument::GENERADO, $documento->status);
        $this->assertSame(96, strlen($documento->cufe));
        $this->assertNotNull($documento->generated_at);
        $this->assertNull($documento->last_error);
    }

    public function test_el_xml_guardado_es_valido(): void
    {
        // No basta con que se haya guardado algo: tiene que ser un UBL
        // que la DIAN acepte.
        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(
            [],
            app(InvoiceXmlBuilder::class)->erroresDeEsquema($documento->signed_xml),
        );
    }

    public function test_el_documento_guarda_la_resolucion_y_el_ambiente(): void
    {
        // El ambiente se congela: si mañana la empresa cambia, este
        // documento tiene que seguir diciendo dónde se emitió — su QR
        // apunta al catálogo de ese ambiente.
        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(DianConfiguration::PRODUCCION, $documento->environment_code);
        $this->assertSame('18760000001', $documento->resolution->resolution_number);
        $this->assertStringContainsString('CUFE: ' . $documento->cufe, $documento->qr_content);
    }

    public function test_una_factura_interna_no_crea_documento(): void
    {
        // Es la mayoría de los casos y no es un error: un documento
        // interno no se reporta a nadie.
        $grupo = AffinityGroup::factory()->create(['company_id' => $this->branch->company_id]);

        $contrato = $this->createBillableContract();
        $contrato->update(['affinity_group_id' => $grupo->id]);

        $factura = $this->emitir($contrato->fresh());

        $this->assertSame(
            0,
            ElectronicDocument::withoutGlobalScopes()->where('invoice_id', $factura->id)->count(),
        );
    }

    // ==================== La firma ====================

    public function test_sin_certificado_el_documento_queda_generado_sin_firmar(): void
    {
        // No es un error: es el estado normal mientras la empresa no
        // tenga su .p12 cargado. El XML ya sirve para revisarlo; lo que
        // no puede es transmitirse asi.
        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(ElectronicDocument::GENERADO, $documento->status);
        $this->assertNull($documento->signed_at);
        $this->assertNull($documento->dian_certificate_id);
        $this->assertStringNotContainsString('<ds:Signature', $documento->signed_xml);
    }

    public function test_con_certificado_vigente_el_documento_sale_firmado(): void
    {
        $certificado = $this->certificadoVigente();

        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(ElectronicDocument::FIRMADO, $documento->status);
        $this->assertNotNull($documento->signed_at);
        $this->assertSame($certificado->id, $documento->dian_certificate_id);
        $this->assertStringContainsString('<ds:Signature', $documento->signed_xml);
    }

    public function test_un_certificado_caducado_no_firma(): void
    {
        // Firmar con uno caducado produce documentos que la DIAN
        // rechaza: es peor que no firmar, porque parece que si.
        $this->certificadoVigente()->update(['valid_until' => now()->subDay()]);

        $factura = $this->emitir($this->contratoElectronico());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(ElectronicDocument::GENERADO, $documento->status);
        $this->assertStringNotContainsString('<ds:Signature', $documento->signed_xml);
    }

    // ==================== Cuando algo falla ====================

    public function test_un_fallo_no_impide_emitir_la_factura(): void
    {
        // ES LA PRUEBA IMPORTANTE. La factura ya gastó un consecutivo
        // autorizado y el cliente ya tiene el servicio: que falte un
        // dato fiscal no puede deshacer eso ni reventar la corrida.
        $contrato = $this->contratoElectronico();
        $contrato->client->update(['municipality_dane_code' => null]);

        $factura = $this->emitir($contrato->fresh());

        $this->assertNotNull($factura->full_number);
        $this->assertSame('SETP', $factura->prefix);
    }

    public function test_el_fallo_queda_anotado_con_su_motivo(): void
    {
        // «Algo falló» sin decir qué obliga a ir a leer los logs del
        // servidor. La propia fila tiene que decirlo.
        $contrato = $this->contratoElectronico();
        $contrato->client->update(['municipality_dane_code' => null]);

        $factura = $this->emitir($contrato->fresh());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(ElectronicDocument::BORRADOR, $documento->status);
        $this->assertStringContainsString('municipio', $documento->last_error);
        $this->assertNull($documento->cufe);
    }

    public function test_reintentar_completa_el_documento_y_limpia_el_error(): void
    {
        // Se arregla el dato y se vuelve a intentar: no se crea un
        // segundo documento, se completa el que estaba en borrador.
        $contrato = $this->contratoElectronico();
        $contrato->client->update(['municipality_dane_code' => null]);

        $factura = $this->emitir($contrato->fresh());

        $contrato->client->update(['municipality_dane_code' => '05001']);

        app(\App\Billing\Dian\ElectronicDocumentGenerator::class)
            ->generar($factura->fresh()->load('invoice_items', 'contract.client.taxResponsibilities'));

        $documentos = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->get();

        $this->assertCount(1, $documentos, 'Se creó un segundo documento en vez de completar el que había.');
        $this->assertSame(ElectronicDocument::GENERADO, $documentos->first()->status);
        $this->assertNull($documentos->first()->last_error);
        $this->assertSame(96, strlen($documentos->first()->cufe));
    }

    // ==================== Apoyo ====================

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    /**
     * Un certificado autofirmado guardado en disco, como uno real.
     *
     * Se guarda como ARCHIVO y no en la base porque es una clave
     * privada: en una columna acabaria tambien en cada copia de
     * seguridad y en cada volcado.
     */
    private function certificadoVigente(): \App\Models\DianCertificate
    {
        $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $llave = @openssl_pkey_new($opciones);

        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $ruta) {
            if ($llave === false && $ruta && is_file($ruta)) {
                $opciones['config'] = $ruta;
                $llave = @openssl_pkey_new($opciones);
            }
        }

        if ($llave === false) {
            $this->markTestSkipped('OpenSSL no puede generar claves en esta máquina.');
        }

        $extra = isset($opciones['config']) ? ['config' => $opciones['config']] : [];

        $solicitud = openssl_csr_new(['countryName' => 'CO', 'commonName' => 'Fibra Andina S.A.S.'], $llave, $extra + ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($solicitud, null, $llave, 365, $extra + ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($cert, $p12, $llave, 'prueba', $extra);

        $ruta = storage_path('app/certificados-prueba');
        @mkdir($ruta, 0755, true);
        $archivo = $ruta . '/prueba-' . uniqid() . '.p12';
        file_put_contents($archivo, $p12);

        return \App\Models\DianCertificate::create([
            'company_id' => $this->branch->company_id,
            'name' => 'Certificado de prueba',
            'path' => $archivo,
            'password' => 'prueba',
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->addYear(),
            'active' => true,
        ]);
    }

    private function contratoElectronico(): Contract
    {
        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $this->branch->company_id,
        ]);

        $contrato = $this->createBillableContract(price: 100000, taxPercent: 19);
        $contrato->update(['affinity_group_id' => $grupo->id]);

        $contrato->client->update([
            'document_type_code' => '13',
            'organization_type_code' => '2',
            'fiscal_address' => 'Carrera 70 # 30-20',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'cliente@ejemplo.com',
        ]);

        return $contrato->fresh();
    }

    private function emitir(Contract $contrato): Invoice
    {
        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato,
            now(),
            $this->admin->id,
        );

        $this->assertTrue(
            $resultado['generated'],
            'No se generó la factura: ' . ($resultado['reason'] ?? 'sin motivo'),
        );

        return $resultado['invoice'];
    }
}

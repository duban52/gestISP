<?php

namespace App\Billing\Dian;

use App\Billing\Services\ElectronicInvoicingDecider;
use App\Models\Branch;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Models\NumberingRange;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Convierte una factura electrónica ya emitida en su documento DIAN.
 *
 * QUÉ HACE
 * --------
 * Toma una factura cuyo tipo quedó congelado como electrónico, le arma
 * el XML, calcula su CUFE y su QR, y lo deja guardado en
 * `electronic_documents`. A partir de ahí el documento existe y se
 * puede firmar y transmitir.
 *
 * NO TUMBA LA EMISIÓN
 * -------------------
 * Esta es la regla que manda aquí. La factura **ya está emitida**: tiene
 * su número, su consecutivo gastado del rango autorizado, y el cliente
 * ya tiene el servicio. Que no se pueda armar el XML —porque al cliente
 * le falta el municipio, porque la resolución venció, porque el rango se
 * agotó— no puede deshacer nada de eso ni reventar la corrida mensual a
 * mitad de camino.
 *
 * Así que el fallo se atrapa y se ANOTA: queda una fila en borrador con
 * el motivo en `last_error`. Es la diferencia entre «algo falló» y saber
 * qué falló sin ir a leer los logs del servidor.
 *
 * ES IDEMPOTENTE
 * --------------
 * Volver a llamarlo sobre la misma factura no crea un segundo documento:
 * actualiza el que hay. Eso es lo que permite reintentar los que
 * quedaron en borrador sin comprobar nada antes.
 *
 * TODAVÍA NO FIRMA
 * ----------------
 * El documento queda en estado GENERADO, con el XML sin firmar. La firma
 * XAdES es el paso siguiente y va aparte: necesita el certificado .p12
 * de la empresa, que es lo único de todo esto que no se puede montar sin
 * tener uno real.
 */
class ElectronicDocumentGenerator
{
    public function __construct(
        private readonly InvoiceXmlBuilder $constructor = new InvoiceXmlBuilder(),
        private readonly XadesSigner $firmador = new XadesSigner(),
    ) {
    }

    /**
     * Genera —o regenera— el documento electrónico de una factura.
     *
     * Devuelve el documento, o null si la factura no es electrónica
     * (que no es un error: la mayoría no lo son).
     */
    public function generar(Invoice $factura): ?ElectronicDocument
    {
        if ($factura->document_kind !== ElectronicInvoicingDecider::ELECTRONICO) {
            return null;
        }

        $empresaId = $this->empresaDe($factura);

        // Fuera del try a proposito: lo que se llegue a averiguar antes
        // del fallo se guarda igual. Si el XML revienta por el
        // municipio del cliente, el ambiente y la resolucion ya se
        // sabian, y anotarlos ahorra averiguarlos otra vez al
        // reintentar.
        $configuracion = null;
        $rango = null;

        try {
            $configuracion = $this->configuracionDe($empresaId);
            $rango = $this->rangoDe($factura, $empresaId);

            $resultado = $this->constructor->construir($factura, $rango, $configuracion);

            // ---- La firma, si hay con que ----
            //
            // Sin certificado vigente el documento se queda GENERADO y
            // sin firmar. No es un error: es el estado normal mientras
            // la empresa no tenga su .p12 cargado, y el XML ya sirve
            // para revisarlo. Lo que NO puede es transmitirse asi.
            $certificado = $this->certificadoDe($empresaId);
            $xml = $resultado['xml'];
            $firmado = false;

            if ($certificado) {
                $xml = $this->firmador->firmar(
                    $xml,
                    $this->contenidoDel($certificado),
                    (string) $certificado->password,
                );
                $firmado = true;
            }

            return $this->guardar($factura, $empresaId, $rango, $configuracion, [
                'cufe' => $resultado['cufe'],
                'signed_xml' => $xml,
                'qr_content' => $resultado['qr'],
                'dian_certificate_id' => $certificado?->id,
                'status' => $firmado ? ElectronicDocument::FIRMADO : ElectronicDocument::GENERADO,
                'signed_at' => $firmado ? now() : null,
                // Se limpia: si se quedara, una pantalla estaria
                // enseñando un problema que ya no existe.
                'last_error' => null,
                'generated_at' => now(),
            ]);
        } catch (Throwable $error) {
            // La factura YA está emitida. Esto no puede tumbarla ni
            // reventar la corrida mensual: se anota y se sigue.
            Log::error('No se pudo generar el documento electrónico', [
                'factura' => $factura->full_number,
                'invoice_id' => $factura->id,
                'motivo' => $error->getMessage(),
            ]);

            return $this->guardar($factura, $empresaId, $rango, $configuracion, [
                'status' => ElectronicDocument::BORRADOR,
                'last_error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Guarda el documento, creándolo o actualizando el que hubiera.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardar(
        Invoice $factura,
        ?int $empresaId,
        ?NumberingRange $rango,
        ?DianConfiguration $configuracion,
        array $datos,
    ): ElectronicDocument {
        return ElectronicDocument::withoutGlobalScopes()->updateOrCreate(
            ['invoice_id' => $factura->id],
            array_merge([
                'company_id' => $empresaId,
                'dian_resolution_id' => $rango?->dian_resolution_id,
                // El ambiente se CONGELA aquí. Si mañana la empresa
                // pasa de pruebas a producción, este documento tiene
                // que seguir diciendo dónde se emitió — su QR apunta al
                // catálogo de ese ambiente.
                'environment_code' => $configuracion?->environment_code,
            ], $datos),
        );
    }

    /**
     * El certificado vigente de la empresa, si tiene alguno.
     *
     * Devuelve null cuando no hay: el documento se queda sin firmar y
     * eso esta bien mientras no haya que transmitirlo. Un certificado
     * CADUCADO no vale —firmar con el produce documentos que la DIAN
     * rechaza— y por eso `vigente()` mira tambien las fechas, no solo
     * la casilla de activo.
     */
    private function certificadoDe(?int $empresaId): ?DianCertificate
    {
        return DianCertificate::query()
            ->where('company_id', $empresaId)
            ->activos()
            ->get()
            ->first(fn (DianCertificate $certificado) => $certificado->vigente());
    }

    /**
     * El .p12 en binario.
     *
     * El certificado se guarda como ARCHIVO fuera del directorio
     * publico y solo su ruta va en la base: es una clave privada, y
     * meterla en una columna la mete tambien en cada copia de
     * seguridad de la base y en cada volcado que alguien haga.
     */
    private function contenidoDel(DianCertificate $certificado): string
    {
        $ruta = $certificado->path;

        if (!is_file($ruta)) {
            $ruta = storage_path('app/' . ltrim($certificado->path, '/'));
        }

        if (!is_file($ruta)) {
            throw new RuntimeException(sprintf(
                'El certificado «%s» no esta en su ruta (%s): no se puede firmar.',
                $certificado->name,
                $certificado->path,
            ));
        }

        return (string) file_get_contents($ruta);
    }

    private function empresaDe(Invoice $factura): ?int
    {
        return $factura->company_id
            ?? Branch::withoutGlobalScopes()->whereKey($factura->branch_id)->value('company_id');
    }

    private function configuracionDe(?int $empresaId): DianConfiguration
    {
        $configuracion = DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $empresaId)
            ->first();

        if (!$configuracion) {
            throw new RuntimeException(
                'La empresa no tiene configuración de facturación electrónica: '
                . 'sin el identificador y el PIN del software no se puede armar el XML.'
            );
        }

        return $configuracion;
    }

    /**
     * El rango del que salió el número de esta factura.
     *
     * Se busca por PREFIJO, no el rango activo sin más: una factura
     * vieja pudo salir de un rango que ya se agotó y se sustituyó por
     * otro, y su XML tiene que seguir declarando la resolución que de
     * verdad la autorizó.
     */
    private function rangoDe(Invoice $factura, ?int $empresaId): NumberingRange
    {
        $rango = NumberingRange::withoutGlobalScopes()
            ->where('company_id', $empresaId)
            ->where('prefix', $factura->prefix)
            ->where('range_start', '<=', (int) $factura->number)
            ->where('range_end', '>=', (int) $factura->number)
            ->first();

        if (!$rango) {
            throw new RuntimeException(sprintf(
                'No hay ningún rango autorizado que contenga el número %s: '
                . 'no se puede decir con qué resolución se emitió.',
                $factura->full_number,
            ));
        }

        return $rango;
    }
}

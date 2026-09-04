<?php

namespace App\Billing\Dian;

use App\Billing\Services\ElectronicInvoicingDecider;
use App\Jobs\TransmitElectronicDocument;
use App\Models\Branch;
use App\Models\CreditDebitNote;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\ElectronicDocument;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Convierte una nota ya emitida en su documento electrónico.
 *
 * ES EL HERMANO DE ElectronicDocumentGenerator
 * --------------------------------------------
 * Misma forma y mismas reglas —no tumba la emisión, se anota el fallo,
 * es idempotente— con dos diferencias que vienen del documento:
 *
 * 1. **Necesita el CUFE de la factura que corrige.** Va dentro del
 *    `BillingReference` de la nota y es lo que la ata a su documento.
 *    Si la factura no tiene documento electrónico, la nota no se puede
 *    armar: no hay a qué apuntar.
 *
 * 2. **No busca rango de numeración.** Una nota no sale de un rango
 *    autorizado —lo dice el anexo §12.1 y se ve en sus ejemplos, que no
 *    llevan `InvoiceControl`—. Su número es el que le puso el
 *    facturador.
 *
 * NO TUMBA LA EMISIÓN
 * -------------------
 * La nota **ya está emitida** y ya ajustó el saldo de la factura. Que no
 * se pueda armar su XML no puede deshacer eso: el fallo se atrapa y se
 * anota en `last_error`.
 */
class NoteDocumentGenerator
{
    public function __construct(
        private readonly NoteXmlBuilder $constructor = new NoteXmlBuilder(),
        private readonly XadesSigner $firmador = new XadesSigner(),
    ) {
    }

    /**
     * Genera —o regenera— el documento electrónico de una nota.
     *
     * Devuelve null si la nota es interna, que es la mayoría.
     */
    public function generar(CreditDebitNote $nota): ?ElectronicDocument
    {
        if ($nota->document_kind !== ElectronicInvoicingDecider::ELECTRONICO) {
            return null;
        }

        $empresaId = $this->empresaDe($nota);
        $configuracion = null;

        try {
            $configuracion = $this->configuracionDe($empresaId);
            $cufe = $this->cufeDeLaFactura($nota);

            $resultado = $this->constructor->construir($nota, $configuracion, $cufe);

            $certificado = $this->certificadoDe($empresaId);
            $xml = $resultado['xml'];
            $firmado = false;

            if ($certificado) {
                $xml = $this->firmador->firmar($xml, $this->contenidoDel($certificado), (string) $certificado->password);
                $firmado = true;
            }

            $documento = $this->guardar($nota, $empresaId, $configuracion, [
                'cufe' => $resultado['cude'],
                'signed_xml' => $xml,
                'qr_content' => $resultado['qr'],
                'dian_certificate_id' => $certificado?->id,
                'status' => $firmado ? ElectronicDocument::FIRMADO : ElectronicDocument::GENERADO,
                'signed_at' => $firmado ? now() : null,
                'last_error' => null,
                'generated_at' => now(),
            ]);

            if ($firmado && filled(config('dian.endpoint'))) {
                TransmitElectronicDocument::dispatch($documento->id);
            }

            return $documento;
        } catch (Throwable $error) {
            // La nota YA esta emitida y ya ajusto el saldo de la
            // factura. Esto no puede deshacer nada: se anota y se sigue.
            Log::error('No se pudo generar el documento electrónico de la nota', [
                'nota' => $nota->full_number,
                'nota_id' => $nota->id,
                'motivo' => $error->getMessage(),
            ]);

            return $this->guardar($nota, $empresaId, $configuracion, [
                'status' => ElectronicDocument::BORRADOR,
                'last_error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * El CUFE de la factura que la nota corrige.
     *
     * Sin él la nota no se puede armar: el `BillingReference` es lo que
     * la ata a su documento, y una nota huérfana la rechaza la DIAN.
     */
    private function cufeDeLaFactura(CreditDebitNote $nota): string
    {
        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $nota->invoice_id)
            ->first();

        if (!$documento || blank($documento->cufe)) {
            throw new RuntimeException(sprintf(
                'La factura %s no tiene documento electrónico: sin su CUFE no se puede referenciar desde la nota.',
                $nota->invoice?->full_number ?? $nota->invoice_id,
            ));
        }

        return (string) $documento->cufe;
    }

    /** @param array<string, mixed> $datos */
    private function guardar(
        CreditDebitNote $nota,
        ?int $empresaId,
        ?DianConfiguration $configuracion,
        array $datos,
    ): ElectronicDocument {
        return ElectronicDocument::withoutGlobalScopes()->updateOrCreate(
            ['credit_debit_note_id' => $nota->id],
            array_merge([
                'company_id' => $empresaId,
                // El ambiente se congela, igual que en la factura.
                'environment_code' => $configuracion?->environment_code,
            ], $datos),
        );
    }

    private function empresaDe(CreditDebitNote $nota): ?int
    {
        return Branch::withoutGlobalScopes()->whereKey($nota->branch_id)->value('company_id');
    }

    private function configuracionDe(?int $empresaId): DianConfiguration
    {
        $configuracion = DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $empresaId)
            ->with('company.taxResponsibilities')
            ->first();

        if (!$configuracion) {
            throw new RuntimeException(
                'La empresa no tiene configuración de facturación electrónica: '
                . 'sin el identificador y el PIN del software no se puede armar el XML.'
            );
        }

        return $configuracion;
    }

    private function certificadoDe(?int $empresaId): ?DianCertificate
    {
        return DianCertificate::query()
            ->where('company_id', $empresaId)
            ->where('active', true)
            ->get()
            ->first(fn (DianCertificate $certificado) => $certificado->vigente());
    }

    private function contenidoDel(DianCertificate $certificado): string
    {
        $ruta = is_file($certificado->path)
            ? $certificado->path
            : storage_path('app/' . ltrim($certificado->path, '/'));

        if (!is_file($ruta)) {
            throw new RuntimeException(sprintf(
                'El certificado «%s» no está en su ruta: no se puede firmar la nota.',
                $certificado->name,
            ));
        }

        return (string) file_get_contents($ruta);
    }
}

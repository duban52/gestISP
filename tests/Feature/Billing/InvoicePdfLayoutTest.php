<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\GraphicRepresentation;
use App\Billing\Delivery\InvoicePdf;
use App\Models\Invoice;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * La representación gráfica: que quepa en UNA hoja, y que el QR
 * distinga una factura electrónica de una interna.
 *
 * DE DÓNDE SALE ESTA PRUEBA
 * -------------------------
 * De un defecto real: en las facturas electrónicas el QR se iba a una
 * segunda página, él solo. El bloque DIAN se pintaba al final,
 * detrás de los costos, y no cabía en la hoja.
 *
 * Una representación gráfica de dos páginas no es un problema estético.
 * Es la que se le entrega al cliente y la que se lleva a una oficina de
 * recaudo; la segunda hoja se pierde, y con ella el QR con el que se
 * comprueba la factura ante la DIAN.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que el PDF sea de una sola página, con QR y sin él.
 * 2. Que la interna NO lleve QR ni CUFE. Es lo que la distingue: una
 *    factura interna que se parezca a una electrónica induce a error
 *    sobre su valor fiscal, y eso sí tiene consecuencias.
 */
class InvoicePdfLayoutTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Media carta: 21,6 x 14,0 cm. La misma constante que usa el controlador. */
    private const PAPEL = PdfBranding::MEDIA_CARTA;

    /**
     * Arma el PDF igual que `InvoiceController::downloadInvoicePdf()`.
     *
     * Se replica aquí en vez de llamar a la ruta porque lo que hay que
     * mirar es el LIENZO —cuántas páginas ocupa—, y eso solo lo sabe la
     * instancia de Dompdf, no los bytes descargados.
     */
    private function pdfDe(Invoice $factura)
    {
        $dian = app(GraphicRepresentation::class)->para($factura);

        $pdf = Pdf::loadView('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100000000010000008000',
            'dian' => $dian,
        ]);

        $pdf->setPaper(self::PAPEL, 'portrait');
        $pdf->getDomPDF()->set_option('isRemoteEnabled', true);
        $pdf->getDomPDF()->render();

        return $pdf;
    }

    public function test_la_factura_electronica_cabe_en_una_hoja(): void
    {
        // El defecto que motivó todo esto: el QR se iba a la segunda
        // página. Si alguien vuelve a mover el bloque DIAN al pie, aquí
        // salta.
        $factura = $this->facturaElectronica();

        $canvas = $this->pdfDe($factura)->getDomPDF()->getCanvas();

        $this->assertSame(1, $canvas->get_page_count(), 'La representación gráfica se desbordó a otra página.');
        // Media carta de verdad: la mitad exacta de una hoja carta.
        // Antes eran 419,53 pt de alto —el lado corto de un A5—, que no
        // es ningun tamano de papel: la impresora escalaba o sacaba
        // hoja de mas.
        $this->assertEqualsWithDelta(612, $canvas->get_width(), 1);
        $this->assertEqualsWithDelta(396, $canvas->get_height(), 1);
    }

    public function test_una_factura_de_tres_renglones_tambien_cabe(): void
    {
        // El limite MEDIDO: en media carta la electronica aguanta tres
        // renglones (390 pt de los 396 que hay). Un plan que empaquete
        // tres servicios produce tres, y ese caso tiene que caber.
        //
        // Con cuatro se desborda. Esta prueba fija donde esta el borde:
        // si alguien anade otro bloque al pie o sube la letra, se entera
        // aqui y no en una factura ya impresa.
        $factura = $this->facturaElectronica();

        foreach (range(1, 2) as $ignorado) {
            $copia = $factura->invoice_items->first()->replicate();
            $copia->save();
        }

        $canvas = $this->pdfDe($factura->fresh())->getDomPDF()->getCanvas();

        $this->assertSame(1, $canvas->get_page_count());
    }

    public function test_el_nombre_de_la_empresa_no_se_monta_sobre_si_mismo(): void
    {
        // EL INTERLINEADO NO PUEDE BAJAR DE 1.
        //
        // Estuvo en 0.5 y un nombre de empresa largo, al partirse en dos
        // lineas, se pintaba encima de la primera: ilegible en una
        // factura que se le entrega al cliente.
        //
        // Medio interlineado solo "funciona" mientras cada linea quepa
        // entera, y eso no se puede garantizar con nombres que escribe
        // el usuario. Se comprueba sobre el CSS porque es una invariante
        // del estilo: el solape no se ve en el numero de paginas.
        $html = view('gestisp.invoices.pdf', [
            'invoice' => $this->facturaElectronica()->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => null,
        ])->render();

        preg_match('/\.interline\s*\{[^}]*line-height:\s*([0-9.]+)/', $html, $coincidencia);

        $this->assertNotEmpty($coincidencia, 'No se encontro el interlineado de la cabecera.');
        $this->assertGreaterThanOrEqual(
            1.0,
            (float) $coincidencia[1],
            'Un interlineado menor que 1 hace que un nombre de dos lineas se solape.',
        );
    }

    public function test_la_factura_interna_tambien_cabe(): void
    {
        // Sin QR sobra sitio, pero conviene fijarlo: es la mitad de los
        // casos y comparte plantilla.
        $factura = $this->emitir($this->createBillableContract());

        $this->assertSame(1, $this->pdfDe($factura)->getDomPDF()->getCanvas()->get_page_count());
    }

    /** El HTML de la representacion grafica, sin pasar por dompdf. */
    private function htmlDe(\App\Models\Invoice $factura): string
    {
        return view('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => app(GraphicRepresentation::class)->para($factura),
        ])->render();
    }

    public function test_la_electronica_lleva_qr_y_cufe(): void
    {
        $factura = $this->facturaElectronica();
        $dian = app(GraphicRepresentation::class)->para($factura);

        $html = view('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => $dian,
        ])->render();

        $this->assertStringContainsString('Código QR de la factura electrónica', $html);
        $this->assertStringContainsString('CUFE:', $html);
        $this->assertStringContainsString('FACTURA ELECTRÓNICA DE VENTA', $html);
    }

    public function test_la_tarifa_de_iva_sale_del_documento(): void
    {
        // Decia «IVA 19%» SIEMPRE, escrito a mano en la plantilla. En
        // una factura de servicios excluidos —el caso normal de un ISP:
        // internet residencial de estratos 1 a 3— imprimia «IVA 19% ...
        // 0.00», afirmando una tarifa que no se aplico.
        $gravada = $this->emitir($this->contratoElectronico(precio: 100000, iva: 19));

        $this->assertStringContainsString('IVA 19%', $this->htmlDe($gravada));

        $excluida = $this->emitir($this->contratoElectronico(precio: 80000, iva: 0));
        $excluida->invoice_items()->update(['tax_classification' => 'excluido', 'percentage_tax' => 0]);

        $this->assertStringNotContainsString('IVA 19%', $this->htmlDe($excluida->fresh()));
    }

    public function test_la_columna_codigo_muestra_el_codigo_de_producto(): void
    {
        // Mostraba `$item->id`, el id interno del renglon: un numero que
        // no le dice nada a nadie y que cambia entre facturas del mismo
        // servicio.
        $factura = $this->facturaElectronica();
        $factura->invoice_items()->update(['product_code' => '81112200']);

        $html = $this->htmlDe($factura->fresh());

        $this->assertStringContainsString('81112200', $html);
        $this->assertStringNotContainsString(
            '<p>' . $factura->invoice_items->first()->id . '</p>',
            $html,
        );
    }

    public function test_el_codigo_de_barras_no_deja_archivos(): void
    {
        // Se escribia un PNG en `storage/app/public/barcodes/` y se
        // enlazaba por URL, lo que obligaba a que el servidor pudiera
        // pedirse a si mismo por HTTP. Desde un worker en cola —que es
        // como se le envia ahora la factura al cliente— eso es justo lo
        // que falla; y ademas dejaba un archivo por factura para
        // siempre.
        //
        // Se compara ANTES y DESPUES en vez de mirar si la carpeta
        // existe: la carpeta puede venir de archivos viejos, y entonces
        // la prueba no medira lo que dice medir.
        $disco = Storage::disk('public');
        $antes = $disco->exists('barcodes') ? $disco->files('barcodes') : [];

        $bytes = app(InvoicePdf::class)->bytes($this->facturaElectronica());

        $despues = $disco->exists('barcodes') ? $disco->files('barcodes') : [];

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($antes, $despues, 'Generar el PDF dejo archivos sueltos en storage.');
    }

    public function test_la_interna_no_lleva_qr_ni_cufe(): void
    {
        // Lo que separa un documento fiscal de uno que no lo es. Una
        // interna con QR le diría al cliente que su factura está ante
        // la DIAN cuando no lo está.
        $factura = $this->emitir($this->createBillableContract());

        $html = view('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => app(GraphicRepresentation::class)->para($factura),
        ])->render();

        $this->assertStringNotContainsString('Código QR de la factura electrónica', $html);
        $this->assertStringNotContainsString('CUFE:', $html);
        $this->assertStringNotContainsString('FACTURA ELECTRÓNICA DE VENTA', $html);
        $this->assertStringContainsString('FACTURA DE VENTA', $html);
    }
}

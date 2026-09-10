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
    private function pdfDe(Invoice $factura, ?string $logo = null)
    {
        $dian = app(GraphicRepresentation::class)->para($factura);

        $pdf = Pdf::loadView('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100000000010000008000',
            'dian' => $dian,
            'logo' => $logo,
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

    // ==================================================================
    // EL LOGO
    //
    // Esta parte faltaba desde el principio, y por eso el defecto llego
    // a una factura impresa: todas las pruebas de arriba renderizaban
    // SIN logo, asi que ninguna podia ver que un logo cuadrado se
    // llevaba la factura a otra hoja. Un caso que no se ejercita no esta
    // cubierto, aunque la prueba de al lado se llame «cabe en una hoja».
    // ==================================================================

    /**
     * Un PNG de las proporciones que se pidan, en disco.
     *
     * DENTRO DEL PROYECTO A PROPOSITO: dompdf tiene un `chroot` fijado
     * en `base_path()` y se NIEGA a leer imagenes de fuera. Escribiendo
     * en el temporal del sistema la imagen no se carga, la celda queda
     * vacia y la prueba pasa sin haber medido nada — que fue justo lo
     * que ocurrio la primera vez que se midio esto.
     */
    private function logoDe(int $ancho, int $alto): string
    {
        $carpeta = storage_path('framework/testing/logos');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 30, 30, 30));

        $ruta = $carpeta . "/logo-{$ancho}x{$alto}.png";
        imagepng($imagen, $ruta);
        imagedestroy($imagen);

        return $ruta;
    }

    /** La factura electronica en su peor caso: con QR y tres renglones. */
    private function peorCaso(): Invoice
    {
        $factura = $this->facturaElectronica();

        while ($factura->invoice_items->count() < 3) {
            $factura->invoice_items->first()->replicate()->save();
            $factura = $factura->fresh();
        }

        return $factura;
    }

    public static function logosDesproporcionados(): array
    {
        return [
            'cuadrado' => [512, 512],   // el de la captura que destapo el fallo
            'vertical' => [200, 900],   // 1:4,5
            'enorme' => [2400, 2400],
            'apaisado' => [1200, 300],
            'diminuto' => [40, 20],     // no debe agrandarse
        ];
    }

    /**
     * @dataProvider logosDesproporcionados
     */
    public function test_ningun_logo_manda_la_factura_a_una_segunda_hoja(int $ancho, int $alto): void
    {
        // «Nunca puede pasar eso». Se comprueba sobre el PEOR caso —tres
        // renglones, que ya ocupa 390 pt de los 396 de la media carta—
        // porque un logo que quepa ahi cabe en cualquier factura.
        //
        // El que fallaba era el del TALON: al lado solo tiene un codigo
        // de barras de unos 16 px, asi que es el logo el que decide la
        // altura de esa fila. Medido: cabia hasta 44 px y se partia en
        // 45; con `width="80px"` y sin alto, un logo cuadrado salia de
        // 80x80.
        $canvas = $this->pdfDe($this->peorCaso(), $this->logoDe($ancho, $alto))
            ->getDomPDF()->getCanvas();

        $this->assertSame(
            1,
            $canvas->get_page_count(),
            "Un logo de {$ancho}x{$alto} desbordo la factura a otra hoja.",
        );
    }

    public function test_el_logo_sale_siempre_con_alto_y_sin_unidades(): void
    {
        // LA REGRESION QUE HAY QUE IMPEDIR, en el marcado.
        //
        // Iba `<img width="100px">`: sin alto —lo ponia la imagen— y con
        // un sufijo que el atributo HTML ni siquiera admite. Basta que
        // alguien vuelva a escribirlo asi para repetir el fallo, y el
        // numero de paginas solo lo delata cuando la factura va justa.
        $html = view('gestisp.invoices.pdf', [
            'invoice' => $this->facturaElectronica()->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => null,
            'logo' => $this->logoDe(512, 512),
        ])->render();

        preg_match_all('/<img\s[^>]*alt="Logo"[^>]*>/', $html, $imagenes);

        $this->assertCount(2, $imagenes[0], 'La factura lleva logo en la cabecera y en el talon.');

        foreach ($imagenes[0] as $etiqueta) {
            $this->assertMatchesRegularExpression('/width="\d+"/', $etiqueta, "Sin ancho numerico: {$etiqueta}");
            $this->assertMatchesRegularExpression('/height="\d+"/', $etiqueta, "Sin alto: {$etiqueta}");
        }
    }

    public function test_la_caja_del_logo_respeta_los_dos_topes(): void
    {
        // Ni mas ancha ni mas alta que la caja, y sin deformarse: un
        // logo estirado en una factura que se le entrega al cliente se
        // ve como un descuido de la empresa que la emite.
        $caja = PdfBranding::logoBox($this->logoDe(200, 900), 100, 46);

        $this->assertNotNull($caja);
        $this->assertLessThanOrEqual(100, $caja['ancho']);
        $this->assertLessThanOrEqual(46, $caja['alto']);
        $this->assertEqualsWithDelta(200 / 900, $caja['ancho'] / $caja['alto'], 0.02);
    }

    public function test_un_logo_pequeno_no_se_estira(): void
    {
        // Agrandarlo solo lo pixela. La caja es un TOPE, no una medida.
        $caja = PdfBranding::logoBox($this->logoDe(40, 20), 100, 46);

        $this->assertSame(40, $caja['ancho']);
        $this->assertSame(20, $caja['alto']);
    }

    public function test_sin_logo_no_se_pinta_nada(): void
    {
        // Una sucursal sin logo cargado, y una ruta que ya no existe en
        // disco: las dos tienen que dar en nada, no en una imagen rota.
        $this->assertNull(PdfBranding::logoBox(null, 100, 46));
        $this->assertNull(PdfBranding::logoBox(storage_path('no/existe/logo.png'), 100, 46));
    }

    public function test_el_pdf_masivo_gasta_una_hoja_por_factura(): void
    {
        // LA MISMA PLANTILLA DE LOGO, EN EL SITIO DONDE MAS DUELE.
        //
        // `pending_invoices_pdf` imprime UNA factura por hoja para todo
        // un corte. Si el logo desborda, no se va una factura a dos
        // hojas: se van TODAS, y el documento que se manda a imprimir
        // sale del doble de grueso.
        //
        // Aqui ademas el logo se resuelve por sucursal —cada factura
        // puede ser de otra empresa— y eso es codigo distinto del de la
        // factura suelta, asi que necesita su propia comprobacion.
        $logo = $this->logoDe(512, 512);

        $facturas = collect([
            $this->facturaElectronica(),
            $this->emitir($this->createBillableContract()),
        ])->map(fn ($f) => $f->load(['contract.client', 'contract.branch', 'invoice_items']));

        $representacion = app(GraphicRepresentation::class);

        $pdf = Pdf::loadView('gestisp.invoices.pending_invoices_pdf', [
            'invoices' => $facturas,
            'barcodeUrls' => $facturas->mapWithKeys(fn ($f) => [$f->id => ''])->all(),
            'barcodeCodes' => $facturas->mapWithKeys(fn ($f) => [$f->id => '0100'])->all(),
            'dianes' => $facturas->mapWithKeys(fn ($f) => [$f->id => $representacion->para($f)])->all(),
            // Todas las sucursales del lote con el mismo logo cuadrado.
            // Todas las sucursales del lote con el mismo logo cuadrado.
            'logos' => $facturas->mapWithKeys(fn ($f) => [$f->contract?->branch?->id => $logo])->all(),
        ]);

        $pdf->setPaper(PdfBranding::MEDIA_CARTA, 'portrait');
        $pdf->getDomPDF()->set_option('isRemoteEnabled', true);
        $pdf->getDomPDF()->render();

        $this->assertSame(
            $facturas->count(),
            $pdf->getDomPDF()->getCanvas()->get_page_count(),
            'El PDF del corte gasto mas de una hoja por factura.',
        );
    }
}

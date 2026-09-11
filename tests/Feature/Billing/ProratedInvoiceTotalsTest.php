<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Enums\ProrationMode;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Que las cuentas de una factura PRORRATEADA CON IVA cuadren.
 *
 * EL RECHAZO
 * ----------
 * FAU14: «Valor a Pagar de Factura es distinto de la Suma de Valor
 * Bruto más tributos − Valor del Descuento Total + Valor del Cargo
 * Total». Aparecía solo a veces, y solo en facturas prorrateadas con
 * IVA.
 *
 * LA CAUSA
 * --------
 * No era falta de precisión: era que NADA se redondeaba en el renglón.
 * `subtotal`, `tax` y `total` se acumulaban con todos sus decimales y
 * las columnas —`decimal(15,2)`— redondeaban cada una por su cuenta. La
 * suma de redondeos no es el redondeo de la suma, así que se desajustaba
 * un centavo.
 *
 * Solo se notaba con prorrateo porque es el único caso en que la base
 * deja de ser un número redondo: 80.000 × 18/31 no cabe en dos
 * decimales.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Las tres igualdades que mira la DIAN, sobre el XML de verdad y no
 * sobre aritmética suelta:
 *
 *   1. suma de LineExtensionAmount de las líneas = Valor Bruto
 *   2. suma de los IVA de las líneas             = tributos
 *   3. Valor Bruto + tributos − descuentos       = Valor a Pagar  (FAU14)
 *
 * Y la de FAS07: el IVA de cada línea es su base declarada por la
 * tarifa.
 */
class ProratedInvoiceTotalsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /**
     * Combinaciones que ROMPÍAN la ecuación antes del arreglo.
     *
     * No están elegidas a ojo: salieron de barrer los precios reales
     * contra todos los días prorrateables de meses de 28, 29, 30 y 31
     * días. De unas 2.000 combinaciones fallaba el 8%; estas son las
     * primeras de esa lista.
     *
     * @return array<string, array{float, int, int}> precio, día de alta, mes
     */
    public static function combinacionesQueFallaban(): array
    {
        return [
            '55.000 el 29 de febrero' => [55000, 29, 2],
            '45.000 el 28 de febrero' => [45000, 28, 2],
            '120.000 el 28 de febrero' => [120000, 28, 2],
            '80.000 el 27 de febrero' => [80000, 27, 2],
            '70.000 el 26 de febrero' => [70000, 26, 2],
            '99.900 el 29 de febrero' => [99900, 29, 2],
            '60.000 el 26 de febrero' => [60000, 26, 2],
            // Meses de 31 dias tambien fallaban: no era cosa de febrero.
            '80.000 el 29 de marzo' => [80000, 29, 3],
            '120.000 el 30 de marzo' => [120000, 30, 3],
        ];
    }

    /**
     * @dataProvider combinacionesQueFallaban
     */
    public function test_las_cuentas_cuadran_en_una_factura_prorrateada(float $precio, int $dia, int $mes): void
    {
        $factura = $this->facturaProrrateada($precio, $dia, $mes);

        // Que de verdad haya prorrateo: si el multiplicador fuera 1 la
        // prueba pasaría sin medir lo que dice medir.
        $this->assertLessThan(
            $precio,
            (float) $factura->subtotal,
            'No hubo prorrateo: la factura salió por el precio completo.',
        );

        $this->comprobarLasCuatroIgualdades($factura);
    }

    public function test_tambien_cuadran_sin_prorrateo(): void
    {
        // El caso que siempre funcionó. Se fija para que el arreglo del
        // prorrateo no lo rompa por el otro lado.
        $factura = $this->emitir($this->contratoElectronico(precio: 80000, iva: 19));

        $this->comprobarLasCuatroIgualdades($factura);
    }

    public function test_tambien_cuadran_sin_iva(): void
    {
        // Servicios excluidos, el caso normal de un ISP residencial.
        $factura = $this->facturaProrrateada(80000, 18, 3, iva: 0);

        $this->comprobarLasCuatroIgualdades($factura);
    }

    public function test_el_iva_de_cada_renglon_es_su_base_por_la_tarifa(): void
    {
        // FAS07. Con la base sin redondear, el IVA declarado no era
        // exactamente el producto de la base declarada por la tarifa.
        $factura = $this->facturaProrrateada(55000, 29, 2);

        foreach ($factura->invoice_items as $item) {
            if ((float) $item->percentage_tax <= 0) {
                continue;
            }

            $base = round((float) $item->unit_price * (float) $item->quantity, 2);

            $this->assertEqualsWithDelta(
                round($base * (float) $item->percentage_tax / 100, 2),
                (float) $item->tax,
                0.001,
                'El IVA del renglón no es su base por la tarifa.',
            );
        }
    }

    // ==================== Apoyo ====================

    /** Una factura del primer mes de un contrato: la que se prorratea. */
    private function facturaProrrateada(float $precio, int $dia, int $mes, float $iva = 19): Invoice
    {
        BranchBillingSetting::updateOrCreate(
            ['branch_id' => $this->branch->id],
            ['proration_mode' => ProrationMode::Prorated->value],
        );

        $contrato = $this->contratoElectronico(precio: $precio, iva: $iva);

        // Año bisiesto a propósito: febrero de 29 días es donde más
        // decimales periódicos salen.
        $alta = Carbon::create(2024, $mes, $dia);

        $contrato->update(['activation_date' => $alta->toDateString()]);

        $resultado = app(\App\Billing\Services\InvoiceGenerator::class)->generateForContract(
            $contrato->fresh(),
            $alta->copy(),
            $this->admin->id,
        );

        $this->assertTrue($resultado['generated'], 'No se generó la factura.');

        return $resultado['invoice']->load('invoice_items');
    }

    /**
     * Las igualdades, comprobadas SOBRE EL XML.
     *
     * Sobre el XML y no sobre las columnas porque es lo que la DIAN
     * recibe y lo que evalúa. Comprobarlo en la base de datos dejaría
     * fuera cualquier error de formateo al escribirlo.
     */
    private function comprobarLasCuatroIgualdades(Invoice $factura): void
    {
        $xml = app(InvoiceXmlBuilder::class)->construir(
            $factura->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        )['xml'];

        $xpath = $this->xpath($xml);

        $leer = fn (string $ruta) => (float) ($xpath->query($ruta)->item(0)?->nodeValue ?? 0);

        $bruto = $leer('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
        $tributos = $leer('//cac:TaxTotal/cbc:TaxAmount');
        $descuento = $leer('//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount');
        $cargos = $leer('//cac:LegalMonetaryTotal/cbc:ChargeTotalAmount');
        $aPagar = $leer('//cac:LegalMonetaryTotal/cbc:PayableAmount');

        // ---- 1. Las líneas suman el valor bruto ----
        $sumaLineas = 0.0;
        foreach ($xpath->query('//cac:InvoiceLine/cbc:LineExtensionAmount') as $nodo) {
            $sumaLineas += (float) $nodo->nodeValue;
        }

        $this->assertEqualsWithDelta(
            $sumaLineas,
            $bruto,
            0.001,
            'La suma de las líneas no da el Valor Bruto declarado.',
        );

        // ---- 2. Los IVA de las líneas suman los tributos ----
        $sumaIva = 0.0;
        foreach ($xpath->query('//cac:InvoiceLine/cac:TaxTotal/cbc:TaxAmount') as $nodo) {
            $sumaIva += (float) $nodo->nodeValue;
        }

        $this->assertEqualsWithDelta(
            $sumaIva,
            $tributos,
            0.001,
            'La suma de los IVA de las líneas no da los tributos declarados.',
        );

        // ---- 3. FAU14 ----
        $this->assertEqualsWithDelta(
            $bruto + $tributos - $descuento + $cargos,
            $aPagar,
            0.001,
            sprintf(
                'FAU14: %.2f + %.2f - %.2f + %.2f = %.2f, pero el Valor a Pagar dice %.2f.',
                $bruto,
                $tributos,
                $descuento,
                $cargos,
                $bruto + $tributos - $descuento + $cargos,
                $aPagar,
            ),
        );

        // ---- 4. Nada con más de dos decimales ----
        // Un importe con tres decimales pasa las sumas y lo rechazan
        // igual: el peso colombiano no los tiene.
        foreach ($xpath->query('//*[contains(local-name(), "Amount")]') as $nodo) {
            $this->assertMatchesRegularExpression(
                '/^-?\d+\.\d{2}$/',
                $nodo->nodeValue,
                "El importe {$nodo->nodeName} no tiene exactamente dos decimales: {$nodo->nodeValue}",
            );
        }
    }
}

<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Concerns\ArmaCorreo;
use App\Notifications\Concerns\RespetaCanales;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente de que su factura se venció.
 */
class InvoiceOverdue extends Notification implements ShouldQueue
{
    use Queueable;
    use ArmaCorreo;
    use RespetaCanales;

    public function __construct(private readonly Invoice $invoice)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vencio = optional($this->invoice->due_date)->format('d/m/Y');

        return $this->correo(
            'Factura vencida ' . $this->invoice->displayNumber(),
            [
                'titulo' => 'Su factura está vencida',
                'preheader' => 'Saldo pendiente: ' . $this->pesos($this->invoice->pending_invoice_amount),
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Su factura superó la fecha límite de pago y figura como vencida en nuestro sistema.',
                ],
                'destacado' => [
                    'etiqueta' => 'Saldo pendiente',
                    'valor' => $this->pesos($this->invoice->pending_invoice_amount),
                    'nota' => $vencio ? 'Venció el ' . $vencio : null,
                ],
                'datos' => array_filter([
                    'Número de factura' => $this->invoice->displayNumber(),
                    'Período facturado' => $this->invoice->billed_month_name,
                    'Fecha de vencimiento' => $vencio,
                ]),
                'aviso' => [
                    'tipo' => 'alerta',
                    'texto' => 'Le pedimos ponerse al día para mantener activo su servicio. Si ya realizó el pago en los últimos días, por favor ignore este mensaje.',
                ],
                'cierre' => 'Si tiene alguna dificultad para pagar, comuníquese con nosotros: buscamos la manera de ayudarle.',
            ],
            $this->invoice->branch,
            'alerta',
        );
    }
//Definicion de plantilla
    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->pending_invoice_amount, 0, ',', '.');

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su factura {$this->invoice->displayNumber()} está VENCIDA. Saldo: {$total}. Póngase al día para no interrumpir su servicio. Estamos para ayudarle."
        )->template('factura_vencida', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
        ]);
    }
}

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
 * Recordatorio al cliente unos días antes de que venza su factura.
 */
class InvoiceDueSoon extends Notification implements ShouldQueue
{
    use Queueable;
    use ArmaCorreo;
    use RespetaCanales;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly int $diasRestantes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vence = optional($this->invoice->due_date)->format('d/m/Y');
        $dias = $this->diasRestantes;

        return $this->correo(
            'Su factura vence ' . ($dias === 1 ? 'mañana' : 'en ' . $dias . ' días'),
            [
                'titulo' => 'Recordatorio de pago',
                'preheader' => 'Su factura ' . $this->invoice->displayNumber() . ' vence pronto.',
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    $dias === 1
                        ? 'Le recordamos que su factura vence mañana.'
                        : 'Le recordamos que su factura vence en ' . $dias . ' días.',
                ],
                'destacado' => [
                    'etiqueta' => 'Saldo pendiente',
                    'valor' => $this->pesos($this->invoice->pending_invoice_amount),
                    'nota' => $vence ? 'Vence el ' . $vence : null,
                ],
                'datos' => array_filter([
                    'Número de factura' => $this->invoice->displayNumber(),
                    'Período facturado' => $this->invoice->billed_month_name,
                    'Fecha de vencimiento' => $vence,
                ]),
                'aviso' => [
                    'tipo' => 'info',
                    'texto' => 'Pagando antes de la fecha límite evita la suspensión del servicio. Si ya realizó el pago, ignore este mensaje.',
                ],
            ],
            $this->invoice->branch,
            'aviso',
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->pending_invoice_amount, 0, ',', '.');
        $vence = optional($this->invoice->due_date)->format('d/m/Y') ?? 'pronto';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su factura {$this->invoice->displayNumber()} vence en {$this->diasRestantes} día(s) ({$vence}). Saldo: {$total}. Pague a tiempo para no interrumpir su servicio. 🙌"
        )->template('factura_por_vencer', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            (string) $this->diasRestantes,
            $total,
        ]);
    }
}

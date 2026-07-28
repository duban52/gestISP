<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashRegisterTransaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'cash_register_id',
        'payment_id',
        'transaction_type',
        'amount',
        'payment_method',
        'description',
        'created_by',
    ];

    /**
     * The relationships to always load.
     *
     * @var array
     */
    protected $with = ['cashRegister', 'payment', 'user'];

    /**
     * Get the cash register associated with the transaction.
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * Get the payment associated with the transaction.
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the user who created the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A quién corresponde el movimiento: número de contrato,
     * identificación y nombre del cliente.
     *
     * Se arma desde las relaciones y NO desde la columna description,
     * a propósito: esa columna guarda el texto tal como se escribió el
     * día del cobro ("Pago de factura 18") y no se puede reescribir
     * hacia atrás. Derivándolo aquí, los movimientos viejos salen tan
     * completos como los nuevos.
     *
     * Devuelve null cuando el movimiento no viene de un cobro (un
     * egreso manual, por ejemplo).
     */
    public function detalleDelCliente(): ?string
    {
        $pago = $this->payment;

        if (!$pago) {
            return null;
        }

        // El contrato llega por la factura o, en los anticipos, del
        // pago directamente.
        $contrato = $pago->invoice?->contract ?? $pago->contract;

        if (!$contrato) {
            return null;
        }

        $cliente = $contrato->client;

        return collect([
            'Contrato ' . $contrato->numero_visible,
            $cliente ? trim(($cliente->type_document ?: 'CC') . ' ' . $cliente->identity_number) : null,
            $cliente ? trim($cliente->name . ' ' . $cliente->last_name) : null,
        ])->filter()->implode(' · ');
    }

    /**
     * Descripción del movimiento con el número de factura formal.
     *
     * Los movimientos antiguos guardaron el id de la factura en vez de
     * su número ("Pago de factura 18"); si la factura se puede
     * resolver, se rehace la frase con displayNumber().
     */
    public function descripcionLegible(): string
    {
        $factura = $this->payment?->invoice;

        if ($factura) {
            return 'Pago de factura ' . $factura->displayNumber();
        }

        if ($this->payment && $this->payment->type === 'anticipo') {
            return 'Anticipo a cuenta';
        }

        return (string) $this->description;
    }
}

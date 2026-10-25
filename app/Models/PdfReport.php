<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una generación del PDF masivo de facturas pendientes.
 *
 * Es un REGISTRO DE PROCESO, no solo la ruta de un archivo. La
 * diferencia importa: la pantalla pregunta por ESTA generación —la
 * que acaba de pedir— y no por «el último PDF que haya de esta
 * sucursal», que es lo que hacía antes y lo que hacía aparecer el
 * documento del mes pasado mientras el nuevo seguía en la cola.
 */
class PdfReport extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /** Pedido, todavía sin empezar: el trabajo está en la cola. */
    public const EN_COLA = 'en_cola';

    /** Trabajando. `procesadas` de `total` va subiendo. */
    public const GENERANDO = 'generando';

    /** Terminado: `pdf_path` tiene el archivo. */
    public const LISTO = 'listo';

    /** Se rompió. `error` dice por qué. */
    public const FALLIDO = 'fallido';

    protected $fillable = [
        'branch_id',
        'user_id',
        'pdf_path',
        'status',
        'total',
        'procesadas',
        'error',
        'finished_at',
    ];

    protected $casts = [
        'total' => 'integer',
        'procesadas' => 'integer',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function terminado(): bool
    {
        return in_array($this->status, [self::LISTO, self::FALLIDO], true);
    }

    /**
     * Lo que lleva hecho, en porcentaje.
     *
     * Mientras no se sabe cuántas facturas hay (el trabajo aún no ha
     * arrancado) devuelve 0 en vez de dividir por cero. Y un PDF
     * terminado es 100 aunque no hubiera ni una factura que procesar:
     * una barra parada en 0 sobre un trabajo acabado se lee como que
     * se colgó.
     */
    public function porcentaje(): int
    {
        if ($this->status === self::LISTO) {
            return 100;
        }

        if ($this->total < 1) {
            return 0;
        }

        return (int) min(99, round($this->procesadas / $this->total * 100));
    }

    /** Lo que se le enseña a quien está esperando. */
    public function enClaro(): string
    {
        return match ($this->status) {
            self::EN_COLA => 'En cola: el PDF empezará a generarse enseguida.',
            self::GENERANDO => $this->total > 0
                ? sprintf('Preparando %s de %s facturas…', number_format($this->procesadas, 0, ',', '.'), number_format($this->total, 0, ',', '.'))
                : 'Buscando las facturas pendientes…',
            self::LISTO => sprintf('Listo: %s facturas.', number_format($this->total, 0, ',', '.')),
            self::FALLIDO => 'No se pudo generar: ' . ($this->error ?: 'error desconocido'),
            default => $this->status,
        };
    }
}

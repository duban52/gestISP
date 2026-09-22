<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una tanda de corte masivo por mora (ver ContractMassCutoff).
 */
class ContractCutoff extends Model
{
    use BelongsToCompany;

    protected $fillable = ['branch_id', 'user_id', 'reason', 'source', 'threshold'];

    public function items(): HasMany
    {
        return $this->hasMany(ContractCutoffItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** ¿Queda algo en la cola? Mientras tanto la pantalla se refresca sola. */
    public function enCurso(): bool
    {
        return $this->items()->where('status', ContractCutoffItem::PENDIENTE)->exists();
    }
}

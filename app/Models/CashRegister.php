<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashRegister extends Model
{
    use BelongsToCompany;

    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'user_id', 'initial_amount', 'final_amount', 'total_income',
        'total_expenses', 'expected_amount', 'difference',
        'opening_notes', 'closing_notes', 'opened_at', 'closed_at', 'status'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime'
    ];

    public function transactions()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function calculateTotals()
    {
        $this->total_income = $this->transactions()
            ->where('transaction_type', 'Ingreso')
            ->sum('amount');

        $this->total_expenses = $this->transactions()
            ->where('transaction_type', 'Egreso')
            ->sum('amount');

        $this->expected_amount = $this->initial_amount + $this->total_income - $this->total_expenses;
        $this->difference = $this->final_amount - $this->expected_amount;

        $this->save();
    }
}

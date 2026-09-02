<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PdfReport extends Model
{
    use BelongsToCompany;

    use HasFactory;
    protected $fillable = ['branch_id', 'pdf_path'];
}

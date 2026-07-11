<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'ip_address',
        'api_port',
        'username',
        'password',
        'brand',
        'model',
        'status',
        'active',
        'version',
        'board_name',
        'uptime',
    ];

    protected $casts = [
        'status'   => 'boolean',
        'active'   => 'boolean',
        'api_port' => 'integer',
    ];

    protected $hidden = ['password'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function pppoeAccounts()
    {
        return $this->hasMany(PppoeAccount::class);
    }

    public function getPlainPassword(): string
    {
        return $this->password;
    }

    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}

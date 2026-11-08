<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'package_name',
        'price_cents',
        'currency',
        'status',
        'assigned_to_phone',
        'assigned_at',
        'used_at',
        'used_by_phone',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'used_at' => 'datetime',
        'price_cents' => 'integer',
    ];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    public function scopeForPackage(Builder $query, string $packageName): Builder
    {
        return $query->where('package_name', $packageName);
    }

    public function paymentOrder(): HasOne
    {
        return $this->hasOne(PaymentOrder::class);
    }

    public function assignTo(string $phone): void
    {
        if ($this->status === 'used') {
            throw new \RuntimeException('This voucher has already been used and cannot be reassigned.');
        }

        if ($this->assigned_to_phone && $this->assigned_to_phone !== $phone) {
            throw new \RuntimeException('This voucher has already been assigned to another phone number.');
        }

        $this->status = 'assigned';
        $this->assigned_to_phone = $phone;
        $this->assigned_at = now();

        $this->save();
    }

    public function redeemFor(string $phone): void
    {
        if ($this->status === 'used') {
            throw new \RuntimeException('This voucher has already been used and cannot be used again.');
        }

        if ($this->assigned_to_phone !== $phone) {
            throw new \RuntimeException('This voucher is assigned to a different phone number.');
        }

        $this->status = 'used';
        $this->used_at = now();
        $this->used_by_phone = $phone;

        $this->save();
    }
}

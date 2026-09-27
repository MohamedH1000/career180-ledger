<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'initiated_at',
        'trigger',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'initiated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }
}

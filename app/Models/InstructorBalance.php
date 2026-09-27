<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'paid_cents',
        'pending_cents',
        'cached_earned_cents',
        'cached_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_cents' => 'integer',
            'pending_cents' => 'integer',
            'cached_earned_cents' => 'integer',
            'cached_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}

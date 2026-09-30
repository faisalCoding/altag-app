<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the return checklist — read by the supervisor as a condition of
 * booking, and ticked by the officer when the bus comes back.
 */
#[Fillable(['label', 'position', 'is_active'])]
class BusHandoverItem extends Model
{
    protected $casts = [
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }
}

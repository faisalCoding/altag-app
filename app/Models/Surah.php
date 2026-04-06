<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Surah extends Model
{
    protected $fillable = [
        'id',
        'number',
        'name_arabic',
        'name_simple',
        'revelation_place',
        'revelation_order',
        'verses_count',
        'start_page',
        'end_page',
    ];

    public function ayahs()
    {
        return $this->hasMany(Ayah::class);
    }
}

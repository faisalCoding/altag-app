<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ayah extends Model
{
    protected $fillable = [
        'id',
        'surah_id',
        'verse_number',
        'verse_key',
        'juz_number',
        'hizb_number',
        'rub_number',
        'page_number',
        'ruku_number',
        'manzil_number',
        'sajdah_type',
        'text_uthmani',
        'line_number',
    ];

    public function surah()
    {
        return $this->belongsTo(Surah::class);
    }
}

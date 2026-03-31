<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasProfile
{
    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::upper(mb_substr($this->name, 0, 2));
    }
}

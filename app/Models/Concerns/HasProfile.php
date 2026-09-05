<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;
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

    /**
     * The first two parts of the user's name, for narrow layouts where the full
     * name would not fit. Falls back to whatever parts exist.
     *
     * @return list<string>
     */
    public function shortNameParts(): array
    {
        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($parts, 0, 2);
    }

    /**
     * Get the public URL of the user's profile picture, or null if none is set.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * Get unique avatar inline style (background and text color).
     */
    public function avatarStyle(): string
    {
        $hue = ($this->id * 137) % 360;

        return "background-color: hsl({$hue}, 75%, 93%); color: hsl({$hue}, 80%, 28%);";
    }
}

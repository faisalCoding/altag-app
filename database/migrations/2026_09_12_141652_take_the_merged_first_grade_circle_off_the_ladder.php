<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "أولى و ثاني ابتدائي" is one circle holding two grades, so no single rank
 * describes it: promoting on its rank would send every one of its students to
 * third grade, and half of them have not finished first.
 *
 * It comes off the ladder until it is split in two. Its students then appear in
 * a run as staying put, for the manager to place by hand.
 */
return new class extends Migration
{
    private const MERGED_CIRCLE = 'أولى و ثاني ابتدائي';

    public function up(): void
    {
        $this->circle()?->update(['level' => null]);
    }

    public function down(): void
    {
        $this->circle()?->update(['level' => 1]);
    }

    private function circle(): ?object
    {
        $row = DB::table('circles')->get()
            ->first(fn ($circle) => $this->normalise($circle->name) === $this->normalise(self::MERGED_CIRCLE));

        return $row ? DB::table('circles')->where('id', $row->id) : null;
    }

    /**
     * Names here carry invisible bidi marks and stray spaces.
     */
    private function normalise(string $name): string
    {
        return trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', $name));
    }
};

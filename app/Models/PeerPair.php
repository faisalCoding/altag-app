<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two students of a circle reciting to each other on a day — or, when not
 * mutual, the first reciting to the second. See the peer_pairs migration.
 */
class PeerPair extends Model
{
    public const PLACES = ['first', 'second'];

    protected $fillable = [
        'circle_id',
        'date',
        'position',
        'mutual',
        'first_id',
        'first_from_ayah_id',
        'first_to_ayah_id',
        'first_mistakes',
        'first_ready',
        'second_id',
        'second_from_ayah_id',
        'second_to_ayah_id',
        'second_mistakes',
        'second_ready',
        'created_by',
    ];

    protected $casts = [
        'mutual' => 'boolean',
        'position' => 'integer',
        'first_mistakes' => 'integer',
        'second_mistakes' => 'integer',
        'first_ready' => 'boolean',
        'second_ready' => 'boolean',
    ];

    /** @return BelongsTo<Circle, $this> */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function first(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'first_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function second(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'second_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function firstFromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'first_from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function firstToAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'first_to_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function secondFromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'second_from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function secondToAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'second_to_ayah_id');
    }

    /** The place a student holds in the pair, or null when they are not in it. */
    public function placeOf(int $studentId): ?string
    {
        return match ($studentId) {
            $this->first_id => 'first',
            $this->second_id => 'second',
            default => null,
        };
    }

    /** Whether the student in a place recites: both do in a mutual pair, else the first alone. */
    public function recites(string $place): bool
    {
        return $place === 'first' || $this->mutual;
    }
}

<?php

namespace App\Models;

use App\Services\GamificationService;
use App\Support\RecitationGrade;
use Database\Factories\FreeRecitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recitation by a student who has no plan: what they recited for hifz or
 * review on a day, and how it was graded. It earns points exactly like a
 * graded plan day.
 */
class FreeRecitation extends Model
{
    /** @use HasFactory<FreeRecitationFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'recorded_by',
        'type',
        'recited_on',
        'from_ayah_id',
        'to_ayah_id',
        'achievement',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'recited_on' => 'date',
            'graded_at' => 'datetime',
            'achievement' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Points earned by a recitation go with it.
        static::deleting(function (FreeRecitation $recitation) {
            GamificationService::clearTransactionsForReference(self::class, $recitation->id);
        });
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function fromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function toAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'to_ayah_id');
    }

    public function isRecited(): bool
    {
        return RecitationGrade::isRecited($this->achievement);
    }

    /**
     * Recitations that were heard and graded.
     *
     * @param  Builder<FreeRecitation>  $query
     */
    public function scopeRecited(Builder $query): void
    {
        $query->where('achievement', '>=', 1);
    }

    public function formatRange(): ?string
    {
        return StudentPlanDay::formatAyahRange($this->fromAyah, $this->toAyah);
    }
}

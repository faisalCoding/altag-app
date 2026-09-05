<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    protected $fillable = [
        'supervisor_id',
        'title',
        'description',
        'header_image_path',
        'color',
        'slug',
        'fields',
        'policy_text',
        'success_text',
        'is_public_report',
        'is_supervisor_shared',
        'public_report_token',
        'audience',
        'status',
        'published_at',
        'due_date',
        'is_blocking',
        'created_by_id',
        'created_by_type',
    ];

    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            if (empty($form->public_report_token)) {
                $form->public_report_token = Str::random(12);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_public_report' => 'boolean',
            'is_supervisor_shared' => 'boolean',
            'audience' => 'array',
            'published_at' => 'datetime',
            'due_date' => 'date',
            'is_blocking' => 'boolean',
        ];
    }

    /**
     * Whoever made this form — a manager, a supervisor or a teacher.
     *
     * @return MorphTo<Model, $this>
     */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /** @return BelongsTo<Supervisor, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Supervisor::class);
    }

    /** @return HasMany<FormResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    /** @return HasMany<FormAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(FormAssignment::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Whether this form still holds the app shut for whoever owes it: only a
     * published, blocking form that has not closed, and only before its due date
     * passes — a survey nobody closed must not lock the academy out forever.
     */
    public function blocksTheApp(): bool
    {
        if (! $this->is_blocking || ! $this->isPublished()) {
            return false;
        }

        return $this->due_date === null || ! $this->due_date->isPast();
    }

    /**
     * How far the asked-of audience has got, for the results screen.
     *
     * @return array{assigned: int, completed: int, rate: int}
     */
    public function completion(): array
    {
        $assigned = $this->assignments()->count();
        $completed = $this->assignments()->where('status', 'completed')->count();

        return [
            'assigned' => $assigned,
            'completed' => $completed,
            'rate' => $assigned > 0 ? (int) round($completed / $assigned * 100) : 0,
        ];
    }
}

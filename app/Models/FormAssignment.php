<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form asked of one person.
 *
 * Rows are created from the form's audience rule and only ever move from
 * pending to completed; nothing is deleted when someone answers, so the trail
 * of who was asked survives.
 */
class FormAssignment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'form_id',
        'user_id',
        'role',
        'status',
        'due_date',
        'completed_at',
        'form_response_id',
        'notified_at',
        'reminded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FormResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * The assignments a person still owes, newest obligation last so the oldest
     * is asked of them first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOwedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->where('status', 'pending')
            ->whereHas('form', fn ($q) => $q->where('status', 'published'))
            ->orderBy('due_date')
            ->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending'
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function markCompleted(?int $responseId = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'form_response_id' => $responseId,
        ]);
    }
}

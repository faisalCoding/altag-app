<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The rules as they were published, frozen: later edits to the draft make a
 * new version and never touch this one.
 *
 * `content` holds the sections in order, each with its clauses:
 * [{title, clauses: [{audience, body}]}].
 */
class RuleVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['number', 'content', 'requires_acknowledgement', 'notes', 'published_by', 'published_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'requires_acknowledgement' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<RuleAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(RuleAcknowledgement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}

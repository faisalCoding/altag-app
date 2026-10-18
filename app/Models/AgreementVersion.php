<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreement as it was published, frozen: what its signers read.
 * `requires_acknowledgement` false marks a wording fix, which leaves the
 * signatures of earlier versions standing.
 */
class AgreementVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['agreement_id', 'number', 'title', 'type', 'audience', 'clauses', 'requires_acknowledgement', 'published_by', 'published_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'clauses' => 'array',
            'requires_acknowledgement' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's word that they read a version of the rules and will keep them.
 *
 * One per person and version, covering every role they held when they gave
 * it; `content_hash` is of the very text they were shown.
 */
class RuleAcknowledgement extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'rule_version_id', 'roles', 'content_hash', 'ip', 'user_agent', 'acknowledged_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RuleVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RuleVersion::class, 'rule_version_id');
    }
}

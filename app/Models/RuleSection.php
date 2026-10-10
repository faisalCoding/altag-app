<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A heading of the circles' rules as the manager is drafting them, holding
 * its clauses in order.
 */
class RuleSection extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'position'];

    /** @return HasMany<RuleClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(RuleClause::class)->orderBy('position')->orderBy('id');
    }
}

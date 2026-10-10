<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule of the draft, meant for everyone, for teachers or for supervisors.
 */
class RuleClause extends Model
{
    /** Whom a clause speaks to. */
    public const AUDIENCES = [
        'shared' => 'مشترك',
        'teacher' => 'للمعلمين',
        'supervisor' => 'للمشرفين',
    ];

    /** @var list<string> */
    protected $fillable = ['rule_section_id', 'audience', 'body', 'position'];

    /** @return BelongsTo<RuleSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(RuleSection::class, 'rule_section_id');
    }
}

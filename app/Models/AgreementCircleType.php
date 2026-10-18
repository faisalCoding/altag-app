<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The kind a teacher calls an agreement by for their own circle, in place of
 * the supervisor's.
 */
class AgreementCircleType extends Model
{
    /** @var list<string> */
    protected $fillable = ['agreement_id', 'circle_id', 'type', 'set_by'];
}

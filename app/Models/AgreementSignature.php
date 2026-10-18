<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's — or their guardian's — word on a version of an agreement.
 *
 * `party` says who gave it, `signer_name` the name they gave, `type` the
 * kind it was called by for their circle, and `content_hash` is of the very
 * text they read. `channel` is 'link' for the student's own link, 'account'
 * for a signed-in student or guardian.
 */
class AgreementSignature extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'agreement_id', 'agreement_version_id', 'student_id', 'party', 'signer_name', 'signer_id',
        'channel', 'type', 'content_hash', 'ip', 'user_agent', 'signed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<AgreementVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'agreement_version_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}

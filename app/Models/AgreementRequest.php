<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send of a template agreement by a teacher, to a student or to their
 * guardian: the version sent, the kind it goes by, the teacher's note, and
 * the answer — given through the request's own link, once. The same template
 * may go to the same person again: each send is its own request and answer.
 */
class AgreementRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'agreement_id', 'agreement_version_id', 'student_id', 'party', 'type', 'note', 'sent_by', 'sent_at', 'cancelled_at',
        'signed_at', 'signer_name', 'signer_id', 'channel', 'content_hash', 'ip', 'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Waiting for its answer: neither given nor called off. */
    public function isOpen(): bool
    {
        return $this->signed_at === null && $this->cancelled_at === null;
    }
}

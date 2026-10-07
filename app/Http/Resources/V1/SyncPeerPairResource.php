<?php

namespace App\Http\Resources\V1;

use App\Models\PeerPair;
use App\Support\AyahIndex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mutual-recitation pair as the app keeps it: its two places, each with
 * the student, the portion they recite (none for a listener) and how it went.
 *
 * @mixin PeerPair
 */
class SyncPeerPairResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'circle_id' => $this->circle_id,
            'date' => $this->date,
            'position' => $this->position,
            'mutual' => $this->mutual,
            ...collect(PeerPair::PLACES)->mapWithKeys(fn (string $place) => [$place => [
                'student_id' => $this->{"{$place}_id"},
                'portion' => AyahIndex::presentRange($this->{"{$place}_from_ayah_id"}, $this->{"{$place}_to_ayah_id"}),
                'mistakes' => $this->{"{$place}_mistakes"},
                'ready' => $this->{"{$place}_ready"},
            ]])->all(),
        ];
    }
}

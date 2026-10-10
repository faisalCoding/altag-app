<?php

namespace App\Services;

use App\Models\Ayah;

/**
 * Fills the selected days of a plan being written with a volume — a page, a
 * surah, a juz — running on from the days before them. The plan wizard calls
 * it, and the teacher app carries a port of it that must answer exactly the
 * same, so the parity fixtures the app replays are written from this class.
 */
class QuranPlanFiller
{
    public function __construct(
        public string $planType,
        public string $fillDirection,
        public string $reviewDirection,
        public int $bulkStartSurah,
        public int $bulkStartVerse,
        public int $memorizedUpToSurah,
        public int $memorizedUpToVerse,
    ) {}

    /**
     * The plan's days with the selected ones filled.
     *
     * @param  list<array<string, mixed>>  $planDays
     * @param  array<int, int|string>  $selectedIndices
     * @return list<array<string, mixed>>
     */
    public function fill(array $planDays, string $type, string $target, array $selectedIndices): array
    {
        foreach ($planDays as $index => &$day) {
            $day['selected'] = in_array($index, $selectedIndices);
        }
        unset($day);

        if ($this->planType === 'review') {
            $target = 'review';
        } elseif ($this->planType === 'hifz') {
            $target = 'hifz';
        }

        $service = app(QuranPlanService::class);
        $lastDayStart = null;
        $lastDayEnd = null;
        $fixedReviewStart = null;

        $fromSurahKey = $target === 'review' ? 'review_from_surah_id' : 'from_surah_id';
        $fromVerseKey = $target === 'review' ? 'review_from_verse' : 'from_verse';
        $toSurahKey = $target === 'review' ? 'review_to_surah_id' : 'to_surah_id';
        $toVerseKey = $target === 'review' ? 'review_to_verse' : 'to_verse';

        $defaultStart = null;
        if ($target === 'review') {
            foreach ($planDays as $day) {
                if ($day['selected']) {
                    $fixedReviewStart = Ayah::where('surah_id', $day['review_from_surah_id'])
                        ->where('verse_number', $day['review_from_verse'])
                        ->first();
                    break;
                }
            }
            $defaultStart = $fixedReviewStart;
            if (! $defaultStart) {
                if ($this->reviewDirection === 'reverse') {
                    $defaultStart = Ayah::where('surah_id', 114)->where('verse_number', 1)->first();
                } else {
                    $defaultStart = Ayah::where('surah_id', 1)->where('verse_number', 1)->first();
                }
            }
        } else {
            $defaultStart = Ayah::where('surah_id', $this->bulkStartSurah)
                ->where('verse_number', $this->bulkStartVerse)
                ->first() ?: Ayah::first();
        }

        $resetNextReview = false;

        foreach ($planDays as &$day) {
            if (! $day['selected']) {
                $unselectedStart = null;
                $unselectedEnd = null;
                if (! empty($day[$fromSurahKey]) && ! empty($day[$fromVerseKey])) {
                    $unselectedStart = Ayah::where('surah_id', $day[$fromSurahKey])
                        ->where('verse_number', $day[$fromVerseKey])
                        ->first();
                }
                if (! empty($day[$toSurahKey]) && ! empty($day[$toVerseKey])) {
                    $unselectedEnd = Ayah::where('surah_id', $day[$toSurahKey])
                        ->where('verse_number', $day[$toVerseKey])
                        ->first();
                }
                if ($unselectedStart && $unselectedEnd) {
                    $isDefault = $defaultStart &&
                                 $unselectedStart->surah_id === $defaultStart->surah_id &&
                                 $unselectedStart->verse_number === $defaultStart->verse_number &&
                                 $unselectedEnd->surah_id === $defaultStart->surah_id &&
                                 $unselectedEnd->verse_number === $defaultStart->verse_number;

                    if (! $isDefault) {
                        $lastDayStart = $unselectedStart;
                        $lastDayEnd = $unselectedEnd;
                    }
                }

                continue;
            }

            if ($target === 'review') {
                $maxPossibleEnd = null;
                $loopBackStart = $fixedReviewStart;

                if ($this->planType === 'hifz_review') {
                    $hifzStartAyah = Ayah::where('surah_id', $day['from_surah_id'])
                        ->where('verse_number', $day['from_verse'])
                        ->first();

                    if (! $hifzStartAyah) {
                        continue;
                    }

                    // Dynamically calculate the Hifz ceiling (last verse of Hifz on previous day)
                    $C = $service->getAyahBefore($hifzStartAyah, $this->fillDirection);
                } else {
                    // Pure Review ceiling based on selected Hifz boundary
                    $C = Ayah::where('surah_id', $this->memorizedUpToSurah)
                        ->where('verse_number', $this->memorizedUpToVerse)
                        ->first();
                }

                if ($C) {
                    if ($this->reviewDirection === $this->fillDirection) {
                        // Same direction: starting point is static (fixedReviewStart), cap is ceiling C
                        $loopBackStart = $fixedReviewStart;
                        $maxPossibleEnd = $C;
                    } else {
                        // Opposite direction: starting point is the first verse of the ceiling surah C
                        $loopBackStart = Ayah::where('surah_id', $C->surah_id)
                            ->where('verse_number', 1)
                            ->first();

                        $isFullSurah = ($C->verse_number === $C->surah->verses_count);
                        if ($isFullSurah) {
                            if ($this->reviewDirection === 'reverse') {
                                $maxPossibleEnd = Ayah::where('surah_id', 1)
                                    ->orderBy('verse_number', 'desc')
                                    ->first();
                            } else {
                                $maxPossibleEnd = Ayah::where('surah_id', 114)
                                    ->orderBy('verse_number', 'desc')
                                    ->first();
                            }
                        } else {
                            $maxPossibleEnd = $C;
                        }
                    }
                }

                // 1. Determine the Start of this day's review
                if ($type === 'all_previous') {
                    $actualStart = $loopBackStart;
                    $targetReviewEnd = $maxPossibleEnd;
                } else {
                    if ($resetNextReview) {
                        $actualStart = $loopBackStart;
                        $resetNextReview = false;
                    } elseif ($lastDayEnd) {
                        $actualStart = $service->getNextStartAyah($lastDayStart, $lastDayEnd, $type, $this->reviewDirection);
                    } else {
                        $actualStart = $loopBackStart;
                    }

                    if (! $actualStart) {
                        $actualStart = $loopBackStart;
                    }

                    // Ensure Start is not already beyond limit
                    if ($maxPossibleEnd && $service->isExceeding($actualStart, $maxPossibleEnd, $this->reviewDirection)) {
                        $actualStart = $loopBackStart;
                        $resetNextReview = true;
                    }

                    // 2. Determine the End of this day's review based on volume
                    $targetReviewEnd = $service->getEndAyah($actualStart, $type, $this->reviewDirection, null, $this->planType === 'review');

                    // 3. Cap the End so it doesn't overlap limits
                    if ($maxPossibleEnd && $service->isExceeding($targetReviewEnd, $maxPossibleEnd, $this->reviewDirection)) {
                        $isOverLimit = $service->isExceeding($targetReviewEnd, $maxPossibleEnd, $this->reviewDirection, false);

                        $targetReviewEnd = $maxPossibleEnd;
                        $resetNextReview = true;

                        if ($isOverLimit) {
                            // Calculate backwards from the start of maxPossibleEnd's surah to get a full review segment of the requested volume
                            $actualStart = $this->segmentEndingAt($service, $maxPossibleEnd, $loopBackStart, $type);
                        }
                    }

                    // Check if today's range is a duplicate of the previous day's range
                    if ($lastDayStart && $lastDayEnd && $maxPossibleEnd) {
                        if ($actualStart->surah_id === $lastDayStart->surah_id &&
                            $actualStart->verse_number === $lastDayStart->verse_number &&
                            $targetReviewEnd->surah_id === $lastDayEnd->surah_id &&
                            $targetReviewEnd->verse_number === $lastDayEnd->verse_number) {

                            $actualStart = $this->segmentEndingAt($service, $maxPossibleEnd, $loopBackStart, $type);

                            $targetReviewEnd = $maxPossibleEnd;
                            $resetNextReview = true;
                        }
                    }
                }

                $day['review_from_surah_id'] = $actualStart->surah_id;
                $day['review_from_verse'] = $actualStart->verse_number;
                $day['review_to_surah_id'] = $targetReviewEnd->surah_id;
                $day['review_to_verse'] = $targetReviewEnd->verse_number;

                $lastDayStart = $actualStart;
                $lastDayEnd = $targetReviewEnd;

                continue;
            }

            $hifzCeilingAyah = null;
            $ceiling = null;
            if ($target === 'hifz') {
                $ceiling = Ayah::where('surah_id', $this->memorizedUpToSurah)
                    ->where('verse_number', $this->memorizedUpToVerse)
                    ->first();
                if ($ceiling) {
                    $hifzCeilingAyah = $service->getNextStartAyah($ceiling, $ceiling, $type, $this->fillDirection);
                }
            }

            if ($lastDayStart && $lastDayEnd) {
                $start = $service->getNextStartAyah($lastDayStart, $lastDayEnd, $type, $this->fillDirection);

                $isExceeded = ! $start || ($ceiling && $service->isExceeding($start, $ceiling, $this->fillDirection, false));

                if ($isExceeded) {
                    if ($this->fillDirection === 'reverse') {
                        $start = Ayah::where('surah_id', 114)->where('verse_number', 1)->first();
                    } else {
                        $start = Ayah::where('surah_id', 1)->where('verse_number', 1)->first();
                    }
                }

                if ($start) {
                    $day[$fromSurahKey] = $start->surah_id;
                    $day[$fromVerseKey] = $start->verse_number;
                }
            }

            $currentStart = Ayah::where('surah_id', $day[$fromSurahKey])
                ->where('verse_number', $day[$fromVerseKey])
                ->first();

            if ($currentStart) {
                // Only a hifz day is capped: review days were filled above.
                $end = $service->getEndAyah($currentStart, $type, $this->fillDirection, $target === 'hifz' ? $hifzCeilingAyah : null, $this->planType === 'review');

                $day[$toSurahKey] = $end->surah_id;
                $day[$toVerseKey] = $end->verse_number;

                $lastDayStart = $currentStart;
                $lastDayEnd = $end;
            }
        }
        unset($day);

        return $planDays;
    }

    /**
     * Where a review day of the given volume starts when it must end at the
     * cap: measured back from the start of the cap's surah to the first verse
     * of the surah it lands in, but never further back than where the loop
     * starts over.
     */
    private function segmentEndingAt(QuranPlanService $service, Ayah $maxPossibleEnd, ?Ayah $loopBackStart, string $type): ?Ayah
    {
        $oppositeDirection = $this->reviewDirection === 'reverse' ? 'forward' : 'reverse';
        $backwardsStartAnchor = Ayah::where('surah_id', $maxPossibleEnd->surah_id)
            ->where('verse_number', 1)
            ->first();
        $backwardsStart = $service->getEndAyah($backwardsStartAnchor ?: $maxPossibleEnd, $type, $oppositeDirection, null, $this->planType === 'review');

        if ($backwardsStart) {
            $backwardsStart = Ayah::where('surah_id', $backwardsStart->surah_id)
                ->where('verse_number', 1)
                ->first();
        }

        // Ensure we don't go past the absolute start of the memorized range
        if ($backwardsStart && $service->isExceeding($loopBackStart, $backwardsStart, $this->reviewDirection)) {
            return $loopBackStart;
        }

        return $backwardsStart ?: $loopBackStart;
    }
}

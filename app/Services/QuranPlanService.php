<?php

namespace App\Services;

use App\Models\Ayah;
use App\Models\Surah;

class QuranPlanService
{
    /**
     * Get the end ayah for a given start ayah, unit type, and direction.
     */
    public function getEndAyah(Ayah $startAyah, string $type, string $direction = 'forward', ?Ayah $capAyah = null): Ayah
    {
        $currentAyah = clone $startAyah;
        $endAyah = null;

        if ($type === 'half') {
            $endAyah = $this->traverseLines($currentAyah, 7, $direction);
        } elseif ($type === 'third') {
            $endAyah = $this->traverseLines($currentAyah, 5, $direction);
        } elseif ($type === 'page') {
            $endAyah = $this->traverseLines($currentAyah, 15, $direction);
        } elseif ($type === 'surah') {
            $endAyah = Ayah::where('surah_id', $currentAyah->surah_id)->orderBy('verse_number', 'desc')->first();
        } elseif ($type === '3_surahs') {
            if ($direction === 'forward') {
                $targetSurahId = min(114, $currentAyah->surah_id + 2);
            } else {
                $targetSurahId = max(1, $currentAyah->surah_id - 2);
            }
            $endAyah = Ayah::where('surah_id', $targetSurahId)->orderBy('verse_number', 'desc')->first();
        } elseif ($type === 'juz') {
            $endAyah = $this->traverseLines($currentAyah, 300, $direction); // 20 pages * 15 lines
        } elseif ($type === 'half_juz') {
            $endAyah = $this->traverseLines($currentAyah, 150, $direction); // 10 pages * 15 lines
        } elseif ($type === '5_pages') {
            $endAyah = $this->traverseLines($currentAyah, 75, $direction); // 5 * 15 lines
        } else {
            $endAyah = $startAyah;
        }

        if ($capAyah && $this->isExceeding($endAyah, $capAyah, $direction)) {
            if ($this->isExceeding($startAyah, $capAyah, $direction)) {
                return $startAyah;
            }

            return $this->getAyahBefore($capAyah, $direction);
        }

        return $endAyah;
    }

    public function isExceeding(Ayah $current, Ayah $cap, string $direction, bool $inclusive = true): bool
    {
        if ($direction === 'reverse') {
            // Plan moves from 114 towards 1.
            // So a lower surah ID means we have moved further along the plan.
            if ($current->surah_id < $cap->surah_id) {
                return true;
            }
            if ($current->surah_id > $cap->surah_id) {
                return false;
            }

            // Same surah: we always read 1 -> last within a day's segment.
            // So if current verse is higher than cap verse, we have exceeded.
            return $inclusive ? $current->verse_number >= $cap->verse_number : $current->verse_number > $cap->verse_number;
        } else {
            // Plan moves from 1 towards 114.
            if ($current->surah_id > $cap->surah_id) {
                return true;
            }
            if ($current->surah_id < $cap->surah_id) {
                return false;
            }

            return $inclusive ? $current->verse_number >= $cap->verse_number : $current->verse_number > $cap->verse_number;
        }
    }

    public function getAyahBefore(Ayah $target, string $direction): Ayah
    {
        if ($target->verse_number > 1) {
            return Ayah::where('surah_id', $target->surah_id)->where('verse_number', $target->verse_number - 1)->first();
        }

        $prevSurahId = $direction === 'reverse' ? $target->surah_id + 1 : $target->surah_id - 1;
        if ($prevSurahId >= 1 && $prevSurahId <= 114) {
            return Ayah::where('surah_id', $prevSurahId)->orderBy('verse_number', 'desc')->first();
        }

        return $target;
    }

    /**
     * Traverse a specific number of lines logically.
     * In forward: traverse physically forward.
     * In reverse: traverse forward within the Surah. If end of Surah is reached, jump to verse 1 of PREVIOUS Surah.
     */
    protected function getAyahSize(Ayah $ayah): int
    {
        $absoluteCurrent = (($ayah->page_number - 1) * 15) + $ayah->line_number;

        if ($ayah->verse_number == 1) {
            if ($ayah->surah_id == 1) {
                return $absoluteCurrent;
            }
            $prevAyah = Ayah::where('surah_id', $ayah->surah_id - 1)
                ->orderBy('verse_number', 'desc')
                ->first();
            if ($prevAyah) {
                $absolutePrev = (($prevAyah->page_number - 1) * 15) + $prevAyah->line_number;

                return max(1, $absoluteCurrent - $absolutePrev);
            }
        } else {
            $prevAyah = Ayah::where('surah_id', $ayah->surah_id)
                ->where('verse_number', $ayah->verse_number - 1)
                ->first();
            if ($prevAyah) {
                $absolutePrev = (($prevAyah->page_number - 1) * 15) + $prevAyah->line_number;

                return max(0, $absoluteCurrent - $absolutePrev);
            }
        }

        return 1;
    }

    protected function getTemporalNextAyah(Ayah $current, string $direction): ?Ayah
    {
        $next = Ayah::where('surah_id', $current->surah_id)
            ->where('verse_number', $current->verse_number + 1)
            ->first();
        if ($next) {
            return $next;
        }

        $nextSurahId = ($direction === 'forward') ? $current->surah_id + 1 : $current->surah_id - 1;
        if ($nextSurahId < 1 || $nextSurahId > 114) {
            return null;
        }

        return Ayah::where('surah_id', $nextSurahId)->where('verse_number', 1)->first();
    }

    protected function getTemporalPageEnd(int $pageNumber, string $direction): ?Ayah
    {
        if ($direction === 'forward') {
            return Ayah::where('page_number', $pageNumber)
                ->orderBy('surah_id', 'desc')
                ->orderBy('verse_number', 'desc')
                ->first();
        } else {
            $lowestSurahId = Ayah::where('page_number', $pageNumber)->min('surah_id');

            return Ayah::where('page_number', $pageNumber)
                ->where('surah_id', $lowestSurahId)
                ->orderBy('verse_number', 'desc')
                ->first();
        }
    }

    protected function traverseLines(Ayah $startAyah, int $linesToConsume, string $direction): Ayah
    {
        $isPageMultiple = ($linesToConsume % 15 === 0);
        $pagesTarget = $linesToConsume / 15;

        $currentPageEnd = $this->getTemporalPageEnd($startAyah->page_number, $direction);

        $linesOnCurrentPage = 0;
        $curr = $startAyah;
        while ($curr) {
            $linesOnCurrentPage += $this->getAyahSize($curr);
            if ($curr->id == $currentPageEnd->id) {
                break;
            }
            $curr = $this->getTemporalNextAyah($curr, $direction);
        }

        // RULE 1: If 10 or more lines, it counts as a FULL Page! We finish exactly at the page end.
        if ($isPageMultiple && $linesOnCurrentPage >= 10 && $pagesTarget == 1) {
            return $currentPageEnd;
        }

        // RULE 2 & 3: Less than 10 lines, or just normal fractional walk (mitigation if exceeding)
        $totalLines = 0;
        $curr = $startAyah;
        $lastValidAyah = $curr;

        while ($curr && $totalLines < $linesToConsume) {
            $size = $this->getAyahSize($curr);

            // RULE 3: Mitigation/Reduction -> if we exceed the exact lines, we prefer the lesser amount
            if ($totalLines + $size > $linesToConsume) {
                return ($totalLines > 0) ? $lastValidAyah : $curr;
            }

            $totalLines += $size;
            $lastValidAyah = $curr;

            // Check if we hit a perfectly aligned page block (if multiple pages requested)
            if ($isPageMultiple && $pagesTarget > 1) {
                // To keep it perfectly simple, if multiple pages are requested,
                // the exact lines (N * 15) with mitigation will handle it properly or we can enforce snaps.
                // The strict rule from the user is for "1 Page" adjustments primarily.
            }

            $curr = $this->getTemporalNextAyah($curr, $direction);
        }

        return $lastValidAyah;
    }

    /**
     * Get the next start ayah based on direction.
     * If they just finished $currentEnd, the next start is the logically NEXT ayah.
     */
    public function getNextStartAyah(Ayah $currentStart, Ayah $currentEnd, string $type, string $direction = 'forward'): ?Ayah
    {
        // Logical "Next":
        // Since we are reading forward within a Surah, the "next" Ayah is currentEnd + 1 verse.
        $nextVerse = Ayah::where('surah_id', $currentEnd->surah_id)
            ->where('verse_number', $currentEnd->verse_number + 1)
            ->first();

        if ($nextVerse) {
            return $nextVerse;
        }

        // If currentEnd was the last verse of the Surah, jump to logical next Surah's verse 1
        $nextSurahId = $direction === 'forward' ? $currentEnd->surah_id + 1 : $currentEnd->surah_id - 1;

        if ($nextSurahId >= 1 && $nextSurahId <= 114) {
            return Ayah::where('surah_id', $nextSurahId)->where('verse_number', 1)->first();
        }

        return null;
    }
}

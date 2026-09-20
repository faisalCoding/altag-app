<?php

namespace App\Services;

use App\Models\Ayah;
use App\Models\Surah;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class QuranPlanService
{
    /** Cached surah rows, rehydrated into models on read. */
    public const SURAHS_KEY = 'quran.surahs.rows';

    /** Cached juz→surah map and per-surah verse layout for the plan wizard. */
    public const REFERENCE_KEY = 'quran.plan.reference_data';

    /*
     * ─── Hot-path memoization ──────────────────────────────────────────────
     * getAyahSize() and getTemporalNextAyah() live inside while-loops and
     * are called hundreds of times per fillSelected(). Caching their DB
     * lookups here eliminates the N+1 problem on weak connections.
     * All other methods keep original DB queries — algorithm unchanged.
     */

    /** key: "{surah_id}_{verse_number}" */
    private array $ayahByRef = [];

    /** key: surah_id */
    private array $lastBySurah = [];

    private function findByRef(int $surahId, int $verseNumber): ?Ayah
    {
        $k = "{$surahId}_{$verseNumber}";
        if (! array_key_exists($k, $this->ayahByRef)) {
            $this->ayahByRef[$k] = Ayah::where('surah_id', $surahId)->where('verse_number', $verseNumber)->first();
        }

        return $this->ayahByRef[$k];
    }

    private function lastOfSurah(int $surahId): ?Ayah
    {
        if (! array_key_exists($surahId, $this->lastBySurah)) {
            $this->lastBySurah[$surahId] = Ayah::where('surah_id', $surahId)->orderBy('verse_number', 'desc')->first();
        }

        return $this->lastBySurah[$surahId];
    }

    /**
     * All surahs ordered by id. The raw attribute rows are cached forever (the
     * surah list is immutable reference data) and rehydrated into models on read.
     *
     * Plain arrays are cached rather than the Eloquent collection itself: a
     * serializing cache store (file/redis/database) cannot always reconstruct
     * cached model objects and hands back an __PHP_Incomplete_Class instead.
     *
     * @return Collection<int, Surah>
     */
    public function getAllSurahs(): Collection
    {
        $rows = Cache::rememberForever(self::SURAHS_KEY, function () {
            return Surah::orderBy('id')->get()->map->getAttributes()->all();
        });

        return Surah::hydrate($rows);
    }

    /**
     * Drop the cached reference data.
     *
     * Forever means forever: an empty table read once — a fresh install where
     * somebody opened the plan wizard before the surahs were synced — is cached
     * as an empty list and never reconsidered. The sync calls this when it
     * finishes, which is the only moment the underlying data can have changed.
     */
    public function forgetReferenceData(): void
    {
        Cache::forget(self::SURAHS_KEY);
        Cache::forget(self::REFERENCE_KEY);
    }

    /**
     * Builds the juz→surah mapping and per-surah verse/page layout consumed by
     * the plan-creator wizard. Cached forever since it is derived purely from the
     * immutable ayah reference data, so the ~6k-row scan runs once per cache
     * lifetime instead of on every Livewire request that renders the wizard.
     *
     * @return array{juzSurahs: array<int, list<int>>, versesData: array<int, array{mid: int, pages: array<int, array<int, list<int>>>}>}
     */
    public function getPlanReferenceData(): array
    {
        return Cache::rememberForever(self::REFERENCE_KEY, function () {
            $ayahs = Ayah::orderBy('surah_id')->orderBy('verse_number')->get(['surah_id', 'verse_number', 'page_number', 'line_number_start', 'line_number_end', 'juz_number']);

            $juzSurahs = [];
            $versesData = [];

            $mapping = $ayahs->unique(function ($item) {
                return $item->surah_id.'-'.$item->juz_number;
            });
            foreach ($mapping as $row) {
                $juzSurahs[$row->juz_number][] = $row->surah_id;
            }

            foreach ($ayahs->groupBy('surah_id') as $surahId => $surahAyahs) {
                $first = $surahAyahs->first();
                $last = $surahAyahs->last();
                $startAbs = $first->page_number * 15 + $first->line_number_start;
                $endAbs = $last->page_number * 15 + $last->line_number_end;
                $midAbs = ($startAbs + $endAbs) / 2;

                $middleVerseNumber = 1;
                $minDiff = 999999;
                $pages = [];

                foreach ($surahAyahs as $ayah) {
                    $abs = $ayah->page_number * 15 + $ayah->line_number_start;
                    $diff = abs($abs - $midAbs);
                    if ($diff < $minDiff) {
                        $minDiff = $diff;
                        $middleVerseNumber = $ayah->verse_number;
                    }
                    $pages[$ayah->page_number][$ayah->line_number_end][] = $ayah->verse_number;
                }

                $versesData[$surahId] = [
                    'mid' => $middleVerseNumber,
                    'pages' => $pages,
                ];
            }

            return [
                'juzSurahs' => $juzSurahs,
                'versesData' => $versesData,
            ];
        });
    }

    /**
     * Get the end ayah for a given start ayah, unit type, and direction.
     */
    public function getEndAyah(Ayah $startAyah, string $type, string $direction = 'forward', ?Ayah $capAyah = null, bool $isReviewOnlyPlan = false): Ayah
    {
        $currentAyah = clone $startAyah;
        $endAyah = null;

        if ($type === 'half') {
            $endAyah = $this->traverseLines($currentAyah, 8, $direction);
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
        } elseif ($type === '2_surahs') {
            if ($direction === 'forward') {
                $targetSurahId = min(114, $currentAyah->surah_id + 1);
            } else {
                $targetSurahId = max(1, $currentAyah->surah_id - 1);
            }
            $endAyah = Ayah::where('surah_id', $targetSurahId)->orderBy('verse_number', 'desc')->first();
        } elseif ($type === '1_surah') {
            $endAyah = Ayah::where('surah_id', $currentAyah->surah_id)->orderBy('verse_number', 'desc')->first();
        } elseif ($type === 'juz') {
            if ($currentAyah->surah_id == 114 && $currentAyah->verse_number == 1 && $direction === 'reverse') {
                $endAyah = Ayah::where('surah_id', 78)->orderBy('verse_number', 'desc')->first();
            } elseif ($currentAyah->surah_id == 78 && $currentAyah->verse_number == 1 && $direction === 'forward') {
                $endAyah = Ayah::where('surah_id', 114)->orderBy('verse_number', 'desc')->first();
            } else {
                $endAyah = $this->traverseLines($currentAyah, 300, $direction); // 20 pages * 15 lines
            }
        } elseif ($type === 'half_juz') {
            $endAyah = $this->traverseLines($currentAyah, 150, $direction); // 10 pages * 15 lines
        } elseif ($type === '5_pages') {
            $endAyah = $this->traverseLines($currentAyah, 75, $direction); // 5 * 15 lines
        } elseif (str_starts_with($type, 'custom_pages_')) {
            $pages = (int) str_replace('custom_pages_', '', $type);
            if ($pages < 1) {
                $pages = 1;
            }
            $endAyah = $this->traverseLines($currentAyah, $pages * 15, $direction);
        } else {
            $endAyah = $startAyah;
        }

        // Apply page-fill optimization rules if we traversed lines AND it is a review only plan
        if ($isReviewOnlyPlan && (in_array($type, ['half', 'third', 'page', '5_pages', 'juz', 'half_juz']) || str_starts_with($type, 'custom_pages_'))) {
            $endAyah = $this->applyPageOptimizationRules($startAyah, $endAyah, $direction);
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
        $absoluteCurrent = (($ayah->page_number - 1) * 15) + $ayah->line_number_end;

        if ($ayah->verse_number == 1) {
            if ($ayah->surah_id == 1) {
                return $absoluteCurrent;
            }
            $prevAyah = $this->lastOfSurah($ayah->surah_id - 1);
            if ($prevAyah) {
                $absolutePrev = (($prevAyah->page_number - 1) * 15) + $prevAyah->line_number_end;

                return max(1, $absoluteCurrent - $absolutePrev);
            }
        } else {
            $prevAyah = $this->findByRef($ayah->surah_id, $ayah->verse_number - 1);
            if ($prevAyah) {
                $absolutePrev = (($prevAyah->page_number - 1) * 15) + $prevAyah->line_number_end;

                return max(0, $absoluteCurrent - $absolutePrev);
            }
        }

        return 1;
    }

    protected function getTemporalNextAyah(Ayah $current, string $direction): ?Ayah
    {
        $next = $this->findByRef($current->surah_id, $current->verse_number + 1);
        if ($next) {
            return $next;
        }

        $nextSurahId = ($direction === 'forward') ? $current->surah_id + 1 : $current->surah_id - 1;
        if ($nextSurahId < 1 || $nextSurahId > 114) {
            return null;
        }

        return $this->findByRef($nextSurahId, 1);
    }

    protected function getTemporalPageEnd(int $pageNumber, string $direction, $startAyah): ?Ayah
    {
        if ($direction === 'forward') {
            return Ayah::where('page_number', $pageNumber)
                ->orderBy('surah_id', 'desc')
                ->orderBy('verse_number', 'desc')
                ->first();
        } else {

            $lowestSurah = Ayah::where('page_number', $pageNumber);
            $lowestSurahId = $lowestSurah->min('surah_id');

            if ($lowestSurah->first()->line_number_start == 1 && $startAyah->surah_id != $lowestSurahId) {
                $lowestSurahId++;
            }

            $last_ayah = Ayah::where('surah_id', $startAyah->surah_id)->orderBy('verse_number', 'desc')->first()->toArray();

            if ($last_ayah['page_number'] > $pageNumber && $startAyah->surah_id != $lowestSurahId) {
                $lowestSurahId++;
            }

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

        $currentPageEnd = $this->getTemporalPageEnd($startAyah->page_number, $direction, $startAyah);

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

        while ($curr && $totalLines <= $linesToConsume) {
            $size = $this->getAyahSize($curr);

            // --edit 1 start
            // RULE 3: Mitigation/Reduction -> if we exceed the exact lines, we prefer the lesser amount
            if ($totalLines + $size > $linesToConsume) {
                return ($totalLines > 0) ? $lastValidAyah : $curr;
            }
            // --edit 1 end

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

    /**
     * Get lines between two ayahs (inclusive).
     */
    public function getLinesBetween(Ayah $start, Ayah $end): int
    {
        if ($start->surah_id !== $end->surah_id) {
            return 0;
        }

        $lines = 0;
        $curr = $start;
        while ($curr) {
            $lines += $this->getAyahSize($curr);
            if ($curr->verse_number >= $end->verse_number) {
                break;
            }
            $curr = $this->findByRef($curr->surah_id, $curr->verse_number + 1);
        }

        return $lines;
    }

    /**
     * Get the total lines of a surah.
     */
    public function getSurahLinesCount(int $surahId): int
    {
        $lastAyah = Ayah::where('surah_id', $surahId)->orderBy('verse_number', 'desc')->first();
        if (! $lastAyah) {
            return 0;
        }

        $firstAyah = Ayah::where('surah_id', $surahId)->orderBy('verse_number', 'asc')->first();
        if (! $firstAyah) {
            return 0;
        }

        return $this->getLinesBetween($firstAyah, $lastAyah);
    }

    /**
     * Get the ayah in a surah that is at a specific line offset from verse 1.
     */
    public function getAyahAtLineOffset(int $surahId, int $targetLines): Ayah
    {
        $curr = $this->findByRef($surahId, 1);
        $lines = 0;
        $lastValid = $curr;
        while ($curr) {
            $size = $this->getAyahSize($curr);
            if ($lines + $size > $targetLines) {
                return $lastValid ?: $curr;
            }
            $lines += $size;
            $lastValid = $curr;
            $curr = $this->findByRef($surahId, $curr->verse_number + 1);
        }

        return $lastValid;
    }

    /**
     * Apply Quranic page-fill optimization rules (restricted to review only plan).
     */
    protected function applyPageOptimizationRules(Ayah $startAyah, Ayah $endAyah, string $direction): Ayah
    {
        $startSurahId = $startAyah->surah_id;
        $endSurahId = $endAyah->surah_id;
        $rule2Applied = false;

        // Rule 2: Avoiding awkward start of a new surah (if it's less than 15 lines of the new surah)
        if ($endSurahId !== $startSurahId) {
            $firstAyahOfNewSurah = Ayah::where('surah_id', $endSurahId)->orderBy('verse_number', 'asc')->first();
            if ($firstAyahOfNewSurah) {
                $linesConsumedInNewSurah = $this->getLinesBetween($firstAyahOfNewSurah, $endAyah);
                if ($linesConsumedInNewSurah < 15) {
                    $totalNewSurahLines = $this->getSurahLinesCount($endSurahId);
                    if ($totalNewSurahLines <= 15) {
                        // Complete the new surah
                        $lastAyahOfNewSurah = Ayah::where('surah_id', $endSurahId)->orderBy('verse_number', 'desc')->first();
                        if ($lastAyahOfNewSurah) {
                            $endAyah = $lastAyahOfNewSurah;
                        }
                    } else {
                        // Read exactly 15 lines (1 page) of the new surah
                        $endAyah = $this->getAyahAtLineOffset($endSurahId, 15);
                        $rule2Applied = true;
                    }
                    $endSurahId = $endAyah->surah_id; // Update end surah id in case it changed
                }
            }
        }

        // Rule 1: Surah completion if remaining portion of the end surah is <= 1 page (15 lines)
        if (! $rule2Applied) {
            $lastAyahOfEndSurah = Ayah::where('surah_id', $endSurahId)->orderBy('verse_number', 'desc')->first();
            if ($lastAyahOfEndSurah && $endAyah->verse_number < $lastAyahOfEndSurah->verse_number) {
                $nextVerse = $this->findByRef($endSurahId, $endAyah->verse_number + 1);
                if ($nextVerse) {
                    $remainingLines = $this->getLinesBetween($nextVerse, $lastAyahOfEndSurah);
                    if ($remainingLines <= 15) {
                        $endAyah = $lastAyahOfEndSurah;
                    }
                }
            }
        }

        return $endAyah;
    }
}

<?php

namespace App\Support;

use App\Models\Ayah;
use Illuminate\Support\Facades\Cache;

/**
 * Translates between ayah ids (1–6236, in mushaf order) and the surah and
 * verse numbers the teacher app speaks. The 6,236 pairs never change, so they
 * are read once and cached.
 */
class AyahIndex
{
    /**
     * @return array{surah: int, verse: int}|null
     */
    public static function pair(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $pair = self::index()['byId'][$id] ?? null;

        return $pair === null ? null : ['surah' => $pair[0], 'verse' => $pair[1]];
    }

    public static function id(int $surah, int $verse): ?int
    {
        return self::index()['byPair']["{$surah}:{$verse}"] ?? null;
    }

    /**
     * A range written as "S:V-S:V", the key the app compares ranges by; null
     * when either end is missing.
     */
    public static function rangeKey(?int $fromId, ?int $toId): ?string
    {
        $from = self::pair($fromId);
        $to = self::pair($toId);

        if ($from === null || $to === null) {
            return null;
        }

        return "{$from['surah']}:{$from['verse']}-{$to['surah']}:{$to['verse']}";
    }

    /**
     * @return array{from: array{surah: int, verse: int}, to: array{surah: int, verse: int}}|null
     */
    public static function presentRange(?int $fromId, ?int $toId): ?array
    {
        $from = self::pair($fromId);
        $to = self::pair($toId);

        return $from === null || $to === null ? null : ['from' => $from, 'to' => $to];
    }

    /**
     * Read once per request, and kept in the cache once the table is seeded.
     *
     * @return array{byId: array<int, array{0: int, 1: int}>, byPair: array<string, int>}
     */
    private static function index(): array
    {
        return once(function (): array {
            $cached = Cache::get('ayah-index');

            if (is_array($cached)) {
                return $cached;
            }

            $index = ['byId' => [], 'byPair' => []];

            foreach (Ayah::query()->orderBy('id')->get(['id', 'surah_id', 'verse_number']) as $row) {
                $index['byId'][$row->id] = [$row->surah_id, $row->verse_number];
                $index['byPair']["{$row->surah_id}:{$row->verse_number}"] = $row->id;
            }

            // An unseeded table (a fresh test database) is not worth remembering.
            if ($index['byId'] !== []) {
                Cache::forever('ayah-index', $index);
            }

            return $index;
        });
    }
}

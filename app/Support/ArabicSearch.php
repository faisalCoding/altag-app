<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Finding a name the way it was meant rather than the way it was typed.
 *
 * Arabic names are written several ways — «أحمد» or «احمد», «عائشة» or
 * «عائشه», «مصطفى» or «مصطفي», «عبدالله» or «عبد الله» — so a plain LIKE
 * misses most of what the manager asks the assistant about. Both the column
 * and the search term are folded the same way: the hamza forms of alef to
 * a bare alef, taa marbuta to haa, alef maqsura to yaa, and tatweel and
 * spaces dropped.
 */
class ArabicSearch
{
    /** @var array<string, string> */
    private const FOLDS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ـ' => '', ' ' => '',
    ];

    public static function fold(string $text): string
    {
        // Harakat and the dagger alef carry no letter of their own.
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text;

        return strtr(mb_strtolower(trim($text)), self::FOLDS);
    }

    /**
     * Constrain the query to rows whose column contains the term, both folded.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function where(QueryBuilder $query, string $column, string $term, string $boolean = 'and'): QueryBuilder
    {
        $folded = self::fold($term);

        // An escaped term keeps «%» and «_» typed by the manager literal; «!»
        // escapes, as a backslash would not read the same in every database.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $folded).'%';

        return $query->whereRaw(self::foldedColumn($column)." LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }

    /** The column folded in SQL the way fold() folds the term. */
    private static function foldedColumn(string $column): string
    {
        $sql = 'LOWER('.$column.')';

        foreach (self::FOLDS as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }
}

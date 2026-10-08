<?php

namespace App\Support;

/**
 * A count and the noun it counts, as Arabic says them.
 *
 * Arabic picks the noun's form by the number: one and two are said by the
 * noun alone («نقطة واحدة», «نقطتان»), 3 to 10 take the plural («5 نقاط»),
 * 11 to 99 a singular in the accusative («12 نقطة»), and the hundreds a
 * singular again («100 يوم»). The rule reads the last two digits, so 103
 * counts like 3 («103 أيام») and 101 like a hundred («101 يوم»). Read off the
 * whole number, «100 يوماً» and «102 يوماً» came out wrong.
 *
 * The forms are passed in the case the sentence needs, since the case changes
 * the word («نقطتان» as a subject, «نقطتين» after a preposition). New copy is
 * worded to put the count in the nominative where it can.
 *
 * The browser's copy of the rule is gamCount() in resources/js/gamification/numbers.js.
 */
class ArabicCount
{
    /** Points, nominative: «يلزمك نقطتان». */
    public const POINTS = ['نقطة واحدة', 'نقطتان', 'نقاط', 'نقطة', 'نقطة'];

    /** Days, nominative: «سلسلتك: يومان». */
    public const DAYS = ['يوم واحد', 'يومان', 'أيام', 'يوماً', 'يوم'];

    /** Days, genitive: «تجميد ما فاتك، حتى يومين». */
    public const DAYS_GEN = ['يوم واحد', 'يومين', 'أيام', 'يوماً', 'يوم'];

    /** Ranks, accusative: «تقدّمت مركزين». */
    public const RANKS_ACC = ['مركزاً واحداً', 'مركزين', 'مراكز', 'مركزاً', 'مركز'];

    /** Rewards, genitive (and accusative, written alike): «تم استلام مكافأتين». */
    public const REWARDS_GEN = ['مكافأة واحدة', 'مكافأتين', 'مكافآت', 'مكافأة', 'مكافأة'];

    /** Awards, nominative: «لديك جائزتان بانتظار الاستلام». */
    public const AWARDS = ['جائزة واحدة', 'جائزتان', 'جوائز', 'جائزة', 'جائزة'];

    /** Awards, accusative: «استلمت جائزتين». */
    public const AWARDS_ACC = ['جائزة واحدة', 'جائزتين', 'جوائز', 'جائزة', 'جائزة'];

    /** Absences, nominative: «يتبقى غيابان». */
    public const ABSENCES = ['غياب واحد', 'غيابان', 'غيابات', 'غياباً', 'غياب'];

    /** Late arrivals, nominative: «يتبقى تأخران». */
    public const LATENESSES = ['تأخر واحد', 'تأخران', 'تأخرات', 'تأخراً', 'تأخر'];

    /** Mushaf pages, nominative: «حفظت وجهان». */
    public const PAGES = ['وجه واحد', 'وجهان', 'أوجه', 'وجهاً', 'وجه'];

    /** Weeks, genitive: «بعد نحو أسبوعين». */
    public const WEEKS_GEN = ['أسبوع واحد', 'أسبوعين', 'أسابيع', 'أسبوعاً', 'أسبوع'];

    /** Sessions, genitive: «من جلستين». */
    public const SESSIONS_GEN = ['جلسة واحدة', 'جلستين', 'جلسات', 'جلسة', 'جلسة'];

    /**
     * The count said with its noun: one and two by the noun alone, any other
     * number in Western digits before the form its last two digits call for.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}  $forms  one, two, 3–10, 11–99, and the hundreds (last two digits 0, 1 or 2)
     */
    public static function of(int $count, array $forms): string
    {
        if ($count === 1) {
            return $forms[0];
        }

        if ($count === 2) {
            return $forms[1];
        }

        return $count.' '.$forms[self::formIndex($count)];
    }

    /**
     * The unit for a chip that shows its number apart, as in «+12 نقطة» or
     * «+5 نقاط»: the plural when the last two digits are 3 to 10, the
     * singular otherwise.
     */
    public static function unit(int $count, string $singular, string $plural): string
    {
        $lastTwo = abs($count) % 100;

        return ($lastTwo >= 3 && $lastTwo <= 10) ? $plural : $singular;
    }

    /**
     * Which of the five forms a number other than one or two takes.
     */
    private static function formIndex(int $count): int
    {
        $lastTwo = abs($count) % 100;

        return match (true) {
            $lastTwo >= 3 && $lastTwo <= 10 => 2,
            $lastTwo >= 11 => 3,
            default => 4,
        };
    }
}

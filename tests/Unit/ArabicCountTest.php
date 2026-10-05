<?php

use App\Support\ArabicCount;

it('says one and two by the noun alone', function () {
    expect(ArabicCount::of(1, ArabicCount::POINTS))->toBe('نقطة واحدة')
        ->and(ArabicCount::of(2, ArabicCount::POINTS))->toBe('نقطتان')
        ->and(ArabicCount::of(1, ArabicCount::REWARDS_GEN))->toBe('مكافأة واحدة')
        ->and(ArabicCount::of(2, ArabicCount::REWARDS_GEN))->toBe('مكافأتين');
});

it('takes the plural from 3 to 10 and the singular from 11 to 99', function () {
    expect(ArabicCount::of(3, ArabicCount::DAYS))->toBe('3 أيام')
        ->and(ArabicCount::of(10, ArabicCount::DAYS))->toBe('10 أيام')
        ->and(ArabicCount::of(11, ArabicCount::DAYS))->toBe('11 يوماً')
        ->and(ArabicCount::of(99, ArabicCount::DAYS))->toBe('99 يوماً');
});

it('reads the last two digits from a hundred up', function () {
    expect(ArabicCount::of(100, ArabicCount::DAYS))->toBe('100 يوم')
        ->and(ArabicCount::of(101, ArabicCount::DAYS))->toBe('101 يوم')
        ->and(ArabicCount::of(102, ArabicCount::DAYS))->toBe('102 يوم')
        ->and(ArabicCount::of(103, ArabicCount::DAYS))->toBe('103 أيام')
        ->and(ArabicCount::of(115, ArabicCount::RANKS_ACC))->toBe('115 مركزاً')
        ->and(ArabicCount::of(3, ArabicCount::AWARDS_ACC))->toBe('3 جوائز');
});

it('picks a chip unit, plural only from 3 to 10', function () {
    expect(ArabicCount::unit(5, 'نقطة', 'نقاط'))->toBe('نقاط')
        ->and(ArabicCount::unit(12, 'نقطة', 'نقاط'))->toBe('نقطة')
        ->and(ArabicCount::unit(1, 'نقطة', 'نقاط'))->toBe('نقطة')
        ->and(ArabicCount::unit(2, 'نقطة', 'نقاط'))->toBe('نقطة')
        ->and(ArabicCount::unit(108, 'نقطة', 'نقاط'))->toBe('نقاط');
});

it('says the awards and days the celebrations need in the case their sentence puts them', function () {
    expect(ArabicCount::of(2, ArabicCount::AWARDS))->toBe('جائزتان')
        ->and(ArabicCount::of(2, ArabicCount::AWARDS_ACC))->toBe('جائزتين')
        ->and(ArabicCount::of(5, ArabicCount::AWARDS))->toBe('5 جوائز')
        ->and(ArabicCount::of(2, ArabicCount::DAYS_GEN))->toBe('يومين')
        ->and(ArabicCount::of(1, ArabicCount::DAYS_GEN))->toBe('يوم واحد')
        ->and(ArabicCount::of(12, ArabicCount::DAYS_GEN))->toBe('12 يوماً');
});

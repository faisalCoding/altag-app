import assert from 'node:assert/strict';
import { test } from 'node:test';

import { gamCount, gamIsolate, gamSigned, gamUnit } from './numbers.js';

// The same forms as App\Support\ArabicCount, word for word.
const POINTS = ['نقطة واحدة', 'نقطتان', 'نقاط', 'نقطة', 'نقطة'];
const DAYS = ['يوم واحد', 'يومان', 'أيام', 'يوماً', 'يوم'];
const REWARDS_GEN = ['مكافأة واحدة', 'مكافأتين', 'مكافآت', 'مكافأة', 'مكافأة'];

test('one and two are said by the noun alone', () => {
    assert.equal(gamCount(1, POINTS), 'نقطة واحدة');
    assert.equal(gamCount(2, POINTS), 'نقطتان');
    assert.equal(gamCount(2, REWARDS_GEN), 'مكافأتين');
});

test('3 to 10 take the plural, 11 to 99 the singular', () => {
    assert.equal(gamCount(3, DAYS), '3 أيام');
    assert.equal(gamCount(10, DAYS), '10 أيام');
    assert.equal(gamCount(11, DAYS), '11 يوماً');
    assert.equal(gamCount(99, DAYS), '99 يوماً');
});

test('the hundreds read the last two digits', () => {
    assert.equal(gamCount(100, DAYS), '100 يوم');
    assert.equal(gamCount(101, DAYS), '101 يوم');
    assert.equal(gamCount(102, DAYS), '102 يوم');
    assert.equal(gamCount(103, DAYS), '103 أيام');
    assert.equal(gamCount(111, DAYS), '111 يوماً');
    assert.equal(gamCount(0, POINTS), '0 نقطة');
});

test('a chip unit is plural for 3 to 10 only', () => {
    assert.equal(gamUnit(5, 'نقطة', 'نقاط'), 'نقاط');
    assert.equal(gamUnit(12, 'نقطة', 'نقاط'), 'نقطة');
    assert.equal(gamUnit(1, 'نقطة', 'نقاط'), 'نقطة');
    assert.equal(gamUnit(105, 'نقطة', 'نقاط'), 'نقاط');
});

test('isolation wraps the text so a sign keeps its side in Arabic', () => {
    assert.equal(gamIsolate('+12'), '\u2066+12\u2069');
});

test('a signed number has a plus only when positive, in Western digits', () => {
    assert.equal(gamSigned(12), '+12');
    assert.equal(gamSigned(-30), '-30');
    assert.equal(gamSigned(0), '0');
    assert.equal(gamSigned(1250), '+1,250');
    assert.equal(gamSigned(7, false), '7');
});

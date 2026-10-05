// Numbers and counts on the student's gamification page, for text the
// browser builds itself. The server's copy of the counting rule is
// App\Support\ArabicCount; the two must agree word for word.

const LRI = '\u2066';
const PDI = '\u2069';

/**
 * Isolate text that must read left to right ("+12", "12 / 40", "×2") inside an
 * Arabic line, so the sign stays where it was written.
 */
export function gamIsolate(text) {
    return LRI + String(text) + PDI;
}

function lastTwoDigits(count) {
    return Math.abs(Math.trunc(count)) % 100;
}

/**
 * The count said with its noun. forms: [one, two, 3–10, 11–99, hundreds].
 * One and two are said by the noun alone; any other number by its digits
 * before the form its last two digits call for (103 counts like 3, 101 like 100).
 */
export function gamCount(count, forms) {
    const n = Math.trunc(Number(count) || 0);

    if (n === 1) {
        return forms[0];
    }

    if (n === 2) {
        return forms[1];
    }

    const lastTwo = lastTwoDigits(n);
    const form = lastTwo >= 3 && lastTwo <= 10 ? forms[2] : (lastTwo >= 11 ? forms[3] : forms[4]);

    return n + ' ' + form;
}

/**
 * The unit for a chip that shows its number apart («+12 نقطة», «+5 نقاط»):
 * the plural when the last two digits are 3 to 10, the singular otherwise.
 */
export function gamUnit(count, singular, plural) {
    const lastTwo = lastTwoDigits(Number(count) || 0);

    return lastTwo >= 3 && lastTwo <= 10 ? plural : singular;
}

/**
 * A whole number with "+" before a positive one when signed, in Western
 * digits with thousands separators, as PHP's number_format() writes it.
 */
export function gamSigned(value, signed = true) {
    const n = Math.trunc(Number(value) || 0);
    const sign = n < 0 ? '-' : (signed && n > 0 ? '+' : '');

    return sign + Math.abs(n).toLocaleString('en-US');
}

<?php

use App\Support\ArabicCount;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * The student gamification page's browser logic (the claim store, the motion
 * helpers, the Arabic counting) lives in resources/js/gamification and is
 * unit-tested there with Node's own test runner. This runs those tests with
 * the rest, so a broken store fails the suite like any PHP test would.
 */

it('passes the gamification page\'s JavaScript unit tests', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('Node is not installed here.');
    }

    $result = Process::path(base_path())
        ->timeout(120)
        ->run([$node, '--test', 'resources/js/gamification/*.test.js']);

    expect($result->successful())->toBeTrue($result->output()."\n".$result->errorOutput())
        ->and($result->output())->toMatch('/# pass [1-9]\d*/')
        ->and($result->output())->toContain('# fail 0');
});

it('mirrors the server\'s Arabic counting forms in the browser tests word for word', function () {
    $tests = file_get_contents(base_path('resources/js/gamification/numbers.test.js'));

    foreach (['POINTS', 'DAYS', 'REWARDS_GEN'] as $name) {
        $forms = constant(ArabicCount::class.'::'.$name);
        $js = "const {$name} = ['".implode("', '", $forms)."'];";

        expect($tests)->toContain($js);
    }

    // The celebration cards' live line («لديك جائزتان بانتظار الاستلام»).
    $forms = ArabicCount::AWARDS;
    expect(file_get_contents(base_path('resources/js/gamification/celebrations.test.js')))
        ->toContain("const AWARDS = ['".implode("', '", $forms)."'];");
});

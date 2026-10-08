<?php

use App\Livewire\Manager\AttendanceReports;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Services\AttendanceReportGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    $this->secondary = Stage::factory()->create(['name' => 'الثانوية', 'position' => 1]);
    $this->middle = Stage::factory()->create(['name' => 'المتوسطة', 'position' => 2]);
    $this->primary = Stage::factory()->create(['name' => 'الابتدائية', 'position' => 3]);

    $this->circles = [];
    foreach ([$this->secondary, $this->middle, $this->primary] as $stage) {
        $this->circles[$stage->name] = Circle::factory()->create(['stage_id' => $stage->id, 'name' => 'حلقة '.$stage->name]);
    }
});

/**
 * Whether a circle's row is showing. Every stage is on the page, so a pick
 * costs no request; the rows of the others are hidden.
 */
function circleRowShown(string $html, Circle $circle): bool
{
    expect(preg_match('/<tr[^>]*data-circle-row="'.$circle->id.'"[^>]*>/', $html, $row))->toBe(1);

    return ! str_contains($row[0], 'display: none');
}

function attendanceReport(array $stageIds = [])
{
    return Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-09-24')
        ->set('toDate', '2026-09-28')
        ->set('stageIds', $stageIds);
}

it('shows the whole academy until a stage is picked', function () {
    $html = attendanceReport()->html();

    foreach (['الثانوية', 'المتوسطة', 'الابتدائية'] as $stage) {
        expect($html)->toContain($stage);
    }
});

it('narrows the table to the stages the manager picked', function () {
    $html = attendanceReport([(string) $this->middle->id])->html();

    expect(circleRowShown($html, $this->circles['المتوسطة']))->toBeTrue()
        ->and(circleRowShown($html, $this->circles['الثانوية']))->toBeFalse()
        ->and(circleRowShown($html, $this->circles['الابتدائية']))->toBeFalse();
});

it('takes more than one stage at a time', function () {
    $html = attendanceReport([(string) $this->secondary->id, (string) $this->primary->id])->html();

    expect(circleRowShown($html, $this->circles['الثانوية']))->toBeTrue()
        ->and(circleRowShown($html, $this->circles['الابتدائية']))->toBeTrue()
        ->and(circleRowShown($html, $this->circles['المتوسطة']))->toBeFalse();
});

it('picks stages in the browser, with no request to the server', function () {
    $html = attendanceReport()->html();

    expect($html)->toContain('x-model="stages"')
        ->toContain("\$wire.entangle('stageIds')")
        ->not->toContain('wire:model.live="stageIds"')
        // Every circle of every stage is drawn, ready to be shown.
        ->toContain('حلقة الثانوية')->toContain('حلقة المتوسطة')->toContain('حلقة الابتدائية');
});

it('prints without the dot the sheet\'s font lacks', function () {
    $html = view('pdf.attendance-report', [
        'grid' => AttendanceReportGrid::build('2026-09-24', '2026-09-28'),
        'fromDate' => '2026-09-24',
        'toDate' => '2026-09-28',
        'stageNames' => invade_names([(string) $this->secondary->id, (string) $this->middle->id]),
    ])->render();

    // Lama Sans has no «·»; mPDF printed a «no glyph» box in its place.
    expect($html)->not->toContain('·')
        ->toContain('الثانوية، المتوسطة');
});

it('keeps the arranged order inside a narrowed report', function () {
    $html = attendanceReport([(string) $this->primary->id, (string) $this->secondary->id])->html();

    // Picked in one order, drawn in the academy's.
    expect(strpos($html, 'الثانوية'))->toBeLessThan(strpos($html, 'الابتدائية'));
});

it('goes back to the whole academy on demand', function () {
    $page = attendanceReport([(string) $this->middle->id])->call('clearStages');

    expect($page->get('stageIds'))->toBe([]);
    expect($page->html())->toContain('حلقة الثانوية');
});

it('narrows the printed sheet by the same filter', function () {
    $pdf = attendanceReport([(string) $this->middle->id])->instance()->downloadPDF();

    ob_start();
    $pdf->sendContent();
    $content = ob_get_clean();

    expect(strlen($content))->toBeGreaterThan(1000);

    // The sheet names what it covers, so a filtered print is not mistaken for
    // the whole academy once it is off the screen.
    expect(attendanceReport([(string) $this->middle->id])->instance())
        ->and(invade_names([(string) $this->middle->id]))->toBe('المتوسطة');
    expect(invade_names([]))->toBe('كل المراحل');
});

it('prints in the font the site is set in, not one mPDF chose', function () {
    $pdf = attendanceReport()->instance()->downloadPDF();

    ob_start();
    $pdf->sendContent();
    $content = ob_get_clean();

    // Lama Sans ships as woff2, which mPDF cannot read, so the TTF beside it is
    // what gets embedded. Left to itself mPDF substitutes a font of its own.
    expect($content)->toContain('LamaSans-Regular')
        ->toContain('LamaSans-Bold')
        ->not->toContain('Tajawal');
});

/** Reaches the private helper that titles the printed sheet. */
function invade_names(array $stageIds): string
{
    $component = new AttendanceReports;
    $component->stageIds = $stageIds;

    $method = new ReflectionMethod($component, 'chosenStageNames');

    return $method->invoke($component);
}

it('spreads the printed header across the page instead of bunching it right', function () {
    // mPDF shrinks a table with no width to its content, and on a right-to-left
    // page that piles the logo, the title and the dates against the right edge
    // with the rest of the line empty. Invisible without rendering the sheet.
    $html = view('pdf.attendance-report', [
        'grid' => AttendanceReportGrid::build('2026-09-24', '2026-09-24'),
        'fromDate' => '2026-09-24',
        'toDate' => '2026-09-24',
        'stageNames' => 'كل المراحل',
    ])->render();

    expect($html)->toMatch('/\.head\s*\{[^}]*width:\s*100%/');
});

it('sizes the printed logo with the attribute mPDF actually reads', function () {
    // A CSS height on the image is ignored, and the logo filled the page.
    $html = view('pdf.attendance-report', [
        'grid' => AttendanceReportGrid::build('2026-09-24', '2026-09-24'),
        'fromDate' => '2026-09-24',
        'toDate' => '2026-09-24',
        'stageNames' => 'كل المراحل',
    ])->render();

    expect($html)->toMatch('/<img[^>]+width="\d+"/');
});

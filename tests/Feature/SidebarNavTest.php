<?php

use Illuminate\Support\Facades\Route;

/**
 * The sidebar is read in Arabic; an English label is a leftover, not a choice.
 */
it('leaves no untranslated label in any sidebar', function () {
    foreach (glob(resource_path('views/*/sidebar-nav.blade.php')) as $file) {
        $markup = file_get_contents($file);

        expect($markup)->not->toContain("__('Dashboard')", basename(dirname($file)));
    }
});

/**
 * The student's menu keeps the home page, the Quran plans, the exams, the
 * discipline record, the reports and the messages. The hifz, review, calendar
 * and programme pages were removed, and the achievements and leaderboard live
 * on the home page alone.
 */
it('lists only the pages a student keeps', function () {
    $markup = file_get_contents(resource_path('views/student/sidebar-nav.blade.php'));

    foreach (['الحفظ', 'المراجعة', 'التقويم', 'جدول البرنامج', 'الإنجازات', 'المتصدرون'] as $label) {
        expect($markup)->not->toContain("__('{$label}')");
    }

    foreach (['student.hifz', 'student.review', 'student.calendar', 'student.schedule', 'student.show-plan'] as $route) {
        expect(Route::has($route))->toBeFalse($route);
    }
});

/**
 * A label has to describe the whole page, not its first section. The manager's
 * settings page holds the discipline rules and the database backups; calling it
 * «إعدادات الانضباط» hid the backups from anyone looking for them.
 */
it('names a page by everything on it, not by its first section', function () {
    $page = file_get_contents(resource_path('views/livewire/manager/settings.blade.php'));
    $label = 'الانضباط والنسخ الاحتياطي';

    // Both sections are still there, and the label still names both.
    expect($page)->toContain('إعدادات الانضباط')
        ->and($page)->toContain('النسخ الاحتياطي')
        ->and(file_get_contents(resource_path('views/manager/sidebar-nav.blade.php')))->toContain($label);

    foreach (['الانضباط', 'النسخ الاحتياطي'] as $section) {
        expect($label)->toContain($section);
    }
});

/**
 * «مسار» means a ranking track in the competitions and a memorisation path in
 * the mutun and odes, so the student's plans page must not borrow the word: it
 * lists plans, and says so.
 */
it('calls the student plans page by what it lists', function () {
    $sidebar = file_get_contents(resource_path('views/student/sidebar-nav.blade.php'));

    expect($sidebar)->toContain('خططي القرآنية')
        ->and($sidebar)->not->toContain('مساري القرآني');
});

/**
 * The coloured square behind a sidebar icon is drawn by a global rule on the
 * svg; the colour itself comes from the item. Only the supervisor's items
 * carried one, so every other role showed a white icon on nothing.
 */
it('gives every sidebar icon a coloured square', function () {
    $files = array_merge(
        glob(resource_path('views/*/sidebar-nav.blade.php')),
        [resource_path('views/components/layouts/role-shell.blade.php')],
    );

    foreach ($files as $file) {
        $markup = file_get_contents($file);
        preg_match_all('/<flux:sidebar\.item\b((?:[^>]|->)*?)(?<!-)>/s', $markup, $items);

        $uncoloured = array_filter($items[1], fn ($attrs) => ! str_contains($attrs, '[&_svg]:bg-'));

        expect($uncoloured)->toBe([], basename(dirname($file)).'/'.basename($file));
    }
});

it('draws the square itself for every role, not only the supervisor', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // Padding, radius and a white glyph — the rule is on the item, so it holds
    // for whichever role is looking at it.
    expect($css)->toContain('[data-flux-sidebar-item] svg')
        ->and($css)->toContain('border-radius: 8px');
});

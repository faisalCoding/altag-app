<?php

use App\Models\Circle;
use App\Models\Teacher;

/**
 * The teacher's pages are one page of tabs. A plain link from the home tab to
 * another reloaded all of it; the home tab's links switch tab in place, as the
 * bottom bar and the side menu do.
 */
it('turns to the attendance tab from the home page without reloading', function () {
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach(Circle::factory()->create()->id);

    $html = $this->actingAs($teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful()
        ->getContent();

    foreach (['attendance', 'tasmeeh', 'plan-creator', 'students'] as $tab) {
        expect($html)->toContain("\$dispatch('switch-tab', { tab: '{$tab}'");
    }
});

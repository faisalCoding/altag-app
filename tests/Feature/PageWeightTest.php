<?php

it('fetches no web font from another site before a page can draw', function () {
    // The interface is set in Lama Sans, served from /fonts. An @import from
    // Google blocked every first paint on a phone for a face only listed as
    // a fallback, and told Google about every visit.
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->not->toContain('fonts.googleapis.com')
        ->and(file_get_contents(resource_path('views/partials/head.blade.php')))->not->toContain('fonts.bunny.net');
});

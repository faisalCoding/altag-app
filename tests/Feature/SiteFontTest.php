<?php

/**
 * The interface font is served from this domain, so the files have to be
 * there. A missing weight raises nothing anywhere: the browser quietly falls
 * back and the screen simply wears the wrong face.
 */
it('ships every weight the stylesheet declares', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match_all("/url\('(\/fonts\/lama-sans\/[^']+)'\)/", $css, $declared);

    expect($declared[1])->toHaveCount(6);

    foreach ($declared[1] as $path) {
        expect(public_path($path))->toBeReadableFile();
    }
});

it('leads the interface font stack with Lama Sans', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch("/--font-sans:\s*'Lama Sans',/");
});

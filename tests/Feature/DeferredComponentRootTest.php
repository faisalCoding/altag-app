<?php

/*
 * A component loaded behind the page (defer, lazy) takes the place of a
 * placeholder that keeps its root element, and x-data put on that root comes
 * after the children inside it: they find nothing, fail, and never run again.
 * The teacher's roll call opened from another tab came up with no students.
 * So such a component's root carries no x-data; its scope sits one step in.
 */

/**
 * Every component a view loads deferred or lazily, by name.
 *
 * @return array<int, string>
 */
function deferredComponentNames(): array
{
    $names = [];

    // From the file system, not resource_path(): a dataset is read before the app boots.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/<livewire:([\w.\-]+)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', file_get_contents($file->getPathname()), $tags, PREG_SET_ORDER);

        foreach ($tags as [, $name, $attributes]) {
            if (preg_match('/(^|\s)(defer|lazy)(\s|=|\/|$)/', $attributes)) {
                $names[] = $name;
            }
        }
    }

    return array_values(array_unique($names));
}

/** The opening tag of a component's root element, from its view. */
function componentRootTag(string $name): string
{
    $path = str_replace('.', '/', $name);
    $view = collect([
        resource_path('views/components/'.dirname($path).'/⚡'.basename($path).'.blade.php'),
        resource_path('views/livewire/'.$path.'.blade.php'),
    ])->first(fn (string $candidate) => is_file($candidate));

    expect($view)->not->toBeNull("No view found for {$name}.");

    $source = file_get_contents($view);
    $source = preg_replace('/\A\s*<\?php.*?\?>/s', '', $source);
    $source = preg_replace(['/\{\{--.*?--\}\}/s', '/@php.*?@endphp/s'], '', $source);

    preg_match('/<[a-zA-Z][\w:.\-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $source, $root);

    return $root[0];
}

it('finds the components the teacher pages load behind the page', function () {
    expect(deferredComponentNames())->toContain('teacher.attendance', 'teacher.tasmeeh-manager', 'teacher.attendance-sheet');
});

it('keeps x-data off the root of every component loaded deferred or lazily', function (string $name) {
    expect(componentRootTag($name))->not->toContain('x-data');
})->with(fn () => deferredComponentNames());

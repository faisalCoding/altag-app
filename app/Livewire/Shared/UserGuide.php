<?php

namespace App\Livewire\Shared;

use App\Support\RolePages;
use App\Support\UserGuideContent;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class UserGuide extends Component
{
    public function render()
    {
        $guard = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian'])
            ->first(fn ($g) => auth()->guard($g)->check());

        $sections = collect(UserGuideContent::for($guard ?? ''))
            ->map(function (array $section) use ($guard) {
                $section['pages'] = collect($section['pages'])
                    ->filter(fn (array $page) => Route::has($page['route']))
                    ->filter(fn (array $page) => RolePages::isEnabled($guard, $page['route']))
                    ->map(function (array $page) {
                        $route = Route::getRoutes()->getByName($page['route']);
                        $page['linkable'] = empty($route->parameterNames());
                        $page['url'] = $page['linkable'] ? route($page['route']) : null;

                        return $page;
                    })
                    ->values()
                    ->all();

                return $section;
            })
            ->filter(fn (array $section) => count($section['pages']) > 0)
            ->values();

        return view('livewire.shared.user-guide', [
            'sections' => $sections,
        ]);
    }
}

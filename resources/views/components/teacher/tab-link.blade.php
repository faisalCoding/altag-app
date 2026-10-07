@props(['tab', 'href'])

{{--
    A link to one of the teacher's tabs. Inside the teacher's page it turns to
    the tab where it stands, as the bottom bar and the side menu do — a plain
    link reloaded the whole page, every tab with it. Anywhere else it is an
    ordinary link.
--}}
<a href="{{ $href }}" {{ $attributes }}
    x-on:click.prevent="if (document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: {{ Js::from($tab) }}, url: {{ Js::from($href) }} }); window.scrollTo({ top: 0 }); } else { Livewire.navigate({{ Js::from($href) }}); }">{{ $slot }}</a>

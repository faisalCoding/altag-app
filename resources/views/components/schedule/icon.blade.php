@props(['name' => 'star', 'color' => 'slate'])

@php
    // Line icons drawn for the printed programme schedule. The stroke colour
    // is written on the SVG itself so the icon keeps its colour when printed.
    $paths = [
        'book' => '<path d="M12 6.4C10.4 5 8 4.6 4 5.1v13c4-.5 6.4-.1 8 1.4"/><path d="M12 6.4C13.6 5 16 4.6 20 5.1v13c-4-.5-6.4-.1-8 1.4"/><path d="M12 6.4v13"/>',
        'mosque' => '<path d="M12 3c-2 2-3 3.1-3 4.8 0 1.6 1.3 2.9 3 2.9s3-1.3 3-2.9C15 6.1 14 5 12 3z"/><path d="M5.5 10.7C5.5 9.4 6.3 8.6 7 8.6"/><path d="M18.5 10.7c0-1.3-.8-2.1-1.5-2.1"/><path d="M4 20.5v-7.2a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v7.2"/><path d="M9.2 20.5v-2.8a2.8 2.8 0 0 1 5.6 0v2.8"/><path d="M3 20.5h18"/>',
        'bulb' => '<path d="M9.2 18.2h5.6"/><path d="M10.2 21h3.6"/><path d="M12 3a6 6 0 0 0-3.8 10.6c.6.6 1 1.4 1 2.3h5.6c0-.9.4-1.7 1-2.3A6 6 0 0 0 12 3z"/>',
        'people' => '<circle cx="9" cy="8" r="3"/><path d="M3.6 19a5.4 5.4 0 0 1 10.8 0"/><circle cx="17.2" cy="9.2" r="2.2"/><path d="M15.2 14.6A4.4 4.4 0 0 1 20.6 19"/>',
        'trophy' => '<path d="M7 4h10v4.5a5 5 0 0 1-10 0V4z"/><path d="M7 6H4.2v.8a3 3 0 0 0 3 3"/><path d="M17 6h2.8v.8a3 3 0 0 1-3 3"/><path d="M12 13.5v3"/><path d="M9 20h6"/><path d="M9.8 16.8h4.4V20H9.8z"/>',
        'soccer' => '<g stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7.6l3.3 2.4-1.3 3.9H9.9L8.6 10z"/><path d="M12 3.2v2.1M4.3 9l1.9 1.3M6 18l1.7-1.3M18 18l-1.7-1.3M19.7 9l-1.9 1.3"/></g>',
        'basket' => '<g stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3v18"/><path d="M5.6 5.6C8.1 8.1 8.1 15.9 5.6 18.4"/><path d="M18.4 5.6C15.9 8.1 15.9 15.9 18.4 18.4"/></g>',
        'flask' => '<path d="M9 3.2h6"/><path d="M10 3.2V9l-4.4 8a2 2 0 0 0 1.8 3h9.2a2 2 0 0 0 1.8-3L14 9V3.2"/><path d="M7.4 15.2h9.2"/>',
        'brain' => '<g stroke-width="1.5"><path d="M12 5.6A2.4 2.4 0 0 0 7.6 4.2 2.4 2.4 0 0 0 5.2 6.6 2.4 2.4 0 0 0 4.3 11a2.4 2.4 0 0 0 1 4.3A2.4 2.4 0 0 0 8 18.9a2.4 2.4 0 0 0 4-1z"/><path d="M12 5.6a2.4 2.4 0 0 1 4.4-1.4 2.4 2.4 0 0 1 2.4 2.4A2.4 2.4 0 0 1 19.7 11a2.4 2.4 0 0 1-1 4.3A2.4 2.4 0 0 1 16 18.9a2.4 2.4 0 0 1-4-1z"/><path d="M12 5.6v12.3"/></g>',
        'door' => '<path d="M13.5 3.2H6a1 1 0 0 0-1 1v15.6a1 1 0 0 0 1 1h7.5"/><path d="M13.5 3.2v17.6"/><path d="M10.5 12h9.3"/><path d="M16.8 9l3 3-3 3"/>',
        'party' => '<path d="M3.5 20.5l4.7-11.4 6.7 6.7z"/><path d="M15 4c1 1 1 2.6 0 3.6"/><path d="M18 6.8c1.2 1.2 1.2 3.1 0 4.3"/><path d="M13.8 3.2l.4 1.2M20.4 9l1.2.4M17.4 12.2l.5 1.2"/>',
        'star' => '<path d="M12 3.5l2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.2-4.1 5.8-.8z"/>',
        'pen' => '<path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19z"/><path d="M14 7l3 3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'mic' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21"/><path d="M9 21h6"/>',
        'palette' => '<path d="M12 3a9 9 0 1 0 0 18c1.2 0 1.8-.8 1.8-1.7 0-1.3-1-1.6-1-2.8 0-1 .8-1.7 1.8-1.7H17a4 4 0 0 0 4-4C21 6.4 17 3 12 3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="14.5" cy="7" r="1"/>',
        'bus' => '<rect x="4" y="3.5" width="16" height="14" rx="2.5"/><path d="M4 11h16"/><path d="M7.5 17.5V20M16.5 17.5V20"/><circle cx="8" cy="14.3" r=".9"/><circle cx="16" cy="14.3" r=".9"/>',
        'meal' => '<path d="M7 3v8M5 3v4a2 2 0 0 0 4 0V3"/><path d="M7 11v10"/><path d="M17 3c-1.7 1.2-2.5 3.3-2.5 6v3H17v9"/>',
        'laptop' => '<rect x="4.5" y="5" width="15" height="10" rx="1.5"/><path d="M2.5 19h19"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.6 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
        'flag' => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'puzzle' => '<path d="M10 4.5a2 2 0 1 1 4 0V6h4v4h-1.5a2 2 0 1 0 0 4H18v4h-4v-1.5a2 2 0 1 0-4 0V18H6v-4h1.5a2 2 0 1 0 0-4H6V6h4z"/>',
    ];
@endphp

<svg {{ $attributes->class(['shrink-0', 'size-6' => ! $attributes->has('class')]) }} viewBox="0 0 24 24" fill="none" stroke="{{ \App\Models\ScheduleActivity::hexFor($color) }}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$name] ?? $paths['star'] !!}</svg>

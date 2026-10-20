{{-- One role: its heading, its dates under it, its prose where it is shown in
     full, and its bullets.

     The employer, the title and the dates are the parsed master CV's and never
     the model's, and they arrive here already resolved. --}}
@include('cv.word.paragraph', [
    'style' => 'Heading2',
    'lines' => [$runs->plain($role->title === null
        ? $role->employer
        : __('cv.role_title', ['employer' => $role->employer, 'title' => $role->title]))],
])

@if ($role->dates !== null)
@include('cv.word.paragraph', ['lines' => [$runs->plain($role->dates, italic: true)]])
@endif

@if ($role->context !== null && $runs->block($role->context) !== [])
@include('cv.word.paragraph', ['lines' => $runs->block($role->context)])
@endif

@if ($role->items !== [])
@include('cv.word.items', ['items' => $role->items, 'depth' => 0])
@endif

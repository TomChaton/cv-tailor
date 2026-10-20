{{-- A role's bullets, nested as the master CV nests them. The depth is the
     bullet's own level in the word processor's list, so an item promoted or
     demoted by the owner behaves like any other list item. --}}
@foreach ($items as $item)
@include('cv.word.paragraph', [
    'style' => 'ListParagraph',
    'bullet' => min($depth, 2),
    'lines' => [$runs->line($item->text)],
])
@if ($item->children !== [])
@include('cv.word.items', ['items' => $item->children, 'depth' => $depth + 1])
@endif
@endforeach

<ul>
    @foreach ($items as $item)
        <li>{{ $markdown->line($item->text) }}@if ($item->children !== [])
            @include('cv.items', ['items' => $item->children])
        @endif</li>
    @endforeach
</ul>

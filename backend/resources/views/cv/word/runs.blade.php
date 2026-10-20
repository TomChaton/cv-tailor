{{-- The runs of one paragraph. Emphasis comes from the inline markup a master
     CV's bullets carry; the text is the master CV's own, escaped by Blade. --}}
@foreach ($spans as $span)
<w:r>
@if ($span->emphasised())
<w:rPr>@if ($span->bold)<w:b/>@endif@if ($span->italic)<w:i/>@endif@if ($span->code)<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/>@endif</w:rPr>
@endif
<w:t xml:space="preserve">{{ $span->text }}</w:t>
</w:r>
@endforeach

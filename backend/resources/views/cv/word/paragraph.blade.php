{{-- One paragraph: a style, an optional bullet depth, and one or more lines of
     runs. A second line is a break inside the paragraph rather than a
     paragraph of its own, which is what keeps a role's context one block. --}}
<w:p>
@if (($style ?? null) !== null || ($bullet ?? null) !== null)
<w:pPr>
@if (($style ?? null) !== null)<w:pStyle w:val="{{ $style }}"/>@endif
@if (($bullet ?? null) !== null)<w:numPr><w:ilvl w:val="{{ $bullet }}"/><w:numId w:val="1"/></w:numPr>@endif
</w:pPr>
@endif
@foreach ($lines as $index => $spans)
@if ($index > 0)<w:r><w:br/></w:r>@endif
@include('cv.word.runs', ['spans' => $spans])
@endforeach
</w:p>

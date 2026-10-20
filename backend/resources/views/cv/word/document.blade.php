{{-- One resolved document as WordprocessingML: the copy the owner edits.

     **It carries the same content and the same structure as the PDF**, from
     the same resolved document, and it is not a facsimile of it. Headings are
     heading styles and bullets are list items, because this file exists to be
     edited and a layout built to match the PDF would fight every edit.

     Section headings come from lang/en_GB/cv.php, as the PDF template's do.
     The CV's content never does: it is the master CV's, resolved by the
     pipeline. --}}
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:body>

@include('cv.word.paragraph', ['style' => 'Title', 'lines' => [$runs->plain($document->header->name)]])

@if ($document->header->headline !== null)
@include('cv.word.paragraph', ['style' => 'Subtitle', 'lines' => [$runs->plain($document->header->headline)]])
@endif

@if ($document->header->contact !== [])
@php
    $contact = [];

    foreach ($document->header->contact as $field) {
        if ($contact !== []) {
            $contact = [...$contact, ...$runs->plain(__('cv.contact_separator'))];
        }

        $contact = [...$contact, ...$runs->plain(__('cv.contact_field', [
            'label' => $field['label'],
            'value' => $field['value'],
        ]))];
    }
@endphp
@include('cv.word.paragraph', ['lines' => [$contact]])
@endif

@if ($document->summary !== null)
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.profile'))]])
@include('cv.word.paragraph', ['lines' => [$runs->line($document->summary)]])
@endif

@if ($document->skills !== [])
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.skills'))]])
@foreach ($document->skills as $skill)
@include('cv.word.paragraph', [
    'style' => 'ListParagraph',
    'bullet' => 0,
    'lines' => [$runs->plain($skill->name)],
])
@endforeach
@endif

@if ($document->roles !== [])
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.experience'))]])
@foreach ($document->roles as $role)
@include('cv.word.role', ['role' => $role])
@endforeach
@endif

@if ($document->independentProjects !== [])
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.projects'))]])
@foreach ($document->independentProjects as $role)
@include('cv.word.role', ['role' => $role])
@endforeach
@endif

@if ($document->awards !== [])
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.awards'))]])
@foreach ($document->awards as $award)
@include('cv.word.paragraph', [
    'style' => 'ListParagraph',
    'bullet' => 0,
    'lines' => [$runs->line($award)],
])
@endforeach
@endif

@if ($document->interests !== [])
@include('cv.word.paragraph', ['style' => 'Heading1', 'lines' => [$runs->plain(__('cv.interests'))]])
@foreach ($document->interests as $interest)
@include('cv.word.paragraph', [
    'style' => 'ListParagraph',
    'bullet' => 0,
    'lines' => [$runs->line($interest)],
])
@endforeach
@endif

{{-- A4 with even margins. A document with no section properties at all opens
     at whichever page size the reader defaults to, which on a CV written in
     the United Kingdom is the wrong one often enough to state. --}}
<w:sectPr>
<w:pgSz w:w="11906" w:h="16838"/>
<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>
</w:sectPr>
</w:body>
</w:document>

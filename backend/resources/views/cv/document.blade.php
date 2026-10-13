{{-- One resolved document as HTML, for conversion to PDF.

     Section headings come from lang/en_GB/cv.php. The CV's content does not:
     it is the master CV's, resolved by the pipeline.

     Layout here is deliberately plain. It has to be legible on A4 and nothing
     more; the CV's visual design is a pass of its own. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document->header->name }}</title>
    <style>
        html { font-size: 10.5pt; }

        body {
            margin: 0;
            font-family: "DejaVu Serif", Georgia, "Times New Roman", serif;
            line-height: 1.4;
            color: #111;
        }

        h1, h2, h3 { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; }

        h1 { margin: 0; font-size: 1.7rem; letter-spacing: 0.01em; }

        h2 {
            margin: 1.4rem 0 0.5rem;
            padding-bottom: 0.15rem;
            border-bottom: 1px solid #999;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            page-break-after: avoid;
        }

        h3 { margin: 0; font-size: 1rem; }

        p { margin: 0 0 0.4rem; }

        ul { margin: 0.25rem 0 0.6rem; padding-left: 1.1rem; }

        li { margin-bottom: 0.2rem; }

        li > ul { margin: 0.2rem 0 0.3rem; }

        .headline { margin: 0.2rem 0 0.4rem; font-size: 1.05rem; font-style: italic; }

        .contact { margin: 0; font-size: 0.85rem; color: #333; }

        .contact span + span::before { content: " · "; }

        .contact .label { font-weight: bold; }

        .role { margin-bottom: 0.9rem; page-break-inside: avoid; }

        .role .title { font-weight: normal; font-style: italic; }

        .role .title::before { content: " — "; font-style: normal; }

        .dates { margin: 0; font-size: 0.85rem; color: #333; }

        .context { margin-top: 0.3rem; }

        .skills { columns: 2; column-gap: 1.6rem; }

        .inline { margin: 0.25rem 0 0.6rem; padding: 0; list-style: none; }

        .inline li { display: inline; }

        .inline li + li::before { content: " · "; }
    </style>
</head>
<body>
    <header>
        <h1>{{ $document->header->name }}</h1>

        @if ($document->header->headline !== null)
            <p class="headline">{{ $document->header->headline }}</p>
        @endif

        @if ($document->header->contact !== [])
            <p class="contact">
                @foreach ($document->header->contact as $field)
                    <span><span class="label">{{ $field['label'] }}:</span> {{ $field['value'] }}</span>
                @endforeach
            </p>
        @endif
    </header>

    @if ($document->summary !== null)
        <h2>{{ __('cv.profile') }}</h2>
        <p>{{ $markdown->line($document->summary) }}</p>
    @endif

    @if ($document->skills !== [])
        <h2>{{ __('cv.skills') }}</h2>
        <ul class="skills">
            @foreach ($document->skills as $skill)
                <li>{{ $skill->name }}</li>
            @endforeach
        </ul>
    @endif

    @if ($document->roles !== [])
        <h2>{{ __('cv.experience') }}</h2>
        @foreach ($document->roles as $role)
            @include('cv.role', ['role' => $role])
        @endforeach
    @endif

    @if ($document->independentProjects !== [])
        <h2>{{ __('cv.projects') }}</h2>
        @foreach ($document->independentProjects as $role)
            @include('cv.role', ['role' => $role])
        @endforeach
    @endif

    @if ($document->awards !== [])
        <h2>{{ __('cv.awards') }}</h2>
        <ul>
            @foreach ($document->awards as $award)
                <li>{{ $markdown->line($award) }}</li>
            @endforeach
        </ul>
    @endif

    @if ($document->interests !== [])
        <h2>{{ __('cv.interests') }}</h2>
        <ul class="inline">
            @foreach ($document->interests as $interest)
                <li>{{ $markdown->line($interest) }}</li>
            @endforeach
        </ul>
    @endif
</body>
</html>

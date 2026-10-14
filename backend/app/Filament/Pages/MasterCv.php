<?php

namespace App\Filament\Pages;

use App\MasterCv\InspectMasterCv;
use App\MasterCv\Lint\Finding;
use App\MasterCv\MasterCvReport;
use App\MasterCv\SaveMasterCv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where the master CV is written, and where its problems are reported.
 *
 * The CV is the one thing every owner has to create and maintain, so the
 * application owns it rather than reading it from somewhere they have to put
 * it. Editing and reporting are on one page because the loop that matters is
 * seeing a problem and fixing it, and a loop that leaves the page is one
 * nobody closes.
 *
 * A save is free and unversioned, and a CV with problems in it still saves:
 * refusing a draft would make its problems unfixable. Generation is the gate,
 * and it has not moved.
 *
 * Everything on the page is a schema component. Filament ships its panel CSS
 * prebuilt from its own classes, so a hand-written template here would have no
 * styles to reach for.
 */
class MasterCv extends Page
{
    /**
     * The editor's element id. A finding reaches the editor by it, and that
     * is the only thing the two halves of the page share.
     */
    private const EDITOR_ID = 'master-cv-markdown';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    /**
     * The editor's state. Filament fields bind to a public Livewire property,
     * and `data` is the name its own pages use.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    private ?MasterCvReport $report = null;

    private InspectMasterCv $inspect;

    private SaveMasterCv $saveMasterCv;

    /**
     * Livewire constructs its own components, so there is no constructor to
     * inject into. It resolves a lifecycle hook's parameters from the
     * container on every request, which is where the dependencies come from.
     */
    public function boot(InspectMasterCv $inspect, SaveMasterCv $save): void
    {
        $this->inspect = $inspect;
        $this->saveMasterCv = $save;
    }

    public function mount(): void
    {
        $this->data = ['markdown' => $this->getReport()->markdown];
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('master-cv.title');
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('master-cv.title');
    }

    /**
     * What the application makes of the stored CV, read once per request.
     *
     * Memoised rather than remembered: the memo lives as long as one request,
     * so a save and the problems it produces cannot be a request apart.
     */
    public function getReport(): MasterCvReport
    {
        return $this->report ??= $this->inspect->handle();
    }

    /**
     * Everything that depends on the report is a closure, because a save
     * happens after the schema is assembled and before it is rendered. Built
     * eagerly, the problems beside the editor would be the problems of the CV
     * the owner had just replaced.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->visible(fn (): bool => $this->getReport()->stored)
                ->schema(fn (): array => $this->facts($this->getReport())),
            Grid::make(['default' => 1, 'xl' => 2])->schema([
                Section::make()
                    // The editor writes into `$this->data`, which `save()`
                    // reads back.
                    ->statePath('data')
                    ->schema([
                        Textarea::make('markdown')
                            ->label(__('master-cv.markdown'))
                            ->id(self::EDITOR_ID)
                            ->rows(30),
                    ]),
                Group::make()
                    ->extraAttributes(['x-data' => $this->lineSelector()])
                    ->schema(fn (): array => $this->verdict($this->getReport())),
            ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('master-cv.actions.save'))
                ->icon(Heroicon::Check)
                ->action(fn () => $this->save()),

            Action::make('download')
                ->label(__('master-cv.actions.download'))
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (): bool => $this->getReport()->stored)
                ->action(fn (): StreamedResponse => $this->download(
                    $this->getReport()->markdown,
                    (string) __('master-cv.download_filename'),
                )),

            Action::make('addIds')
                ->label(__('master-cv.actions.add_ids'))
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (): bool => $this->getReport()->missingIds() > 0)
                ->action(fn (): ?StreamedResponse => $this->downloadWithIds()),
        ];
    }

    /**
     * Saving re-lints, so the problems beside the editor are the problems of
     * what was just saved.
     */
    public function save(): void
    {
        $markdown = $this->data['markdown'] ?? '';

        $this->report = $this->saveMasterCv->handle(is_string($markdown) ? $markdown : '');

        Notification::make()
            ->title(__('master-cv.saved'))
            ->success()
            ->send();
    }

    /**
     * The document with its missing IDs filled in - the same content `cv:id`
     * writes, offered as a download because the author is the one who decides
     * which of them they meant.
     */
    public function downloadWithIds(): ?StreamedResponse
    {
        $ids = $this->getReport()->ids;

        if ($ids === null || $ids->total() === 0) {
            return null;
        }

        return $this->download($ids->content, (string) __('master-cv.download_with_ids_filename'));
    }

    private function download(string $content, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print $content,
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * @return list<Component>
     */
    private function facts(MasterCvReport $report): array
    {
        return [
            Grid::make()->schema([
                TextEntry::make('saved')
                    ->label(__('master-cv.saved'))
                    ->state($report->saved?->format('j F Y, H:i T')),
                TextEntry::make('version')
                    ->label(__('master-cv.version'))
                    ->state($report->version)
                    ->fontFamily(FontFamily::Mono),
            ]),
            Grid::make(['default' => 2, 'sm' => 3, 'xl' => 6])
                ->schema($this->counts($report)),
        ];
    }

    /**
     * @return list<Component>
     */
    private function counts(MasterCvReport $report): array
    {
        $entries = [];

        foreach ($report->counts ?? [] as $kind => $count) {
            $entries[] = TextEntry::make('count_'.$kind)
                ->label(__('master-cv.counts.'.$kind))
                ->state($count);
        }

        return $entries;
    }

    /**
     * What lint makes of the CV, beside the editor it was typed into.
     *
     * A CV that has never been saved has nothing to say about it: an empty
     * editor is the whole of a fresh installation's state.
     *
     * @return list<Component>
     */
    private function verdict(MasterCvReport $report): array
    {
        if (! $report->stored) {
            return [];
        }

        return [
            Section::make()
                ->heading(__('master-cv.problems'))
                ->schema($this->problems($report)),
            ...($report->notes() === [] ? [] : [
                Section::make()
                    ->heading(__('master-cv.notes'))
                    ->schema($this->groupedByCheck($report->notesByCheck())),
            ]),
        ];
    }

    /**
     * An empty problem list says the CV is fit to generate from, and nothing
     * else.
     *
     * @return list<Component>
     */
    private function problems(MasterCvReport $report): array
    {
        if ($report->passes()) {
            return [Text::make(__('master-cv.passes'))];
        }

        return $this->groupedByCheck($report->problemsByCheck());
    }

    /**
     * @param  array<string, list<Finding>>  $groups
     * @return list<Component>
     */
    private function groupedByCheck(array $groups): array
    {
        $fieldsets = [];

        foreach ($groups as $check => $findings) {
            $fieldsets[] = Fieldset::make(__('master-cv.checks.'.$check))
                ->columns(1)
                ->schema([
                    UnorderedList::make(array_map(
                        fn (Finding $finding, int $position): Action|string => $this->finding($finding, $position),
                        $findings,
                        array_keys($findings),
                    ))
                        // The component lays short items out in two columns.
                        // A finding is a sentence, so it gets the width.
                        ->columns(['default' => 1, 'sm' => 1]),
                ]);
        }

        return $fieldsets;
    }

    /**
     * One finding, beside the editor it is about.
     *
     * A finding that names a line is a button that selects it. The number was
     * only ever being read to find the line, and finding it by eye in a
     * document of several hundred lines is the work this takes off the page.
     *
     * A finding with no line to name has nothing to select, so it stays the
     * sentence it was: a control that did nothing when it was pressed would
     * be worse than no control at all.
     *
     * Notes behave exactly as problems do. One fails the CV and the other
     * does not, and neither fact has anything to do with finding the line.
     */
    private function finding(Finding $finding, int $position): Action|string
    {
        if ($finding->line === null) {
            return $this->findingText($finding);
        }

        return Action::make(implode('_', [$finding->level->value, $finding->check, $position]))
            ->label($this->findingText($finding))
            ->link()
            // Client-side only. The selection is the browser's own, so the
            // server has nothing to say about it, and a round trip would be
            // a round trip per problem read.
            ->alpineClickHandler('selectLine('.$finding->line.')');
    }

    /**
     * The messages are the linter's own words, written for the CV's author
     * rather than for a developer, so they are the one thing on this page
     * that does not come from the catalogue.
     */
    private function findingText(Finding $finding): string
    {
        return $finding->line === null
            ? $finding->message
            : (string) __('master-cv.finding', [
                'line' => $finding->line,
                'message' => $finding->message,
            ]);
    }

    /**
     * Selecting the line a finding names, in the editor beside it.
     *
     * The browser's own selection is the highlight and nothing is drawn: a
     * gutter of line numbers would have to agree with the editor's own
     * wrapping, and a master CV holds lines long enough to wrap.
     *
     * A text area does not scroll to a selection a script made: it scrolls
     * for a caret the person moved, and for nothing else. So where the line
     * begins is measured in a copy of the editor holding the text above it —
     * a copy wraps where the editor wraps, which a line height multiplied by
     * a line number does not.
     *
     * The findings are the last lint's and the editor holds whatever has been
     * typed since, so a line a finding names may no longer be there. Past the
     * end of the text, this does nothing: saving re-lints, and that is what
     * makes the list true again. Nothing here asks whether the line still
     * says what it said.
     *
     * Alpine, inline, because the panel already ships it and a code editor
     * would cost this application the front-end build it does not have.
     *
     * Single quotes throughout: an attribute value is rendered with only `"`
     * escaped, and the escape it is given is not one HTML honours.
     */
    private function lineSelector(): string
    {
        return sprintf(<<<'JS'
            {
                selectLine(line) {
                    const editor = document.getElementById('%s');

                    if (editor === null) { return; }

                    const lines = editor.value.split('\n');

                    if (line < 1 || lines.length < line) { return; }

                    const above = lines.slice(0, line - 1).join('\n');
                    const start = line === 1 ? 0 : above.length + 1;

                    editor.focus();
                    editor.setSelectionRange(start, start + lines[line - 1].length);
                    editor.scrollTop = this.whereLineBegins(editor, above) - (editor.clientHeight / 2);
                },

                whereLineBegins(editor, above) {
                    const mirror = document.createElement('textarea');
                    const style = getComputedStyle(editor);

                    for (const property of ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'whiteSpace', 'overflowWrap', 'wordBreak', 'tabSize', 'textIndent']) {
                        mirror.style[property] = style[property];
                    }

                    mirror.style.boxSizing = 'content-box';
                    mirror.style.width = style.width;
                    mirror.style.height = '0';
                    mirror.style.position = 'absolute';
                    mirror.style.visibility = 'hidden';
                    mirror.value = above;

                    editor.parentNode.appendChild(mirror);
                    const begins = mirror.scrollHeight;
                    mirror.remove();

                    return begins;
                },
            }
            JS, self::EDITOR_ID);
    }
}

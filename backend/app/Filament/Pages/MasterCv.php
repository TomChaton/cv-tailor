<?php

namespace App\Filament\Pages;

use App\MasterCv\InspectMasterCv;
use App\MasterCv\Lint\Finding;
use App\MasterCv\MasterCvReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the application makes of the master CV, and the two things worth doing
 * about it.
 *
 * The page reports and offers; it does not author. The CV is a Markdown file
 * its owner keeps in an editor, and its mount is read-only, so the page reads
 * the file afresh and hands back a corrected copy to download rather than
 * writing one.
 *
 * Everything on it is a schema component. Filament ships its panel CSS
 * prebuilt from its own classes, so a hand-written template here would have no
 * styles to reach for.
 */
class MasterCv extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    private ?MasterCvReport $report = null;

    private InspectMasterCv $inspect;

    /**
     * Livewire constructs its own components, so there is no constructor to
     * inject into. It resolves a lifecycle hook's parameters from the
     * container on every request, which is where the dependency comes from.
     */
    public function boot(InspectMasterCv $inspect): void
    {
        $this->inspect = $inspect;
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
     * The file as it is now, read once per request.
     *
     * Memoised rather than remembered: the memo lives as long as one request,
     * so a page that is rendered twice reads the file twice and a page left
     * open shows nothing stale.
     */
    public function getReport(): MasterCvReport
    {
        return $this->report ??= $this->inspect->handle();
    }

    public function content(Schema $schema): Schema
    {
        $report = $this->getReport();

        return $schema->components([
            Section::make()->schema($this->file($report)),
            ...($report->wasRead() ? [
                Section::make()
                    ->heading(__('master-cv.problems'))
                    ->schema($this->problems($report)),
            ] : []),
            ...($report->notes() === [] ? [] : [
                Section::make()
                    ->heading(__('master-cv.notes'))
                    ->schema($this->groupedByCheck($report->notesByCheck())),
            ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label(__('master-cv.actions.recheck'))
                ->icon(Heroicon::ArrowPath)
                ->action(fn () => $this->recheck()),

            Action::make('addIds')
                ->label(__('master-cv.actions.add_ids'))
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (): bool => $this->getReport()->missingIds() > 0)
                ->action(fn (): ?StreamedResponse => $this->downloadWithIds()),
        ];
    }

    /**
     * The file is edited outside the application, so every request re-reads
     * it. Clearing the memo keeps a re-check from being answered with a parse
     * made earlier in the same request.
     */
    public function recheck(): void
    {
        $this->report = null;
    }

    /**
     * The document with its missing IDs filled in — the same content `cv:id`
     * writes, offered as a download because the mount is read-only and the
     * author adopts the result themselves.
     */
    public function downloadWithIds(): ?StreamedResponse
    {
        $ids = $this->getReport()->ids;

        if ($ids === null || $ids->total() === 0) {
            return null;
        }

        return response()->streamDownload(
            fn () => print $ids->content,
            (string) __('master-cv.download_filename'),
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * @return list<Component>
     */
    private function file(MasterCvReport $report): array
    {
        $path = TextEntry::make('path')
            ->label(__('master-cv.path'))
            ->state($report->path)
            ->fontFamily(FontFamily::Mono);

        if (! $report->wasRead()) {
            return [
                $path,
                Text::make(__('master-cv.unreadable.'.Str::snake($report->state->name)))
                    ->color('danger'),
            ];
        }

        return [
            Grid::make()->schema([
                $path,
                TextEntry::make('modified')
                    ->label(__('master-cv.modified'))
                    ->state($report->modified?->format('j F Y, H:i T')),
            ]),
            TextEntry::make('version')
                ->label(__('master-cv.version'))
                ->state($report->version)
                ->fontFamily(FontFamily::Mono),
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
                    // The messages are the linter's own words, written for the
                    // CV's author rather than for a developer, so they are the
                    // one thing on this page that does not come from the
                    // catalogue.
                    UnorderedList::make(array_map(
                        fn (Finding $finding): string => $this->findingText($finding),
                        $findings,
                    ))
                        // The component lays short items out in two columns.
                        // A finding is a sentence, so it gets the width.
                        ->columns(['default' => 1, 'sm' => 1]),
                ]);
        }

        return $fieldsets;
    }

    private function findingText(Finding $finding): string
    {
        return $finding->line === null
            ? $finding->message
            : (string) __('master-cv.finding', [
                'line' => $finding->line,
                'message' => $finding->message,
            ]);
    }
}

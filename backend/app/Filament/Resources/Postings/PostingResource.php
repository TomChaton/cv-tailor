<?php

namespace App\Filament\Resources\Postings;

use App\Filament\Resources\Postings\Pages\ListPostings;
use App\Filament\Resources\Postings\Pages\ViewPosting;
use App\Models\Posting;
use App\Postings\DiscardPosting;
use App\Postings\MarkPostingWanted;
use App\Postings\PostingStatus;
use App\Rendering\PdfFilename;
use App\Rendering\PdfStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything that has been captured, and the decision the owner takes about
 * each one.
 *
 * Capture is free and speculative; generation is not. So a posting waits here
 * until it is marked `wanted`, and nothing on a schedule moves it: `cv:queue`
 * sweeps `wanted` and never `captured`.
 *
 * A posting is identified by its title, with the facts that qualify it on a
 * line beneath. A title neither the page nor extraction gave shows as absent:
 * neither source guesses, and a cell printed empty would undo that, because a
 * blank cell cannot be told from a value that is blank. A qualifying fact the
 * capture did not find is left out of its line instead. So a posting still
 * awaiting extraction is a placeholder and no line at all, and
 * `php artisan cv:status` is where the difference between that and a failed
 * extraction is visible.
 *
 * Regenerating and editing belong with the posting detail, which is a screen
 * of its own: this list is for choosing which posting to spend a generation
 * on, and the facts that belong to one posting are on that screen.
 */
class PostingResource extends Resource
{
    protected static ?string $model = Posting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * First in the navigation, which is also where the panel lands: Filament
     * sends its root at the first item the navigation offers. The list is the
     * screen the owner signs in to look at, so the order is stated here
     * rather than left to the order the panel happened to discover things in.
     */
    protected static ?int $navigationSort = 1;

    /**
     * The screen says "Jobs" where the schema says `postings`. The owner
     * browses job boards and thinks in jobs; Laravel's queue owns the table
     * `jobs`, so the code cannot follow them there.
     */
    public static function getModelLabel(): string
    {
        return (string) __('postings.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('postings.label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            // The download action reads the current revision, and without
            // this the list asks the database for one per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('currentRevision'))
            // Newest first. Filament adds the key as a tiebreaker, so two
            // postings captured in the same second still order by capture.
            ->defaultSort('created_at', 'desc')
            // A row opens the posting it names. The way in belongs to the row
            // rather than to a cell, because a posting the capture could not
            // name has no title to hang a link on and still has to be
            // reachable — Filament puts this on every cell that has no URL of
            // its own, so the placeholder's own cell leads here too.
            ->recordUrl(fn (Posting $record): string => self::getUrl('view', ['record' => $record]))
            ->columns([
                // A posting is identified by its title, with the facts that
                // qualify it on a line beneath. That line is the column's own
                // description rather than a layout component, because a
                // layout component in `columns()` costs the whole list its
                // table: no headings and no click-to-sort, which is what a
                // reader uses to read a list down.
                TextColumn::make('title')
                    ->label(__('postings.columns.title'))
                    ->placeholder(__('postings.absent'))
                    // In to the posting's own screen, which is the record the
                    // title names and where deciding happens. Stated on the
                    // column as well as on the row so the cell a reader
                    // reaches for first is dressed as the link it is; the
                    // destination is the row's, so the two agree. The absent
                    // placeholder is a label for nothing, so it is left plain
                    // and the row underneath it carries the way in.
                    ->url(fn (Posting $record): ?string => $record->title === null
                        ? null
                        : self::getUrl('view', ['record' => $record]))
                    ->description(
                        fn (Posting $record): ?string => self::qualifiers($record),
                        position: 'below',
                    )
                    ->sortable()
                    ->wrap(),
                // Every row's way out to the page, whatever extraction made of
                // it: `url` is the one thing every capture carries.
                TextColumn::make('source_site')
                    ->label(__('postings.columns.source'))
                    ->url(fn (Posting $record): string => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('created_at')
                    ->label(__('postings.columns.captured'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('postings.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (PostingStatus $state): string => self::statusLabel($state)),
            ])
            ->filters([
                // The list opens on everything but `discarded`, stated as a
                // selection rather than as a rule hidden in the query: the
                // filter shows what it is doing and the indicator's own
                // control undoes it, so a discarded posting is one click away
                // rather than gone.
                SelectFilter::make('status')
                    ->label(__('postings.columns.status'))
                    ->multiple()
                    ->options(fn (): array => self::statusOptions())
                    ->default(fn (): array => self::statusesOfInterest())
                    // Filament resolves the record an action was clicked on
                    // through the filtered query, so a status the selection
                    // excludes is a row whose own actions refuse to run. The
                    // actions here are what *move* a posting's status: the
                    // row the owner just discarded is still on screen until
                    // the table re-renders, and asking it for its CV in that
                    // moment must not fail. Which postings the list answers
                    // with is the filter's question; which posting an action
                    // acts on is the row's.
                    ->excludeWhenResolvingRecord(),
                SelectFilter::make('source_site')
                    ->label(__('postings.columns.source'))
                    ->multiple()
                    // The sites the owner has actually captured from, rather
                    // than a list of boards held somewhere in configuration.
                    ->options(fn (): array => Posting::query()
                        ->distinct()
                        ->orderBy('source_site')
                        ->pluck('source_site', 'source_site')
                        ->all()),
            ])
            ->recordActions([
                self::wantAction(),
                self::downloadAction(),
                self::discardAction(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPostings::route('/'),
            // The page a row leads to. Filament would also point a record here
            // on its own, through whichever of `view` and `edit` a resource
            // registers and authorises; the list says where its rows go
            // instead, because where the list leads is the list's own decision
            // rather than a consequence of which pages happen to exist.
            'view' => ViewPosting::route('/{record}'),
        ];
    }

    /**
     * The facts that qualify a title, on one line under it: who is hiring,
     * where, and what it pays. Each is a reason to open a posting or to pass
     * over it, so they belong where the owner is choosing — and a line costs
     * none of the width three more columns would.
     *
     * A field the capture did not find is left out of the line rather than
     * placeheld: a line of dashes is noise, and the line already says what is
     * known. The title keeps its own placeholder, because that is the
     * column's value rather than a part of this line.
     */
    private static function qualifiers(Posting $posting): ?string
    {
        $known = array_filter(
            [$posting->company, $posting->location, $posting->salary_text],
            fn (?string $value): bool => filled($value),
        );

        if ($known === []) {
            return null;
        }

        return implode((string) __('postings.qualifier_separator'), $known);
    }

    /**
     * Marking a posting worth a CV, which is what `cv:queue` then picks up.
     *
     * Offered on a captured posting and on nothing else: every later state
     * already has a revision or is the owner's own verdict on one.
     *
     * The list and the posting's own screen both offer it, from here, so the
     * rule it is offered under exists once.
     */
    public static function wantAction(): Action
    {
        return Action::make('want')
            ->label(__('postings.actions.want'))
            ->icon(Heroicon::Check)
            ->button()
            ->visible(fn (Posting $record): bool => $record->status === PostingStatus::Captured)
            // Filament resolves a closure's typed parameters from the
            // container, which is how an action reaches a domain class on a
            // resource that has no constructor of its own.
            ->action(fn (Posting $record, MarkPostingWanted $mark) => $mark->handle($record));
    }

    /**
     * Discarding, from anywhere but `discarded`. A discarded posting is never
     * queued, whatever else was true of it.
     *
     * It asks first, and marking as wanted does not: nothing moves a posting
     * off `discarded`, so one misclick loses a job from the list for good,
     * where a posting marked as wanted is still free to discard.
     *
     * The modal is a heading and a button. Filament's `requiresConfirmation()`
     * adds an "are you sure" paragraph that cannot be turned off and that the
     * heading has already said, so the modal is raised by its heading — which
     * is what opens one — and dressed as the confirmation it is.
     *
     * The list and the posting's own screen both offer it, from here.
     */
    public static function discardAction(): Action
    {
        return Action::make('discard')
            ->label(__('postings.actions.discard'))
            ->icon(Heroicon::XMark)
            ->button()
            ->visible(fn (Posting $record): bool => $record->status !== PostingStatus::Discarded)
            ->modalHeading(__('postings.discard_confirmation.heading'))
            ->modalSubmitActionLabel(__('postings.discard_confirmation.confirm'))
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->action(fn (Posting $record, DiscardPosting $discard) => $discard->handle($record));
    }

    /**
     * The current revision's PDF, which is the thing the whole system is for.
     *
     * Offered from `pdf_path`, which is the system's own record that a PDF
     * exists — a render that failed deliberately leaves the column alone. The
     * null return is for a volume that has been replaced under a path that
     * still names a file.
     *
     * The posting's own screen embeds the PDF and offers this as well: reading
     * one and keeping one are different things.
     */
    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('postings.actions.download'))
            ->icon(Heroicon::ArrowDownTray)
            ->button()
            ->visible(fn (Posting $record): bool => $record->currentRevision?->pdf_path !== null)
            ->action(function (
                Posting $record,
                PdfStore $pdfs,
                PdfFilename $filenames,
            ): ?StreamedResponse {
                $revision = $record->currentRevision;
                $pdf = $revision === null ? null : $pdfs->read($revision);

                if ($pdf === null) {
                    return null;
                }

                return response()->streamDownload(
                    fn () => print $pdf,
                    $filenames->for($record),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    public static function statusLabel(PostingStatus $status): string
    {
        return (string) __('postings.statuses.'.$status->value);
    }

    /**
     * Every status, in the order the machine runs them, so the filter is not
     * a second list of states to keep in step with the enum.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (PostingStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status);
        }

        return $options;
    }

    /**
     * Every status the list opens on: all of them but `discarded`.
     *
     * Capture is free and speculative, so `discarded` is the status that
     * accumulates, and it is the only one that is uninteresting on its own —
     * everything else is live work or the record of work, and an applied
     * posting is the thing the system exists to produce. Derived from the enum
     * for the same reason the options are, so a status added later is one the
     * list opens on without this being edited.
     *
     * @return list<string>
     */
    private static function statusesOfInterest(): array
    {
        $statuses = [];

        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Discarded) {
                $statuses[] = $status->value;
            }
        }

        return $statuses;
    }
}

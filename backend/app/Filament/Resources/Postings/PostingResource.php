<?php

namespace App\Filament\Resources\Postings;

use App\Filament\Resources\Postings\Pages\ListPostings;
use App\Models\Posting;
use App\Postings\DiscardPosting;
use App\Postings\MarkPostingWanted;
use App\Postings\PostingStatus;
use App\Rendering\PdfStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
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
 * A listed field that capture did not find shows as absent. Capture omits
 * rather than guesses, and a list that printed an empty cell instead would
 * undo that — a blank cell cannot be told from a value that is blank.
 *
 * The resource has one page. Regenerating, editing and marking as applied
 * belong with the posting detail, which is a screen of its own.
 */
class PostingResource extends Resource
{
    protected static ?string $model = Posting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
            ->columns([
                TextColumn::make('title')
                    ->label(__('postings.columns.title'))
                    ->placeholder(__('postings.absent'))
                    ->wrap(),
                TextColumn::make('company')
                    ->label(__('postings.columns.company'))
                    ->placeholder(__('postings.absent')),
                TextColumn::make('location')
                    ->label(__('postings.columns.location'))
                    ->placeholder(__('postings.absent')),
                TextColumn::make('salary_text')
                    ->label(__('postings.columns.salary'))
                    ->placeholder(__('postings.absent')),
                TextColumn::make('source_site')
                    ->label(__('postings.columns.source')),
                TextColumn::make('created_at')
                    ->label(__('postings.columns.captured'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('postings.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (PostingStatus $state): string => self::statusLabel($state)),
                // A warning and nothing where there is nothing to warn about.
                // Hidden text in a listing is sometimes aimed at AI tools, so
                // the owner should know before trusting what was extracted.
                IconColumn::make('has_hidden_text')
                    ->label(__('postings.columns.hidden_text'))
                    // Filament reads a boolean cast as a tick-or-cross
                    // column. A cross on every ordinary posting says "no
                    // hidden text" once a row and warns about nothing, so
                    // the pair is turned off and only the warning is drawn.
                    ->boolean(false)
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::ExclamationTriangle : null)
                    ->color('warning'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('postings.columns.status'))
                    ->options(fn (): array => self::statusOptions()),
                SelectFilter::make('source_site')
                    ->label(__('postings.columns.source'))
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
        ];
    }

    /**
     * Marking a posting worth a CV, which is what `cv:queue` then picks up.
     *
     * Offered on a captured posting and on nothing else: every later state
     * already has a revision or is the owner's own verdict on one.
     */
    private static function wantAction(): Action
    {
        return Action::make('want')
            ->label(__('postings.actions.want'))
            ->icon(Heroicon::Check)
            ->visible(fn (Posting $record): bool => $record->status === PostingStatus::Captured)
            // Filament resolves a closure's typed parameters from the
            // container, which is how an action reaches a domain class on a
            // resource that has no constructor of its own.
            ->action(fn (Posting $record, MarkPostingWanted $mark) => $mark->handle($record));
    }

    /**
     * Discarding, from anywhere but `discarded`. A discarded posting is never
     * queued, whatever else was true of it.
     */
    private static function discardAction(): Action
    {
        return Action::make('discard')
            ->label(__('postings.actions.discard'))
            ->icon(Heroicon::XMark)
            ->visible(fn (Posting $record): bool => $record->status !== PostingStatus::Discarded)
            ->action(fn (Posting $record, DiscardPosting $discard) => $discard->handle($record));
    }

    /**
     * The current revision's PDF, which is the thing the whole system is for.
     *
     * Offered from `pdf_path`, which is the system's own record that a PDF
     * exists — a render that failed deliberately leaves the column alone. The
     * null return is for a volume that has been replaced under a path that
     * still names a file.
     */
    private static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('postings.actions.download'))
            ->icon(Heroicon::ArrowDownTray)
            ->visible(fn (Posting $record): bool => $record->currentRevision?->pdf_path !== null)
            ->action(function (Posting $record, PdfStore $pdfs): ?StreamedResponse {
                $revision = $record->currentRevision;
                $pdf = $revision === null ? null : $pdfs->read($revision);

                if ($pdf === null) {
                    return null;
                }

                return response()->streamDownload(
                    fn () => print $pdf,
                    (string) __('postings.pdf_filename', ['posting' => $record->id]),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    private static function statusLabel(PostingStatus $status): string
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
}

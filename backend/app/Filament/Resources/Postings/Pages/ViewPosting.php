<?php

namespace App\Filament\Resources\Postings\Pages;

use App\Filament\Resources\Postings\PostingResource;
use App\Generation\RequirementPriority;
use App\Generation\RequirementStatus;
use App\Http\Controllers\Review\PostingPdfController;
use App\MasterCv\RoleMode;
use App\Models\Posting;
use App\Models\Revision;
use App\Postings\MarkPostingApplied;
use App\Postings\MarkPostingReviewed;
use App\Postings\PostingStatus;
use App\Rendering\Html\InlineMarkdown;
use App\Review\Evidence;
use App\Review\Promotion;
use App\Review\ReadRevisionReview;
use App\Review\Requirement;
use App\Review\ReviewNotes;
use App\Review\RevisionReview;
use App\Validation\Finding;
use App\Validation\Severity;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * One posting, and whichever question it currently poses.
 *
 * **The page has two states and the posting decides which**, rather than the
 * owner picking a mode. A posting with no revision asks whether it is worth a
 * CV, so it shows the job and the decision about it. A posting with a current
 * revision asks whether that CV is good enough to send, so the review surface
 * is added to the same page. The question changes when the posting gains a
 * revision, and nothing the owner does to this screen changes which it is.
 *
 * It is a page rather than a `ViewRecord`, because what it shows is one
 * document read against another rather than a record's own fields, and because
 * a record form would dehydrate every attribute of the posting — the whole of
 * the captured page's text among them — into the browser in order to show none
 * of it.
 *
 * Everything here is a schema component. Filament ships its panel CSS prebuilt
 * from its own class names and this application has no front-end build to add
 * to it, so a hand-written template would have no styles to reach for. The one
 * exception is the frame the PDF is read in: no component embeds a document, so
 * that is an `Html` component holding a single element whose only styling is
 * its own size.
 */
class ViewPosting extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PostingResource::class;

    private ?RevisionReview $review = null;

    private ReadRevisionReview $read;

    private InlineMarkdown $markdown;

    /**
     * Livewire constructs its own components, so there is no constructor to
     * inject into. It resolves a lifecycle hook's parameters from the
     * container on every request, which is where the dependencies come from.
     */
    public function boot(ReadRevisionReview $read, InlineMarkdown $markdown): void
    {
        $this->read = $read;
        $this->markdown = $markdown;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * The job, named as the advert names it. A posting extraction has not
     * answered for carries the same placeholder the list gives it: neither the
     * page nor extraction guesses, and a heading printed empty cannot be told
     * from a job whose title is blank.
     */
    public function getTitle(): string|Htmlable
    {
        return $this->posting()->title ?? (string) __('postings.absent');
    }

    public function content(Schema $schema): Schema
    {
        // Every part of the page that reads the posting's status or its
        // revision is a closure. Filament caches a page's schema for the
        // request and a header action fires between the assembly and the
        // render, so a component built eagerly from a value an action changes
        // renders the value from before it — and the page looks as though the
        // action did nothing.
        return $schema->components([
            Section::make()->schema(fn (): array => $this->facts()),
            Callout::make()
                ->danger()
                ->icon(Heroicon::OutlinedEyeSlash)
                ->heading(__('postings.hidden_text'))
                ->visible(fn (): bool => $this->posting()->has_hidden_text),
            // The PDF beside the job description, because reviewing is reading
            // one against the other, and a download for every look is friction
            // on the one screen the owner will use most.
            //
            // The CV takes the larger share of the row. An advert is read down
            // a narrow column and a CV is not: the browser's own PDF viewer
            // puts its page thumbnails beside the page, so an even split
            // leaves the document itself too small to read a line of.
            Grid::make(['default' => 1, 'xl' => 5])->schema([
                Section::make()
                    ->heading(__('postings.sections.description'))
                    // The whole row where there is no PDF beside it: a posting
                    // with no revision, and one whose revision a blocking
                    // finding stopped, both leave the other column empty.
                    ->columnSpan(fn (): array => [
                        'xl' => $this->revision()?->pdf_path === null ? 5 : 2,
                    ])
                    ->schema(fn (): array => [$this->description()]),
                Section::make()
                    ->heading(__('postings.sections.cv'))
                    ->columnSpan(['xl' => 3])
                    ->visible(fn (): bool => $this->revision()?->pdf_path !== null)
                    ->schema(fn (): array => [Html::make($this->frame())]),
            ]),
            Section::make()
                ->heading(__('postings.sections.requirements'))
                ->visible(fn (): bool => $this->checklist() !== [])
                ->schema(fn (): array => $this->requirements()),
            // Full width and stacked rather than side by side: either can be
            // empty, and a two-column grid with one column in it leaves half a
            // row of nothing.
            Section::make()
                ->heading(__('postings.sections.findings'))
                ->visible(fn (): bool => $this->findings() !== [])
                ->schema(fn (): array => $this->findingsList()),
            Section::make()
                ->heading(__('postings.sections.notes'))
                ->visible(fn (): bool => ! $this->notes()->isEmpty())
                ->schema(fn (): array => $this->reviewNotes()),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Want, the two downloads and Discard come from the resource, so
            // the rules they are offered under are the list's own rather than
            // a second copy of them.
            $this->refreshing(PostingResource::wantAction()),
            $this->markReviewedAction(),
            $this->markAppliedAction(),
            PostingResource::downloadAction(),
            PostingResource::downloadWordAction(),
            $this->refreshing(PostingResource::discardAction()),
        ];
    }

    /**
     * The owner has read the CV.
     *
     * Offered on `ready` alone, which is where a posting arrives when its CV
     * is renderable: `applied` is past it and `discarded` is finished with, and
     * marking either would walk the tracker backwards.
     */
    private function markReviewedAction(): Action
    {
        return $this->refreshing(
            Action::make('markReviewed')
                ->label(__('postings.actions.mark_reviewed'))
                ->icon(Heroicon::BookOpen)
                ->button()
                ->visible(fn (Posting $record): bool => $record->status === PostingStatus::Ready)
                // Filament resolves a closure's typed parameters from the
                // container, which is how an action reaches a domain class on
                // a page that injects nothing of its own.
                ->action(fn (Posting $record, MarkPostingReviewed $mark) => $mark->handle($record)),
        );
    }

    /**
     * An application went in, recorded against the revision that went with it.
     *
     * Offered from `ready` as well as from `reviewed`: applying without
     * marking the CV read first is an ordinary thing to do.
     */
    private function markAppliedAction(): Action
    {
        return $this->refreshing(
            Action::make('markApplied')
                ->label(__('postings.actions.mark_applied'))
                ->icon(Heroicon::PaperAirplane)
                ->button()
                ->visible(fn (Posting $record): bool => in_array(
                    $record->status,
                    [PostingStatus::Ready, PostingStatus::Reviewed],
                    true,
                ))
                ->action(fn (Posting $record, MarkPostingApplied $mark) => $mark->handle($record)),
        );
    }

    /**
     * The posting as the database now holds it.
     *
     * Every one of these actions writes its status in a conditional update, so
     * the row is the only thing that knows where the posting got to — a page
     * holds one record where a table re-reads its rows, and without this the
     * posting on screen is the one from before the action.
     */
    private function refreshing(Action $action): Action
    {
        return $action->after(fn (Posting $record) => $record->refresh());
    }

    /**
     * What the posting says about the job, each fact under its own label.
     *
     * A field extraction did not answer for is placeheld rather than left out:
     * here it is a labelled entry's own value, and a blank cannot be told from
     * a value that is blank. The list leaves its qualifiers out of a line for
     * the opposite reason — there they are part of one line, not values.
     *
     * @return list<Component>
     */
    private function facts(): array
    {
        $posting = $this->posting();

        return [
            Grid::make(['default' => 2, 'sm' => 3, 'xl' => 6])->schema([
                $this->fact('company', $posting->company),
                $this->fact('location', $posting->location),
                $this->fact('salary', $posting->salary_text),
                // Out to the advert, in a new tab: reading the page again is
                // most of what deciding about a posting means, and `url` is
                // the one field every capture carries.
                $this->fact('source', $posting->source_site)
                    ->url($posting->url, shouldOpenInNewTab: true),
                $this->fact('captured', $posting->created_at?->format('j M Y, H:i')),
                TextEntry::make('status')
                    ->label(__('postings.columns.status'))
                    ->state(fn (): string => PostingResource::statusLabel($this->posting()->status))
                    ->badge(),
                // The owner asked for a cover letter, or they did not. Nothing
                // writes one; the fact is the record of the request.
                $this->fact('cover_letter', (string) __($posting->cover_letter_requested
                    ? 'postings.cover_letter_requested'
                    : 'postings.cover_letter_not_requested')),
            ]),
        ];
    }

    private function fact(string $name, ?string $value): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('postings.columns.'.$name))
            ->state($value)
            ->placeholder(__('postings.absent'));
    }

    /**
     * The job description as extraction read it off the page.
     *
     * The page's own words, so they are escaped before their line breaks are
     * put back: a listing is untrusted input, and the lists and paragraph
     * breaks extraction is told to keep are part of what it says.
     *
     * A posting extraction has not answered for shows the absent placeholder,
     * because the screen has to open either way — `php artisan cv:status` is
     * where a posting waiting on extraction is told apart from one whose
     * extraction failed.
     */
    private function description(): Component
    {
        $description = $this->posting()->description;

        return $description === null
            ? Text::make(__('postings.absent'))
            : Text::make(new HtmlString(nl2br(e($description))));
    }

    /**
     * The frame the CV is read in.
     *
     * A page's own proportions decide its height: the frame stands as tall
     * against its width as a sheet of paper does, whatever width the grid gives
     * it. A height in pixels would be right at one window size.
     */
    private function frame(): Htmlable
    {
        return new HtmlString(sprintf(
            '<iframe src="%s" title="%s" style="width:100%%;aspect-ratio:1/1.414;border:0"></iframe>',
            e(PostingPdfController::urlFor($this->posting())),
            e(__('postings.sections.cv')),
        ));
    }

    /**
     * The job's requirements, in the order the checklist gives them: `must`
     * before `nice`, and `gap` before `partial` before `met`. A gap against a
     * must-have is the single most useful thing on the page, so it is first.
     *
     * @return list<Component>
     */
    private function requirements(): array
    {
        $components = [];

        foreach ($this->checklist() as $requirement) {
            $components[] = Fieldset::make($requirement->requirement)
                ->columns(1)
                ->schema($this->requirement($requirement));
        }

        return $components;
    }

    /**
     * One requirement: what it costs to miss, what the CV has to say about it,
     * the model's sentence, and the master CV text behind every ID it cited.
     *
     * The two badges carry the order the list is in. Reading a checklist of a
     * dozen rows means seeing which of them need attention without reading
     * each, and colour is what does that.
     *
     * A requirement with no evidence shows none. A gap is expected to have
     * none, and that is the honest answer rather than a blank to fill.
     *
     * @return list<Component>
     */
    private function requirement(Requirement $requirement): array
    {
        $components = [
            Flex::make([
                Text::make(__('postings.priorities.'.$requirement->priority->value))
                    ->badge()
                    ->color($requirement->priority === RequirementPriority::Must ? 'primary' : 'gray')
                    ->grow(false),
                Text::make(__('postings.requirement_statuses.'.$requirement->status->value))
                    ->badge()
                    ->color(self::statusColour($requirement->status))
                    ->grow(false),
            ]),
        ];

        if ($requirement->note !== null) {
            $components[] = Text::make($requirement->note);
        }

        if ($requirement->evidence !== []) {
            // The master CV's own words, through the renderer the CV template
            // uses: a bullet carries a bold lead-in by the format's own
            // convention, and a screen that printed the asterisks would be
            // showing the owner something their CV does not say.
            $components[] = $this->list(array_map(
                fn (Evidence $evidence): Htmlable => $this->markdown->line($evidence->text),
                $requirement->evidence,
            ));
        }

        return $components;
    }

    private static function statusColour(RequirementStatus $status): string
    {
        return match ($status) {
            RequirementStatus::Gap => 'danger',
            RequirementStatus::Partial => 'warning',
            RequirementStatus::Met => 'success',
        };
    }

    /**
     * Validation's findings, as the checks wrote them, blocking first.
     *
     * A finding is the deterministic checks' own account of the revision, and
     * the checks are what carry the fabrication guarantee: a summary of them
     * would be a weaker claim than the thing itself. So nothing here shortens,
     * rewords or drops one, and the severity it was raised at is beside it.
     *
     * A revision with no findings shows none. On screen that is the whole of
     * what there is to say — in the data, an empty report and an absent one are
     * never the same thing, which is `ValidationReport`'s business.
     *
     * @return list<Component>
     */
    private function findingsList(): array
    {
        $components = [];

        foreach ($this->findings() as $finding) {
            $components[] = Flex::make([
                Text::make(__('postings.severities.'.$finding->severity->value))
                    ->badge()
                    ->color($finding->severity === Severity::Blocking ? 'danger' : 'warning')
                    ->grow(false),
                Text::make($finding->message),
            ]);
        }

        return $components;
    }

    /**
     * The model's notes for the owner, in the model's own words.
     *
     * @return list<Component>
     */
    private function reviewNotes(): array
    {
        $notes = $this->notes();
        $components = [];

        if ($notes->gaps !== []) {
            $components[] = $this->notesGroup('gaps', $notes->gaps);
        }

        if ($notes->judgementCalls !== []) {
            $components[] = $this->notesGroup('judgement_calls', $notes->judgementCalls);
        }

        if ($notes->promotions !== []) {
            $components[] = $this->notesGroup('promotions', array_map(
                fn (Promotion $promotion): string => $this->promotion($promotion),
                $notes->promotions,
            ));
        }

        return $components;
    }

    /**
     * @param  list<string>  $sentences
     */
    private function notesGroup(string $heading, array $sentences): Component
    {
        return Fieldset::make(__('postings.sections.'.$heading))
            ->columns(1)
            ->schema([$this->list($sentences)]);
    }

    /**
     * A role shown in more detail than the presentation policy's default, and
     * the model's reason for it. The sentence is the model's; the line in
     * front of it only says what the reason is a reason for.
     */
    private function promotion(Promotion $promotion): string
    {
        $line = (string) __('postings.promotion', [
            'role' => $promotion->role,
            'to' => $this->mode($promotion->to),
            'from' => $this->mode($promotion->from),
        ]);

        return trim($line.' '.$promotion->reason);
    }

    private function mode(?RoleMode $mode): string
    {
        return (string) ($mode === null
            ? __('postings.absent')
            : __('postings.role_modes.'.$mode->value));
    }

    /**
     * @param  list<string|Htmlable>  $items
     */
    private function list(array $items): Component
    {
        // The component lays short items out in two columns. These are
        // sentences, so they get the width.
        return UnorderedList::make($items)->columns(['default' => 1, 'sm' => 1]);
    }

    private function posting(): Posting
    {
        /** @var Posting $posting */
        $posting = $this->getRecord();

        return $posting;
    }

    private function revision(): ?Revision
    {
        return $this->posting()->currentRevision;
    }

    /**
     * @return list<Requirement>
     */
    private function checklist(): array
    {
        $review = $this->review();

        return $review === null ? [] : $review->requirements;
    }

    /**
     * @return list<Finding>
     */
    private function findings(): array
    {
        $report = $this->review()?->findings;

        return $report === null ? [] : $report->findings;
    }

    private function notes(): ReviewNotes
    {
        $review = $this->review();

        return $review === null ? ReviewNotes::none() : $review->notes;
    }

    /**
     * What the screen makes of the current revision, read once per request.
     *
     * Memoised rather than remembered: the memo lives as long as one request,
     * so nothing an action does can be a request behind what the page renders.
     */
    private function review(): ?RevisionReview
    {
        $revision = $this->revision();

        if ($revision === null) {
            return null;
        }

        return $this->review ??= $this->read->handle($revision);
    }
}

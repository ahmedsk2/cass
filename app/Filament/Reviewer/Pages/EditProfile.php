<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Pages;

use App\Actions\Reviewers\UpdateReviewerAffiliation;
use App\Exceptions\MemberChangeRefused;
use App\Models\ConferenceReviewer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * The reviewer panel's `->profile()` page: Filament's own (name, email,
 * password, two-factor) plus one section listing every conference this person
 * actively reviews, each with its own affiliation and a "Change" action.
 *
 * A table rather than a second form: one save per conference is one policy
 * check, one activity entry and one sentence of feedback, and the record key
 * resolves only through this table's own query - so another reviewer's row, or
 * this reviewer's removed one, is not a row this page can reach.
 *
 * Not discovered as a page of its own: the parent sets `$isDiscovered = false`
 * (vendor/filament/filament/src/Auth/Pages/EditProfile.php:61), so living in
 * the discovered Pages directory registers its Livewire component and nothing
 * else. ReviewerPanelProvider names it in `->profile()`.
 */
class EditProfile extends BaseEditProfile implements HasTable
{
    use InteractsWithTable;

    /**
     * The parent's two components, in the parent's order
     * (vendor/filament/filament/src/Auth/Pages/EditProfile.php:508-515), and
     * then the affiliations. Restated rather than read back from the parent's
     * schema: Schema::getComponents() configures what it returns, and handing
     * configured components back to components() configures them twice.
     * ReviewerAffiliationTest pins the parent method's source, so a Filament
     * upgrade that adds a third component is a red test, not a section that
     * quietly never renders on this one panel.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getAffiliationsContentComponent(),
            ]);
    }

    public function getAffiliationsContentComponent(): Component
    {
        return Section::make(__('reviewer.affiliation.heading'))
            ->description(__('reviewer.affiliation.description'))
            ->compact()
            ->schema([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->affiliationsQuery())
            // Without these Filament derives the table's aria-label from the
            // model's class name: "conference reviewers", in English, in
            // every locale.
            ->modelLabel(__('reviewer.affiliation.model'))
            ->pluralModelLabel(__('reviewer.affiliation.model_plural'))
            ->columns([
                TextColumn::make('conference.name')
                    ->label(__('reviewer.queue.columns.conference'))
                    ->weight('semibold')
                    // The simple profile layout is 32rem wide; a long
                    // conference name wraps rather than scrolling the table.
                    ->wrap()
                    // `->`, not `?->`, on the left of `??`: the same isset-mode
                    // shape InviteReviewer::placeholderValues() uses, because
                    // Organization soft-deletes while its conferences survive.
                    ->description(fn (ConferenceReviewer $record): string => (string) ($record->conference->organization->name ?? '')),
                TextColumn::make('affiliation')
                    ->label(__('reviewer.fields.affiliation'))
                    ->placeholder(__('reviewer.affiliation.none'))
                    ->wrap(),
            ])
            ->recordActions([
                $this->editAffiliationAction(),
            ])
            ->emptyStateHeading(__('reviewer.affiliation.empty_heading'))
            ->emptyStateDescription(__('reviewer.affiliation.empty_body'))
            ->paginated(false);
    }

    /**
     * This person's ACTIVE rows, in conferences that still exist - the same
     * set the dashboard lists (Dashboard::getConferences() walks
     * `activeReviewers` from a non-trashed Conference). `whereHas('conference')`
     * is what drops a soft-deleted conference: the relation subquery carries
     * Conference's SoftDeletes scope.
     *
     * @return Builder<ConferenceReviewer>
     */
    private function affiliationsQuery(): Builder
    {
        return ConferenceReviewer::query()
            ->where('user_id', $this->reviewer()->getKey())
            ->active()
            ->whereHas('conference')
            ->with('conference.organization')
            ->orderBy('id');
    }

    private function editAffiliationAction(): Action
    {
        return Action::make('editAffiliation')
            ->label(__('reviewer.affiliation.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading(fn (ConferenceReviewer $record): string => __('reviewer.affiliation.edit_heading', [
                'conference' => (string) ($record->conference->name ?? ''),
            ]))
            ->modalDescription(__('reviewer.affiliation.edit_description'))
            ->fillForm(fn (ConferenceReviewer $record): array => ['affiliation' => $record->affiliation])
            ->schema([
                // The organizer's invite form declares the same field the same
                // way (ConferenceReviewers::getHeaderActions(), `invite`).
                TextInput::make('affiliation')
                    ->label(__('reviewer.fields.affiliation'))
                    ->maxLength(UpdateReviewerAffiliation::MAX_LENGTH)
                    ->helperText(__('reviewer.affiliation.help')),
            ])
            ->visible(fn (ConferenceReviewer $record): bool => Gate::allows('updateAffiliation', $record))
            ->action(function (ConferenceReviewer $record, array $data, UpdateReviewerAffiliation $update): void {
                // No Gate::authorize() here: the action asks the policy itself,
                // and a refusal is a sentence, not a 403 modal.
                try {
                    $update->handle(
                        $record,
                        $data['affiliation'] === null ? null : (string) $data['affiliation'],
                        $this->reviewer(),
                    );
                } catch (MemberChangeRefused $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('reviewer.affiliation.saved'))->send();
            });
    }

    private function reviewer(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}

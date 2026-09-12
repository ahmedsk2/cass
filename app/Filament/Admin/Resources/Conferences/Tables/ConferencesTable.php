<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Tables;

use App\Actions\Conferences\PurgeConference;
use App\Enums\ConferenceStatus;
use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ConferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Abstracts')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                // Read-only, like everything else on this screen. `counts()`
                // reaches the query as `$query->withCount(Arr::wrap(...))`
                // (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:24-25),
                // and Arr::wrap leaves an associative array alone - so Laravel's
                // `['relation as alias' => Closure]` form works and both counts
                // ride along on the list's own query rather than costing a
                // query per row.
                TextColumn::make('decided_count')
                    ->counts(['submissions as decided_count' => fn (Builder $query): Builder => $query->whereNotNull('decision')])
                    ->label('Decided')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('notified_count')
                    ->counts(['submissions as notified_count' => fn (Builder $query): Builder => $query->whereNotNull('decision_notified_at')])
                    ->label('Letters sent')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Restores the soft delete an organizer made. There is no
                // ForceDeleteAction anywhere: ConferencePolicy::before() makes
                // forceDelete true for a platform admin, and Filament's own
                // action would call $record->forceDelete() straight into four
                // RESTRICT keys. The hard purge below cascades in application
                // code instead (spec section 3).
                RestoreAction::make(),
                static::purgeAction(),
            ]);
    }

    /**
     * Spec section 3's hard purge. NOT a ForceDeleteAction: Filament's own
     * calls $record->forceDelete() and hits four RESTRICT keys. This one calls
     * PurgeConference, which deletes the tree in application code.
     */
    public static function purgeAction(): Action
    {
        return Action::make('purge')
            ->label(__('admin.purge.action'))
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->authorize(fn (Conference $record): bool => Gate::allows('purge', $record))
            ->modalHeading(fn (Conference $record): string => __('admin.purge.conference_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.purge.intro'))
            ->modalSubmitActionLabel(__('admin.purge.submit'))
            // Counted for the modal and counted again by the run. Two reads of
            // the same tables a second apart is cheaper than a confirmation
            // nobody can check.
            ->modalContent(fn (Conference $record) => view('filament.admin.partials.purge-counts', [
                'counts' => app(PurgeConference::class)->preview($record),
            ]))
            ->schema([
                TextInput::make('confirmation')
                    ->label(fn (Conference $record): string => __('admin.purge.confirm_label', ['word' => (string) $record->slug]))
                    ->helperText(__('admin.purge.confirm_help'))
                    ->required()
                    ->rule(static::confirmationRule()),
            ])
            ->action(function (Conference $record): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $name = (string) $record->name;
                $counts = app(PurgeConference::class)->handle($record, $user);
                $files = $counts['private files'] ?? 0;
                unset($counts['private files']);

                Notification::make()->success()->title(__('admin.purge.done', [
                    'name' => $name,
                    'rows' => number_format(array_sum($counts)),
                    'files' => number_format($files),
                ]))->send();
            })
            ->successRedirectUrl(fn (): string => ConferenceResource::getUrl('index', panel: 'admin'));
    }

    /**
     * The double-closure shape ConferenceEmailTemplates::editAction() uses, for
     * the same reason: Filament evaluates a Closure rule as a callback, so an
     * unwrapped Illuminate rule closure throws BindingResolutionException on
     * its $attribute parameter. The record reaches the inner closure through
     * the outer one's injected argument.
     */
    protected static function confirmationRule(): Closure
    {
        return static fn (Conference $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (! is_string($value) || trim($value) !== (string) $record->slug) {
                $fail(__('admin.purge.confirm_mismatch', ['word' => (string) $record->slug]));
            }
        };
    }
}

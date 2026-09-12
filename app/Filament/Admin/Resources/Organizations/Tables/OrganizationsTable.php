<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Tables;

use App\Actions\Organizations\ApproveOrganization;
use App\Actions\Organizations\PurgeOrganization;
use App\Actions\Organizations\RejectOrganization;
use App\Enums\OrganizationStatus;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (Organization $record): string => $record->slug),
                TextColumn::make('type')->badge(),
                TextColumn::make('country')->formatStateUsing(fn (string $state): string => config('cass.countries')[$state] ?? $state),
                TextColumn::make('owners.email')->label('Owner')->listWithLineBreaks(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->label('Registered')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrganizationStatus::class)->default(OrganizationStatus::Pending->value),
            ])
            ->recordActions([
                ViewAction::make(),
                static::approveAction(),
                static::rejectAction(),
                static::purgeAction(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this organization?')
            ->modalDescription('The owner will be emailed and can publish conferences immediately.')
            ->visible(fn (Organization $record): bool => $record->status !== OrganizationStatus::Approved)
            ->action(function (Organization $record, ApproveOrganization $approve): void {
                /** @var User $admin */
                $admin = auth()->user();
                $approve->handle($record, $admin);
                Notification::make()->success()->title("{$record->name} approved")->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Organization $record): bool => $record->status !== OrganizationStatus::Suspended)
            ->schema([
                Textarea::make('reason')->label('Reason sent to the owner')->required()->minLength(10)->maxLength(500)->rows(3),
            ])
            ->action(function (Organization $record, array $data, RejectOrganization $reject): void {
                /** @var User $admin */
                $admin = auth()->user();
                $reject->handle($record, $admin, $data['reason']);
                Notification::make()->warning()->title("{$record->name} rejected")->send();
            });
    }

    /**
     * Spec section 3's hard purge, the tenant-wide half. NOT a
     * ForceDeleteAction: OrganizationPolicy answers forceDelete false for
     * everybody so Filament can never surface one, because it would call
     * $record->forceDelete() straight into the RESTRICT key on
     * conferences.organization_id. This one calls PurgeOrganization, which
     * walks every conference through PurgeConference and then the
     * organization's own rows.
     *
     * It deliberately does not gate on `is_demo`: that flag is the demo
     * command's safety story, not a platform admin's. The typed slug is this
     * action's confirmation.
     */
    public static function purgeAction(): Action
    {
        return Action::make('purge')
            ->label(__('admin.purge.action'))
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->authorize(fn (Organization $record): bool => Gate::allows('purge', $record))
            ->modalHeading(fn (Organization $record): string => __('admin.purge.organization_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.purge.intro'))
            ->modalSubmitActionLabel(__('admin.purge.submit'))
            // Counted for the modal and counted again by the run. Two reads of
            // the same tables a second apart is cheaper than a confirmation
            // nobody can check.
            ->modalContent(fn (Organization $record) => view('filament.admin.partials.purge-counts', [
                'counts' => app(PurgeOrganization::class)->preview($record),
            ]))
            ->schema([
                TextInput::make('confirmation')
                    ->label(fn (Organization $record): string => __('admin.purge.confirm_label', ['word' => (string) $record->slug]))
                    ->helperText(__('admin.purge.confirm_help'))
                    ->required()
                    ->rule(static::confirmationRule()),
            ])
            ->action(function (Organization $record): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $name = (string) $record->name;
                $counts = app(PurgeOrganization::class)->handle($record, $user);
                $files = $counts['private files'] ?? 0;
                unset($counts['private files']);

                Notification::make()->success()->title(__('admin.purge.done', [
                    'name' => $name,
                    'rows' => number_format(array_sum($counts)),
                    'files' => number_format($files),
                ]))->send();
            })
            ->successRedirectUrl(fn (): string => OrganizationResource::getUrl('index', panel: 'admin'));
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
        return static fn (Organization $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (! is_string($value) || trim($value) !== (string) $record->slug) {
                $fail(__('admin.purge.confirm_mismatch', ['word' => (string) $record->slug]));
            }
        };
    }
}

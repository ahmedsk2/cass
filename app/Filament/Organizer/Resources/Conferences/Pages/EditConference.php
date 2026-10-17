<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Conferences\UpdateConference;
use App\Exceptions\ConferenceUpdateRefused;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Tables\ConferenceStatusActions;
use App\Models\Conference;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditConference extends EditRecord
{
    protected static string $resource = ConferenceResource::class;

    /** Not public: asked afresh on every request and never sent to the browser. */
    private ?bool $reviewSettingsLocked = null;

    /** Once per request for the form, which asks often; UpdateConference asks again before saving. */
    public function reviewSettingsLocked(): bool
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $this->reviewSettingsLocked ??= UpdateConference::reviewSettingsLocked($conference);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...ConferenceStatusActions::all(),
            DeleteAction::make(),
        ];
    }

    /**
     * Start reviewing sits in this header, so the lock can engage while the
     * form holds unsaved review settings: shown greyed out, they would refuse
     * every later save. Ask again, and show the stored values once locked.
     */
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        $this->reviewSettingsLocked = null;

        if ($this->record instanceof Model && $this->reviewSettingsLocked()) {
            $this->refreshFormData(['review_mode', 'blind_review']);
        }
    }

    /**
     * UpdateConference rather than the parent's update(): it refuses a locked
     * review setting and names the organizer as causer. A refusal is a
     * notification; Halt ends save() without "Saved" or the redirect.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Conference $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(UpdateConference::class)->handle($record, $data, $actor);
        } catch (ConferenceUpdateRefused $exception) {
            // Back to the stored values, so the next save keeps the other edits.
            $this->reviewSettingsLocked = null;
            $this->refreshFormData(['review_mode', 'blind_review']);

            Notification::make()->danger()
                ->title(__('organizer.update.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}

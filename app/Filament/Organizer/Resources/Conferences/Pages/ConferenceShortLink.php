<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Enums\PosterSize;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ConferenceShortLink extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.short-link';

    /**
     * resolveRecord() runs through ConferenceResource::getEloquentQuery(),
     * which carries the panel's tenancy global scope, so a conference from
     * another organization is already a 404 here. The explicit policy check
     * is defence in depth.
     */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return 'Share and print';
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    /**
     * Days are cut in the conference's own timezone, so the chart lines up
     * with the deadline printed beside it rather than with UTC.
     *
     * @return array<string, int>
     */
    public function getDailyScans(): array
    {
        $conference = $this->getConference();

        return $conference->shortLink?->dailyVisitCounts(30, $conference->timezone) ?? [];
    }

    protected function getHeaderActions(): array
    {
        $conference = $this->getConference();

        if ($conference->shortLink === null) {
            return [];
        }

        return [
            Action::make('download_svg')
                ->label('QR (SVG)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('conference-assets.qr.svg', $conference)),
            Action::make('download_png')
                ->label('QR (PNG)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('conference-assets.qr.png', $conference)),
            Action::make('download_poster_a4')
                ->label('Poster A4')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(route('conference-assets.poster', ['conference' => $conference, 'size' => PosterSize::A4->value])),
            Action::make('download_poster_a3')
                ->label('Poster A3')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(route('conference-assets.poster', ['conference' => $conference, 'size' => PosterSize::A3->value])),
        ];
    }
}

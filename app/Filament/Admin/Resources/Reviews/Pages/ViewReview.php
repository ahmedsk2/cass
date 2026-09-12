<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewReview extends ViewRecord
{
    protected static string $resource = ReviewResource::class;

    /**
     * The answers, in the form's order, for the ONE review this page shows.
     * They are loaded here rather than on the resource's shared query because
     * that query is also the index table's, where no answer is ever rendered.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'answers' => ReviewResource::eagerLoadAnswersInFormOrder(...),
        ]);
    }

    /**
     * Empty on purpose, and asserted by the test. The platform admin reads a
     * review and never rewrites one - reopening belongs to the reviewer and to
     * the organizer who asks for it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

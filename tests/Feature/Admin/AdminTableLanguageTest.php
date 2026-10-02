<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\RelationManagers\ReviewersRelationManager;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Submissions\RelationManagers\ReviewsRelationManager;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The admin panel's ten tables: five resource lists and five relation
 * managers. Plan 6 wrote seven of them with lang/ keys; the three Plans 1 and 2
 * wrote - conferences, organizations and the email log - spelled their
 * headings out, and eight of their columns and all three status filters had no
 * label at all, so Filament made one up from the name ("Name", "Status",
 * "Type", "Country", "Subject").
 * A made-up label is English that no literal holds, which is why the static
 * sweep in tests/Feature/LanguageCoverageTest.php cannot see it and this file
 * mounts the tables instead.
 */
beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

/**
 * Every word an admin table prints around its rows: column labels and
 * placeholders, filter labels, the heading and the empty state. Keyed by kind
 * and name, so a column and a filter that share a name cannot overwrite each
 * other.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $parameters
 * @return array<string, string>
 */
function adminTableWords(string $component, array $parameters = []): array
{
    $table = livewire($component, $parameters)->instance()->getTable();
    $words = [];

    foreach ($table->getColumns() as $name => $column) {
        $words["column {$name}"] = (string) $column->getLabel();

        if (filled($placeholder = $column->getPlaceholder())) {
            $words["placeholder {$name}"] = (string) $placeholder;
        }
    }

    foreach ($table->getFilters() as $name => $filter) {
        $words["filter {$name}"] = (string) $filter->getLabel();
    }

    if (filled($heading = $table->getHeading())) {
        $words['heading'] = (string) $heading;
    }

    $words['empty state'] = (string) $table->getEmptyStateHeading();

    return $words;
}

dataset('admin tables', [
    'conferences' => [ListConferences::class, []],
    'organizations' => [ListOrganizations::class, []],
    'email log' => [ListEmailLogs::class, []],
    'abstracts' => [ListSubmissions::class, []],
    'reviews' => [ListReviews::class, []],
    'conference reviewers' => [ReviewersRelationManager::class, fn (): array => [
        'ownerRecord' => Conference::factory()->create(),
        'pageClass' => ViewConference::class,
    ]],
    'organization members' => [MembersRelationManager::class, fn (): array => [
        'ownerRecord' => Organization::factory()->create(),
        'pageClass' => ViewOrganization::class,
    ]],
    'organization invitations' => [InvitationsRelationManager::class, fn (): array => [
        'ownerRecord' => Organization::factory()->create(),
        'pageClass' => ViewOrganization::class,
    ]],
    'abstract reviews' => [ReviewsRelationManager::class, fn (): array => [
        'ownerRecord' => Submission::factory()->create(),
        'pageClass' => ViewSubmission::class,
    ]],
    'abstract assignments' => [AssignmentsRelationManager::class, fn (): array => [
        'ownerRecord' => Submission::factory()->create(),
        'pageClass' => ViewSubmission::class,
    ]],
]);

it('looks every heading of every admin table up in a language file', function (string $component, array $parameters) {
    // A locale with no language file and no fallback, so __() hands back the
    // key it was given: a word that comes back as a key was looked up, one that
    // comes back as English was spelled out or made up. Filament's own words
    // come back as `filament-tables::…` keys and pass - Filament ships its own
    // translations. A word with no letters at all ("-") is not language.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    $english = array_filter(
        adminTableWords($component, $parameters),
        static fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1
            && preg_match('/^[a-z0-9_-]+(::[a-z0-9_-]+)?(\.[a-z0-9_]+)+$/', $word) !== 1,
    );

    expect($english)->toBe([]);
})->with('admin tables');

it('prints the same english headings on the three tables plans 1 and 2 wrote', function (string $component, array $expected) {
    // Byte for byte what these tables printed before the sweep, including the
    // labels Filament made up from a column name - "Name", "Status", "Type",
    // "Country", "Subject" - which are explicit keys now and must read the
    // same.
    // Left out: Filament's own TrashedFilter, and the empty state of the two
    // tables that never set one - both are Filament's words, not this table's.
    $words = adminTableWords($component);
    unset($words['filter trashed']);

    if (! array_key_exists('empty state', $expected)) {
        unset($words['empty state']);
    }

    expect($words)->toBe($expected);
})->with([
    'conferences' => [ListConferences::class, [
        'column name' => 'Name',
        'column organization.name' => 'Organization',
        'column status' => 'Status',
        'column submissions_count' => 'Abstracts',
        'column decided_count' => 'Decided',
        'column notified_count' => 'Letters sent',
        'column submission_deadline' => 'Deadline',
        'placeholder submission_deadline' => 'Not set',
        'column created_at' => 'Created',
        'filter status' => 'Status',
    ]],
    'organizations' => [ListOrganizations::class, [
        'column name' => 'Name',
        'column type' => 'Type',
        'column country' => 'Country',
        'column owners.email' => 'Owner',
        'column status' => 'Status',
        'column created_at' => 'Registered',
        'filter status' => 'Status',
    ]],
    'email log' => [ListEmailLogs::class, [
        'column created_at' => 'Queued',
        'column to_email' => 'To',
        'column subject' => 'Subject',
        'column status' => 'Status',
        'column template_key' => 'Template',
        'placeholder template_key' => '-',
        'column mailable' => 'Sent by',
        'column organization.name' => 'Organization',
        'placeholder organization.name' => 'Platform',
        'column sent_at' => 'Sent',
        'placeholder sent_at' => '-',
        'filter status' => 'Status',
        'filter organization_id' => 'Organization',
        'empty state' => 'Nothing sent yet',
    ]],
]);

it('says the same english when an organization is approved or rejected', function () {
    // The two review actions live in OrganizationsTable beside its columns,
    // and ViewOrganization's header reuses them. Their notifications carried
    // the name by interpolation ("{$record->name} approved") and carry it
    // through a :name placeholder now; the sentence must not move.
    Mail::fake();

    $first = Organization::factory()->create(['name' => 'Gulf Pediatric Society']);
    $second = Organization::factory()->create(['name' => 'Coastal Paediatric Society']);

    livewire(ListOrganizations::class)
        ->assertTableActionHasLabel('approve', 'Approve', $first)
        ->assertTableActionHasLabel('reject', 'Reject', $first)
        ->mountTableAction('approve', $first)
        ->assertMountedActionModalSee([
            'Approve this organization?',
            'The owner will be emailed and can publish conferences immediately.',
        ])
        ->callMountedTableAction()
        ->assertNotified('Gulf Pediatric Society approved');

    livewire(ListOrganizations::class)
        ->mountTableAction('reject', $second)
        ->assertMountedActionModalSee('Reason sent to the owner')
        ->setTableActionData(['reason' => 'We could not verify this society.'])
        ->callMountedTableAction()
        ->assertNotified('Coastal Paediatric Society rejected');
});

it('escapes an organization name in the approve notification', function () {
    // Not a pin: this one fails on the old code, which interpolated the name
    // raw. An anonymous registrant chooses the name, and Filament renders a
    // notification title through sanitizeHtml(), which keeps style and class.
    // ConferenceStatusActions escapes a conference name the same way.
    Mail::fake();

    $organization = Organization::factory()->create(['name' => 'A <b style="x">B</b>']);

    livewire(ListOrganizations::class)
        ->callTableAction('approve', $organization)
        ->assertNotified('A &lt;b style=&quot;x&quot;&gt;B&lt;/b&gt; approved');
});

it('still forbids an organizer every admin list', function (string $component) {
    // The negative case every panel test carries. The admin panel has no
    // tenant to cross, so the boundary is the platform-admin flag: an
    // organization owner - who can see the same conferences in their own
    // panel - mounts none of these.
    $owner = User::factory()->create();
    Organization::factory()->approved()->create()->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);

    livewire($component)->assertForbidden();
})->with([
    'conferences' => ListConferences::class,
    'organizations' => ListOrganizations::class,
    'email log' => ListEmailLogs::class,
    'abstracts' => ListSubmissions::class,
    'reviews' => ListReviews::class,
]);

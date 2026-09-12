<?php

declare(strict_types=1);

use App\Actions\Submissions\ExportRankingCsv;
use App\Actions\Submissions\ExportRankingXlsx;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Scoring\RankedSubmissions;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
    ]);
    $this->track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);

    $this->submission = Submission::factory()->for($this->conference)->scored(91.25, 2.5, 3)
        ->withCorrespondingAuthor('sara@example.org', 'Dr Sara Al-Harbi')
        ->create(['title' => 'Early mobilisation after cardiac surgery', 'track_id' => $this->track->id]);
    $this->submission->forceFill([
        'reference' => 'AAM26-001',
        'decision' => Decision::AcceptedOral,
    ])->save();
});

it('exports the visible rows as csv, with the numbers and the authors', function () {
    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-001')
        ->toContain('Early mobilisation after cardiac surgery')
        ->toContain('Neurocritical care')
        // Numbers are written as NUMBERS, not as the decimal:2 strings the
        // casts hand back. SpreadsheetCell::number() gives openspout a real
        // float so the spreadsheet can sort and chart the column, and openspout
        // stringifies a NumericCell with `(string) $value->getValue()`
        // (vendor/openspout/openspout/src/Writer/CSV/Writer.php:69) - so 91.25
        // is written "91.25" and 2.50 is written "2.5". Asserting '2.50' here
        // would push the implementer into formatting the cell as text, which is
        // the one thing this column must not be.
        ->toContain('91.25')
        ->toContain(',2.5,')
        ->toContain('Dr Sara Al-Harbi')
        ->toContain('sara@example.org')
        ->toContain(Decision::AcceptedOral->getLabel())
        // The BOM, so Excel opens UTF-8 without mangling an Arabic affiliation.
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('exports only the rows the filters left on screen', function () {
    $other = Submission::factory()->for($this->conference)->scored(40.0)->create(['title' => 'A different abstract']);
    $other->forceFill(['reference' => 'AAM26-002'])->save();

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->filterTable('decision', Decision::AcceptedOral->value)
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->toContain('AAM26-001')->not->toContain('AAM26-002');
});

it('writes both files in the ranking order on screen, not in submission-id order', function () {
    // The higher-scoring abstract is created LAST, so its id is the larger one:
    // a file in `submissions.id` order puts it second and a ranked file puts it
    // first. Nothing else in this file asserts an order, and the writers'
    // ->reorder()->chunkById() silently dropped the table's ORDER BY score DESC
    // and replaced it with ORDER BY submissions.id ASC - so the file an
    // organizer downloaded from a ranking screen was not ranked.
    $top = Submission::factory()->for($this->conference)->scored(99.0)
        ->create(['title' => 'The best abstract', 'track_id' => $this->track->id]);
    $top->forceFill(['reference' => 'AAM26-002'])->save();

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect(strpos($csv, 'AAM26-002'))->toBeInt()
        ->and(strpos($csv, 'AAM26-002'))->toBeLessThan((int) strpos($csv, 'AAM26-001'));

    // The XLSX writer is a second copy of the same loop, so it needs the same
    // assertion or only half the bug is pinned.
    $workbook = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportXlsx')
        ->assertFileDownloaded();

    $path = sys_get_temp_dir().'/cass-ranking-order-'.bin2hex(random_bytes(6)).'.xlsx';
    file_put_contents($path, (string) base64_decode((string) data_get($workbook->effects, 'download.content'), true));

    try {
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        expect(strpos($sheet, 'AAM26-002'))->toBeInt()
            ->and(strpos($sheet, 'AAM26-002'))->toBeLessThan((int) strpos($sheet, 'AAM26-001'));
    } finally {
        @unlink($path);
    }
});

it('scopes the file itself, whatever query it is handed', function () {
    // Every other case in this file feeds the action the page's own already
    // scoped query or RankedSubmissions::query($conference), so
    // RankedSubmissions::constrain() inside the writer - the line the docblock
    // calls the RULE rather than the convenience - could be deleted with the
    // whole suite still green, and the draft/withdrawn exclusion is proved
    // only for the table. This hands it a deliberately unscoped builder.
    $stranger = withoutTenant(fn (): Submission => Submission::factory()->scored(99.0)
        ->create(['title' => 'Another conference abstract']));

    Submission::factory()->for($this->conference)->create(['title' => 'An unfinished draft']);
    Submission::factory()->for($this->conference)->withdrawn()->create(['title' => 'A withdrawn abstract']);

    $response = app(ExportRankingCsv::class)->handle(Submission::query(), $this->conference, 'ranking.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($stranger->conference_id)->not->toBe($this->conference->id)
        ->and($csv)->toContain('AAM26-001')
        ->not->toContain('Another conference abstract')
        ->not->toContain('An unfinished draft')
        ->not->toContain('A withdrawn abstract');
});

it('never exports another conference, whatever the filters say', function () {
    $other = Conference::factory()->for($this->organization)->closed()->create();
    $theirs = Submission::factory()->for($other)->scored(99.0)->create(['title' => 'Winter abstract']);

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded();

    $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

    expect($csv)->not->toContain('Winter abstract');
});

it('names both files after the conference and the moment they were taken', function () {
    Carbon::setTestNow('2026-09-13 08:30:00');

    // In the CONFERENCE's zone, which is Asia/Riyadh here: 08:30 UTC is 11:30
    // there. The `Submitted at` and `Decision letter sent` columns inside the
    // file are rendered in that same zone (RankingRows::row), so stamping the
    // name in config('app.timezone') produced a file whose name disagreed with
    // its own contents by the offset.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportCsv')
        ->assertFileDownloaded('ranking-alpha-annual-meeting-2026-09-13-113000.csv');

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('exportXlsx')
        ->assertFileDownloaded('ranking-alpha-annual-meeting-2026-09-13-113000.xlsx');

    Carbon::setTestNow();
});

it('writes a real workbook whose scores are numbers and whose titles are not formulas', function () {
    // The case this whole guard exists for, and it is worse in XLSX than in
    // CSV: openspout's Cell::fromValue() returns a FormulaCell for a leading
    // `=` (Common/Entity/Cell.php:58-60), so an unguarded title would be
    // written as an <f> element - a live formula inside the workbook, authored
    // by whoever typed the abstract.
    $this->submission->forceFill(['title' => '=HYPERLINK("https://evil.example","click")'])->save();

    $response = app(ExportRankingXlsx::class)->handle(
        RankedSubmissions::query($this->conference),
        $this->conference,
        'ranking.xlsx',
    );

    $path = sys_get_temp_dir().'/cass-ranking-test-'.bin2hex(random_bytes(6)).'.xlsx';

    ob_start();
    $response->sendContent();
    file_put_contents($path, (string) ob_get_clean());

    try {
        expect(filesize($path))->toBeGreaterThan(0);

        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        expect($sheet)->not->toBe('')
            // Inline strings are openspout's XLSX default
            // (Writer/XLSX/Options.php:20), so the text is in the sheet itself.
            ->toContain('AAM26-001')
            // Guarded: the apostrophe is there and the cell is not a formula.
            ->toContain('&#039;=HYPERLINK')
            ->not->toContain('<f>')
            // The score is a number, not text, so the spreadsheet can sort it.
            ->toContain('<v>91.25</v>');
    } finally {
        @unlink($path);
    }
});

it('refuses both exports to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    // The tenant that OWNS this conference, not the outsider's own.
    // SubmissionPolicy::export() is export() -> viewAny()
    // (app/Policies/SubmissionPolicy.php:119-123 and :25-29), which asks "has
    // this user a role in the CURRENT tenant" - so it has to be asked inside
    // THIS conference's tenant. Booted into $otherOrganization, where
    // addMember() has just made them an Owner, it would answer TRUE, about
    // their own abstracts, which is not the question. bootOrganizerPanel() sets
    // the tenant unconditionally (tests/Pest.php:30-51), so booting a tenant
    // this user has no role in is both legal and exactly the case to pin. The
    // page itself is a 404 for this user (ConferenceRankingTest covers that);
    // this pins the second gate.
    bootOrganizerPanel($this->organization);

    expect(Gate::forUser($outsider)->allows('export', Submission::class))->toBeFalse();

    // And with no tenant at all, false rather than accidentally true.
    withoutTenant(function () use ($outsider): void {
        expect(Gate::forUser($outsider)->allows('export', Submission::class))->toBeFalse();
    });
});

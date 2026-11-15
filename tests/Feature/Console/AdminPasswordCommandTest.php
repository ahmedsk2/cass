<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

beforeEach(function () {
    config(['cass.admin_email' => 'admin@example.org', 'cass.admin_password' => null]); // gitleaks:allow

    $this->admin = User::factory()->platformAdmin()->create(['email' => 'admin@example.org']);
});

it('refuses to replace the password without --force, and asks for nothing', function () {
    $this->artisan('cass:admin-password')
        ->expectsOutputToContain('Re-run with --force')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
});

it('replaces the password with the one typed twice, and never prints it', function () {
    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsQuestion('New password', 'Fresh-admin-pass-2026')
        ->expectsQuestion('The same password again', 'Fresh-admin-pass-2026')
        ->expectsOutputToContain('is replaced')
        ->doesntExpectOutputToContain('Fresh-admin-pass-2026')
        ->assertExitCode(0);

    $admin = User::query()->whereKey($this->admin->getKey())->sole();
    $entry = Activity::query()->where('description', 'user.password_reset')->sole();

    expect(password_verify('Fresh-admin-pass-2026', $admin->password))->toBeTrue()
        ->and($admin->remember_token)->not->toBe($this->admin->remember_token)
        ->and($admin->getAppAuthenticationSecret())->toBe($this->admin->getAppAuthenticationSecret())
        ->and($entry->subject_id)->toBe($this->admin->getKey())
        ->and((string) json_encode($entry->toArray()))->not->toContain('Fresh-admin-pass-2026');
});

it('asks with hidden input that refuses, rather than echoes, on a terminal that cannot hide it', function () {
    $questions = new ArrayObject;

    // The real question objects, which expectsQuestion() never shows: the command's own output style, recording.
    $this->app->bind(OutputStyle::class, fn ($app, array $parameters): OutputStyle => new class($parameters['input'], $parameters['output'], $questions) extends OutputStyle
    {
        /** @param  ArrayObject<int, Question>  $questions */
        public function __construct(InputInterface $input, OutputInterface $output, private ArrayObject $questions)
        {
            parent::__construct($input, $output);
        }

        public function askQuestion(Question $question): mixed
        {
            $this->questions->append($question);

            return 'Fresh-admin-pass-2026';
        }
    });

    expect(Artisan::call('cass:admin-password', ['--force' => true]))->toBe(0)
        ->and($questions)->toHaveCount(2);

    foreach ($questions as $question) {
        expect($question->isHidden())->toBeTrue()
            ->and($question->isHiddenFallback())->toBeFalse();
    }
});

it('asks on a terminal even when CASS_ADMIN_PASSWORD is set, so a value left in the environment is never used there', function () {
    config(['cass.admin_password' => 'Left-in-environment-1']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsQuestion('New password', 'Fresh-admin-pass-2026')
        ->expectsQuestion('The same password again', 'Fresh-admin-pass-2026')
        ->expectsOutputToContain('CASS_ADMIN_PASSWORD is not used')
        ->assertExitCode(0);

    $password = User::query()->whereKey($this->admin->getKey())->sole()->password;

    expect(password_verify('Fresh-admin-pass-2026', $password))->toBeTrue()
        ->and(password_verify('Left-in-environment-1', $password))->toBeFalse();
});

it('says nothing of CASS_ADMIN_PASSWORD on a terminal when it holds only the runbook\'s placeholder', function () {
    config(['cass.admin_password' => 'removed']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsQuestion('New password', 'Fresh-admin-pass-2026')
        ->expectsQuestion('The same password again', 'Fresh-admin-pass-2026')
        ->doesntExpectOutputToContain('CASS_ADMIN_PASSWORD')
        ->assertExitCode(0);
});

it('takes CASS_ADMIN_PASSWORD under --no-interaction, and never prints it', function () {
    config(['cass.admin_password' => 'test-admin-password-2026']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('value of CASS_ADMIN_PASSWORD')
        ->doesntExpectOutputToContain('test-admin-password-2026')
        ->assertExitCode(0);

    expect(password_verify('test-admin-password-2026', User::query()->whereKey($this->admin->getKey())->sole()->password))->toBeTrue();
});

it('refuses a quiet run, which cannot ask either, rather than take CASS_ADMIN_PASSWORD unasked', function () {
    config(['cass.admin_password' => 'test-admin-password-2026']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true, '--quiet' => true])
        ->expectsOutputToContain('--no-interaction')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password)
        ->and(Activity::query()->where('description', 'user.password_reset')->exists())->toBeFalse();
});

it('refuses a silent run, which prints nothing at all, with exit 1', function () {
    config(['cass.admin_password' => 'test-admin-password-2026']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true, '--silent' => true])
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
});

it('refuses under --no-interaction when CASS_ADMIN_PASSWORD is empty', function () {
    $this->artisan('cass:admin-password', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('CASS_ADMIN_PASSWORD is empty')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
});

it('refuses a password the rule does not accept', function (string $password, string $reason) {
    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('The same password again', $password)
        ->expectsOutputToContain($reason)
        ->doesntExpectOutputToContain($password)
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password)
        ->and(Activity::query()->where('description', 'user.password_reset')->exists())->toBeFalse();
})->with([
    'shorter than twelve characters' => ['Short-pw-26', 'at least 12 characters'],
    'without a number' => ['No-digits-in-this-password', 'at least one number'],
]);

it('refuses a short CASS_ADMIN_PASSWORD under --no-interaction', function () {
    config(['cass.admin_password' => 'Short-pw-26']); // gitleaks:allow

    $this->artisan('cass:admin-password', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('at least 12 characters')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
});

it('refuses when the two typed passwords differ', function () {
    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsQuestion('New password', 'Fresh-admin-pass-2026')
        ->expectsQuestion('The same password again', 'Fresh-admin-pass-2027')
        ->expectsOutputToContain('differ')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
});

it('changes the password of a platform admin and of nobody else', function (Closure $arrange) {
    $arrange($this->admin);

    $this->artisan('cass:admin-password', ['--force' => true])
        ->expectsOutputToContain('db:seed --force')
        ->assertExitCode(1);

    expect($this->admin->fresh()?->password)->toBe($this->admin->password);
})->with([
    // Pest hands a closure to a parameter typed Closure as it is, without calling it first.
    'an account that is not a platform admin' => [fn (User $admin): bool => $admin->forceFill(['is_platform_admin' => false])->save()],
    'an address no account has' => [fn (): mixed => config(['cass.admin_email' => 'nobody@example.org'])],
    'an empty CASS_ADMIN_EMAIL' => [fn (): mixed => config(['cass.admin_email' => ''])],
]);

<?php

declare(strict_types=1);

use App\Livewire\Public\ContactForm;
use App\Mail\ContactMessage;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(fn () => Mail::fake());

it('emails the platform contact address', function () {
    livewire(ContactForm::class)
        ->set('name', 'Dr Faisal')
        ->set('email', 'faisal@example.org')
        ->set('message', 'We would like to use CASS for our regional meeting in March.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Thank you');

    Mail::assertQueued(ContactMessage::class, fn (ContactMessage $mail) => $mail->hasTo(config('cass.platform_contact_email')) && $mail->hasReplyTo('faisal@example.org'));
});

it('validates and honours the honeypot', function () {
    livewire(ContactForm::class)
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->set('message', 'short')
        ->call('send')
        ->assertHasErrors(['name', 'email', 'message']);

    livewire(ContactForm::class)
        ->set('name', 'Bot')->set('email', 'bot@example.org')->set('message', 'Buy things now please thanks')
        ->set('website_confirm', 'x')
        ->call('send')
        ->assertHasNoErrors();

    Mail::assertNothingQueued();
});

it('blocks the fourth message from the same client', function () {
    foreach (range(1, 3) as $i) {
        livewire(ContactForm::class)
            ->set('name', "Sender {$i}")->set('email', "sender{$i}@example.org")
            ->set('message', 'A genuine message that is long enough to pass validation.')
            ->call('send')->assertHasNoErrors();
    }

    livewire(ContactForm::class)
        ->set('name', 'Sender 4')->set('email', 'sender4@example.org')
        ->set('message', 'A genuine message that is long enough to pass validation.')
        ->call('send')->assertHasErrors(['message']);

    Mail::assertQueuedCount(3);
});

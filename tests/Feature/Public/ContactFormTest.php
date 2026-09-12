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

it('renders a visitor message as escaped plain text, not as markdown', function () {
    // The backlog item this closes: "render the message as plain escaped text
    // with line breaks instead of markdown so senders cannot inject headings or
    // quotes into the internal mail". This email is read by the platform team,
    // so a heading or a quotation a stranger controls is the cheapest possible
    // pretext.
    $html = (string) (new ContactMessage(
        'Dr Faisal',
        'faisal@example.org',
        "# Urgent\n> forwarded from support@example.org\n<b>bold</b>\nsecond line",
    ))->render();

    expect($html)->not->toContain('>Urgent</h1>')
        ->and($html)->not->toContain('<blockquote')
        ->and($html)->not->toContain('<b>bold</b>')
        // The line breaks the visitor typed are the one thing that does survive.
        ->and($html)->toContain('# Urgent<br>')
        ->and($html)->toContain('second line');
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

it('renders the visitor-controlled sender name as escaped text too', function () {
    // The body was neutralised; the From line was not. `name` is validated only
    // as required|string|max:120 (ContactForm::rules()), so a visitor can put
    // Markdown - or a blank line, which ends the surrounding block and hands
    // everything after it back to the parser - into the one line the platform
    // team reads first.
    $html = (string) (new ContactMessage(
        "Bob\n\n# URGENT reset your CASS password",
        'visitor@example.org',
        'A genuine message that is long enough to pass validation.',
    ))->render();

    expect($html)->not->toContain('<h1>URGENT')
        ->and($html)->not->toContain('URGENT reset your CASS password</h1>')
        ->and($html)->toContain('URGENT reset your CASS password');

    $bold = (string) (new ContactMessage(
        '**Platform Support**',
        'visitor@example.org',
        'A genuine message that is long enough to pass validation.',
    ))->render();

    expect($bold)->not->toContain('<strong>Platform Support</strong>')
        ->and($bold)->toContain('**Platform Support**');
});

<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Mail\ContactMessage;
use App\Support\ClientIp;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.public')]
#[Title('Contact')]
class ContactForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $message = '';

    public string $website_confirm = '';

    public bool $sent = false;

    public function send(): void
    {
        $key = 'contact:'.ClientIp::from(request());

        if ($this->website_confirm !== '') {
            RateLimiter::hit($key, 600);

            $this->sent = true;

            return;
        }

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('message', 'Too many messages. Please try again later.');

            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        RateLimiter::hit($key, 600);

        Mail::to(config('cass.platform_contact_email'))->queue(new ContactMessage($data['name'], $data['email'], $data['message']));

        $this->sent = true;
        $this->reset('name', 'email', 'message');
    }

    public function render(): mixed
    {
        return view('livewire.public.contact-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Organizations\RegisterOrganization as RegisterOrganizationAction;
use App\Enums\OrganizationType;
use App\Support\ClientIp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.public')]
#[Title('Register your organization')]
class RegisterOrganization extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $organization_name = '';

    public string $organization_type = 'society';

    public string $country = 'SA';

    public string $website = '';

    public string $purpose = '';

    public bool $terms = false;

    /** Honeypot: real users never see or fill this. */
    public string $website_confirm = '';

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'organization_name' => ['required', 'string', 'min:3', 'max:120'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'country' => ['required', Rule::in(array_keys(config('cass.countries')))],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'purpose' => ['required', 'string', 'min:10', 'max:500'],
            'terms' => ['accepted'],
        ];
    }

    public function register(RegisterOrganizationAction $action): mixed
    {
        $this->email = mb_strtolower(trim($this->email));

        $key = 'register:'.ClientIp::from(request());

        if ($this->website_confirm !== '') {
            RateLimiter::hit($key, 600);

            return $this->redirect('/');
        }

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Please try again in a few minutes.');

            return null;
        }

        $data = $this->validate();

        RateLimiter::hit($key, 600);

        $user = $action->handle($data);

        Auth::login($user);
        session()->regenerate();

        return $this->redirect('/org');
    }

    public function render(): mixed
    {
        return view('livewire.public.register-organization', [
            'types' => OrganizationType::cases(),
            'countries' => config('cass.countries'),
        ]);
    }
}

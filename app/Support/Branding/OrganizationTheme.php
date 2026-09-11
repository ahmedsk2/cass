<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\Organization;

final readonly class OrganizationTheme
{
    public function __construct(
        public string $primary,
        public string $accent,
        public string $onPrimary,
        public string $onAccent,
    ) {}

    public static function for(Organization $organization): self
    {
        $primary = strtoupper($organization->primary_color);
        $accent = strtoupper($organization->accent_color);

        return new self(
            primary: $primary,
            accent: $accent,
            onPrimary: Contrast::textOn($primary),
            onAccent: Contrast::textOn($accent),
        );
    }

    /**
     * Emitted into a `style` attribute on the page wrapper. Tailwind 4 reads
     * these through `bg-[var(--org-primary)]` and friends, so an organization
     * can rebrand its public pages without a rebuild.
     */
    public function cssVariables(): string
    {
        return implode(';', [
            "--org-primary:{$this->primary}",
            "--org-accent:{$this->accent}",
            "--org-on-primary:{$this->onPrimary}",
            "--org-on-accent:{$this->onAccent}",
        ]);
    }
}

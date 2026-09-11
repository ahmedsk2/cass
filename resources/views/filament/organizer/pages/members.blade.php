<x-filament-panels::page>
    {{-- The panel loads no Tailwind utilities (backlog: the organizer theme),
         so spacing here is an inline style, exactly as on the email-templates
         and share pages. --}}
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>

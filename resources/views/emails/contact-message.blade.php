<x-mail::message>
# New message from the CASS contact form

**From:** {{ $senderName }} ({{ $senderEmail }})

{{ $body }}

<x-mail::panel>
Reply directly to this email to answer.
</x-mail::panel>
</x-mail::message>

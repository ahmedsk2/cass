{{-- nl2br(e(...)) and not a Markdown echo: a visitor typing "# Urgent" or
     "> forwarded from support@" would otherwise render as a heading or a
     quotation inside an internal email, which is the cheapest possible
     pretext. e() first, then nl2br, so the line breaks survive and nothing
     else does.

     The <p> wrapper is load-bearing and is not decoration. The panel component
     markdown-parses its own slot
     (vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/panel.blade.php:7),
     so e() alone stops the quotation - `>` becomes `&gt;` - but leaves
     `# Urgent` an ATX heading, which is exactly the injection this closes.
     Opening the slot with `<p>` makes the whole thing a CommonMark HTML block,
     which is passed through verbatim; nl2br guarantees no line inside it is
     blank, and a blank line is the only thing that would end that block early
     and hand the rest back to the markdown parser. `<p>` rather than `<div>`
     so the theme's own paragraph styling still reaches the text. --}}
<x-mail::message>
# {{ __('mail.contact.heading') }}

{{-- An HTML block, for the same reason the panel slot below is one: the name
     is visitor-controlled and validated only as required|string|max:120, so a
     Markdown echo here renders `**Platform Support**` as bold and a name
     carrying a blank line ends the block entirely and hands the rest of the
     line back to the parser as a heading. nl2br(e(...)) - e() first so
     nothing but the line breaks survives, nl2br after so no line inside this
     block is blank. --}}
<p><strong>{{ __('mail.contact.from') }}</strong> {!! nl2br(e($senderName)) !!} &lt;{{ $senderEmail }}&gt;</p>

<x-mail::panel>
<p>{!! nl2br(e($body)) !!}</p>
</x-mail::panel>

{{ __('mail.contact.reply') }}
</x-mail::message>

{{--
    The body is already-escaped markdown from RenderEmailTemplate, so it is
    echoed raw: `{{ }}` here would run Laravel's EncodedHtmlString over it (the
    app calls Markdown::withSecuredEncoding()) and the organizer's own markdown
    would arrive as literal asterisks and brackets. Every value inside it was
    escaped at substitution time, and every `<` in the whole string was escaped
    after it, which is what makes echoing it raw safe.

    The header block below is raw HTML on purpose: Illuminate\Mail\Markdown
    parses with CommonMark's default html_input (allow), so a block written
    here - not by an organizer - passes through and is then inlined by
    CssToInlineStyles. It must be one block with a blank line after it or
    CommonMark will treat the following paragraph as part of the HTML.
--}}
<x-mail::message>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-bottom: 3px solid {{ $theme->primary }}; margin-bottom: 24px;">
<tr>
<td style="padding-bottom: 12px;">
@if ($organization->logo_path)
{{-- e() inside a raw echo, not {{ }}: in a markdown mail view Laravel's secured
     encoding (Markdown::withSecuredEncoding, on in AppServiceProvider) replaces
     only [ < and >, so a double quote in an organization name would otherwise
     close alt="..." and inject attributes into this tag - which CommonMark
     passes through untouched, because the block is deliberately raw HTML.
     {!! !!} is compiled by compileRawEchos, which usingEchoFormat does not
     wrap, so e() is the only encoder that runs here. --}}
<img src="{!! e(Storage::disk('branding')->url($organization->logo_path)) !!}" alt="{!! e($organization->name) !!}" height="40" style="height:40px;width:auto;vertical-align:middle;margin-right:10px">
@endif
<span style="font-size:16px;font-weight:600;color:{{ $theme->primary }};vertical-align:middle;">{{ $organization->name }}</span>
</td>
</tr>
</table>

{!! $body !!}
</x-mail::message>

@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- A PNG, not the SVG: Outlook and several webmail clients drop an <img> with
     an SVG source entirely. It is rendered 144px tall - 4x the 36px it is shown
     at - so it stays sharp on a retina client. --}}
<img src="{{ asset('brand/cass-mark-144.png') }}" alt="CASS" height="36" style="height:36px;vertical-align:middle;margin-right:8px">{{ config('cass.platform_name') }}
</a>
</td>
</tr>

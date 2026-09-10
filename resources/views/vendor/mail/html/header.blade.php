@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/cass-bird.png') }}" alt="CASS" height="36" style="height:36px;vertical-align:middle;margin-right:8px">{{ config('cass.platform_name') }}
</a>
</td>
</tr>

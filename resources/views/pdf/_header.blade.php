{{-- Running header and footer. Page numbers are stamped by PdfService after layout. --}}
<header>
    <table>
        <tr>
            <td style="width: 17mm">@if($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
            <td>
                <div class="inst">{{ $institute }}</div>
                <div class="addr">{{ $instituteAddress }}</div>
            </td>
            <td style="width: 58mm">
                <div class="doc">{{ $docTitle }}@if(!empty($docSub))<br><span class="doc-sub">{{ $docSub }}</span>@endif</div>
            </td>
        </tr>
    </table>
</header>
<footer>{{ $footerLeft ?? '' }}</footer>

@php
    $doc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>'
        .($revision->css ?? '').($revision->custom_css ?? '')
        .'</style>'.($revision->html ?? '');
@endphp
<div style="height:60vh;border:1px solid rgba(148,163,184,.3);border-radius:.5rem;overflow:hidden;background:#fff;">
    {{-- sandbox without allow-scripts: the snapshot's JS never runs in the preview --}}
    <iframe sandbox="allow-same-origin" style="width:100%;height:100%;border:0;" srcdoc="{{ $doc }}"></iframe>
</div>

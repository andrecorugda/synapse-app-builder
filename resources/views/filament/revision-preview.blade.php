@php
    // Mirror the REAL page render's style wrapper (render/page.blade.php) so a
    // revision preview looks designed — not just the snapshot's own css. Without the
    // theme tokens (:root{--pb-*}) and base reset/font/block rules, var(--pb-*)
    // resolve to nothing and the preview renders unstyled.
    $themeCss = app(\Andre\AiPageBuilder\Services\Theme::class)->css();
    $baseCss = '*,*::before,*::after{box-sizing:border-box}'
        .'body{margin:0;font-family:var(--pb-font,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}'
        .'[data-pb-block]{position:relative}'
        .'[data-pb-block]::after{content:"";position:absolute;inset:0;background:var(--pb-overlay,transparent);pointer-events:none;z-index:0}'
        .'[data-pb-block]>*{position:relative;z-index:1}';
    $doc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>'
        .$themeCss.$baseCss
        .($revision->css ?? '').($revision->custom_css ?? '')
        .'</style>'.($revision->html ?? '');
@endphp
<div style="height:60vh;border:1px solid rgba(148,163,184,.3);border-radius:.5rem;overflow:hidden;background:#fff;">
    {{-- sandbox without allow-scripts: the snapshot's JS never runs in the preview --}}
    <iframe sandbox="allow-same-origin" style="width:100%;height:100%;border:0;" srcdoc="{{ $doc }}"></iframe>
</div>

{{-- Component CONFIG styles: rules keyed off root data-pb-* attributes that the
     component settings (traits) write. Absent attribute = the block's baked-in
     default (so the plain template is unchanged); a non-default value overrides
     the relevant inline style with !important. Shared verbatim by the published
     page (render/page.blade.php <style>) AND the editor canvas
     (filament/grapesjs-assets.blade.php) so the builder previews match the page.
     Keep this file pure CSS — no Blade/PHP — so both consumers can inline it. --}}
/* ---- Modal: size ---------------------------------------------------------- */
[data-pb-block="modal"][data-pb-size="sm"] .pb-modal__panel{max-width:24rem !important}
[data-pb-block="modal"][data-pb-size="lg"] .pb-modal__panel{max-width:40rem !important}
[data-pb-block="modal"][data-pb-size="xl"] .pb-modal__panel{max-width:56rem !important}
[data-pb-block="modal"][data-pb-size="full"] .pb-modal__panel{max-width:95vw !important}

/* ---- Modal: display as a slide-in drawer --------------------------------- */
[data-pb-block="modal"][data-pb-display^="drawer"] .pb-modal__overlay{padding:0 !important}
[data-pb-block="modal"][data-pb-display^="drawer"] .pb-modal__panel{border-radius:0 !important}
[data-pb-block="modal"][data-pb-display="drawer-right"] .pb-modal__overlay{justify-content:flex-end !important;align-items:stretch !important}
[data-pb-block="modal"][data-pb-display="drawer-left"] .pb-modal__overlay{justify-content:flex-start !important;align-items:stretch !important}
[data-pb-block="modal"][data-pb-display="drawer-right"] .pb-modal__panel,
[data-pb-block="modal"][data-pb-display="drawer-left"] .pb-modal__panel{height:100vh !important;max-height:100vh !important}
[data-pb-block="modal"][data-pb-display="drawer-top"] .pb-modal__overlay{align-items:flex-start !important}
[data-pb-block="modal"][data-pb-display="drawer-bottom"] .pb-modal__overlay{align-items:flex-end !important}
[data-pb-block="modal"][data-pb-display="drawer-top"] .pb-modal__panel,
[data-pb-block="modal"][data-pb-display="drawer-bottom"] .pb-modal__panel{width:100% !important;max-width:100% !important}

/* ---- Modal: backdrop + close icon ---------------------------------------- */
[data-pb-block="modal"][data-pb-backdrop="false"] .pb-modal__overlay{background:transparent !important}
.pb-modal__panel{position:relative}
.pb-modal__x{display:none;position:absolute;top:.6rem;right:.6rem;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:0;border-radius:.375rem;background:transparent;color:#475569;cursor:pointer;line-height:0;z-index:2}
.pb-modal__x:hover{background:#f1f5f9}
[data-pb-block="modal"][data-pb-close-icon="true"] .pb-modal__x{display:inline-flex}
[data-pb-block="modal"][data-pb-close-icon="true"][data-pb-close-icon-pos="left"] .pb-modal__x{right:auto;left:.6rem}

/* ---- Drawer: side + size (panel pinned to the chosen edge) --------------- */
[data-pb-block="drawer"][data-pb-side="left"] .pb-drawer__panel{right:auto !important;left:0 !important}
[data-pb-block="drawer"][data-pb-side="top"] .pb-drawer__panel{right:0 !important;bottom:auto !important;left:0 !important;top:0 !important;width:100% !important;max-width:none !important;height:auto !important;max-height:85vh !important}
[data-pb-block="drawer"][data-pb-side="bottom"] .pb-drawer__panel{right:0 !important;top:auto !important;left:0 !important;bottom:0 !important;width:100% !important;max-width:none !important;height:auto !important;max-height:85vh !important}
[data-pb-block="drawer"][data-pb-size="sm"] .pb-drawer__panel{width:300px !important}
[data-pb-block="drawer"][data-pb-size="lg"] .pb-drawer__panel{width:480px !important}
[data-pb-block="drawer"][data-pb-size="xl"] .pb-drawer__panel{width:640px !important}
[data-pb-block="drawer"][data-pb-side="top"][data-pb-size="sm"] .pb-drawer__panel,
[data-pb-block="drawer"][data-pb-side="bottom"][data-pb-size="sm"] .pb-drawer__panel{height:auto !important}
[data-pb-block="drawer"][data-pb-backdrop="false"] .pb-drawer__backdrop{display:none !important}
/* Slide transition (the template referenced these x-transition classes but they
   were never defined, so the drawer popped instantly). Default = right edge. */
.pb-drawer-enter{transition:transform .28s ease}
.pb-drawer-enter-start{transform:translateX(100%)}
.pb-drawer-enter-end{transform:translateX(0)}
.pb-drawer-leave{transition:transform .2s ease}
[data-pb-block="drawer"][data-pb-side="left"] .pb-drawer-enter-start{transform:translateX(-100%)}
[data-pb-block="drawer"][data-pb-side="top"] .pb-drawer-enter-start{transform:translateY(-100%)}
[data-pb-block="drawer"][data-pb-side="bottom"] .pb-drawer-enter-start{transform:translateY(100%)}

/* ---- Tabs: alignment ------------------------------------------------------ */
[data-pb-block="tabs"][data-pb-align="center"] .pb-tabs__list{justify-content:center}
[data-pb-block="tabs"][data-pb-align="end"] .pb-tabs__list{justify-content:flex-end}
[data-pb-block="tabs"][data-pb-align="stretch"] .pb-tabs__tab{flex:1}

/* ---- Accordion: flush variant -------------------------------------------- */
[data-pb-block="accordion"][data-pb-variant="flush"]{border:0 !important;border-radius:0 !important}

/* ---- Tooltip: side -------------------------------------------------------- */
[data-pb-block="tooltip"][data-pb-side="bottom"] .pb-tooltip__bubble{bottom:auto !important;top:calc(100% + .5rem) !important}
[data-pb-block="tooltip"][data-pb-side="left"] .pb-tooltip__bubble{bottom:auto !important;top:50% !important;left:auto !important;right:calc(100% + .5rem) !important;transform:translateY(-50%) !important}
[data-pb-block="tooltip"][data-pb-side="right"] .pb-tooltip__bubble{bottom:auto !important;top:50% !important;left:calc(100% + .5rem) !important;transform:translateY(-50%) !important}
[data-pb-block="tooltip"][data-pb-multiline="true"] .pb-tooltip__bubble{white-space:normal !important;max-width:16rem !important}

/* ---- Dropdown menu: alignment + direction -------------------------------- */
[data-pb-block="dropdown_menu"][data-pb-align="end"] .pb-dropdown__menu{left:auto !important;right:0 !important}
[data-pb-block="dropdown_menu"][data-pb-direction="up"] .pb-dropdown__menu{top:auto !important;bottom:calc(100% + .35rem) !important}

/* ---- Context menu: trigger mode (hide the kebab for right-click-only) ----- */
[data-pb-block="context_menu"][data-pb-trigger="right-click"] .pb-context__trigger{display:none !important}

/* ---- Banner: severity variants ------------------------------------------- */
[data-pb-block="banner"][data-pb-variant="success"]{border-color:#bbf7d0 !important;background:#f0fdf4 !important;color:#166534 !important}
[data-pb-block="banner"][data-pb-variant="warning"]{border-color:#fde68a !important;background:#fffbeb !important;color:#92400e !important}
[data-pb-block="banner"][data-pb-variant="error"]{border-color:#fecaca !important;background:#fef2f2 !important;color:#991b1b !important}
[data-pb-block="banner"][data-pb-variant="neutral"]{border-color:#e2e8f0 !important;background:#f8fafc !important;color:#334155 !important}
[data-pb-block="banner"][data-pb-dismissible="false"] .pb-banner__dismiss{display:none !important}
[data-pb-block="banner"][data-pb-icon="false"] .pb-banner__icon{display:none !important}

{{--
    The few rules the settings pages need on top of the admin stylesheet: real form controls inside the
    mockup's switch / segmented / radio-card shapes, folded "More options", and the field highlight of a
    search deep link. Colours come from the admin tokens only.
--}}
<style>
.ad-settings-form .ad-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
.ad-settings-form .ad-toggle-row{position:relative;cursor:pointer}
.ad-settings-form .ad-sr:focus-visible+.ad-switch,.ad-settings-form .ad-seg label:has(.ad-sr:focus-visible),.ad-settings-form .ad-rc:has(.ad-sr:focus-visible){outline:2px solid var(--focus,#2563eb);outline-offset:2px}
.ad-settings-form .ad-switch{border-radius:var(--r-pill);background:var(--border-input)}
.ad-settings-form .ad-switch::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform var(--dur)}
.ad-settings-form .ad-sr:checked+.ad-switch{background:var(--accent)}
.ad-settings-form .ad-sr:checked+.ad-switch::after{transform:translateX(16px)}
[dir=rtl] .ad-settings-form .ad-sr:checked+.ad-switch::after{transform:translateX(-16px)}
.ad-settings-form .ad-seg{flex-wrap:wrap}
.ad-settings-form .ad-seg label{height:30px;padding:0 12px;border-radius:var(--r-sm);color:var(--text-2);font-weight:500;font-size:var(--fs-sm);cursor:pointer;display:inline-flex;align-items:center;white-space:nowrap}
.ad-settings-form .ad-seg label.is-active{background:var(--surface);color:var(--text);box-shadow:0 1px 2px rgba(16,24,40,.12);font-weight:600}
.ad-settings-form .ad-radio-list{display:grid;gap:var(--s3,12px)}
.ad-settings-form .ad-rc b .ad-badge{display:inline-flex;margin:0;margin-inline-start:6px;vertical-align:1px}
.ad-settings-form .ad-label{display:block;font-weight:600}
.ad-settings-form .ad-control{margin-top:8px}
.ad-settings-form details>summary{list-style:none;cursor:pointer}
.ad-settings-form details>summary::-webkit-details-marker{display:none}
.ad-settings-form .ad-sec-body details>.ad-field,.ad-settings-form .ad-sec-body details>.ad-toggle-row{padding:var(--s4,16px) var(--s5,20px);border-top:1px solid var(--border)}
.ad-settings-form .ad-sec-body details[open]>.ad-disclose{border-radius:0}
.ad-settings-form .ad-adv>summary{display:flex;align-items:center;gap:8px;margin-top:var(--s6,24px);padding:var(--s3,12px) 0;border-top:1px solid var(--border);font-weight:600;color:var(--text-2)}
.ad-settings-form .ad-field.is-highlight,.ad-settings-form .ad-toggle-row.is-highlight{background:var(--accent-soft)}
.ad-settings-form .ad-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ad-settings-form .ad-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.ad-settings-form [hidden]{display:none!important}
.ad-settings-form .ad-savebar.is-static{position:static;box-shadow:none}
.ad-settings-form .ad-upload{display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap}
.ad-settings-form .ad-upload img{width:120px;height:63px;object-fit:cover;border:1px solid var(--border);border-radius:var(--r-sm);background:var(--surface-2)}
.ad-settings-form .ad-upload-body{display:grid;gap:8px;flex:1;min-width:200px}
.ad-settings-form .ad-size .ad-input{max-width:220px}
.ad-settings-form .ad-size .ad-select{width:auto;flex:0 0 auto;margin-inline-start:-1px;border-start-start-radius:0;border-end-start-radius:0;background-color:var(--surface-2)}
.ad-intro-steps{margin-top:8px}
.ad-intro-steps summary{display:inline;cursor:pointer}
.ad-intro-steps ol{margin:8px 0 0;padding-inline-start:20px;font-size:var(--fs-sm);color:var(--text-2)}
.ad-intro-steps li+li{margin-top:4px}
.ad-intro-steps li a{color:inherit;text-decoration:underline}
/* Links on a coloured callout take the callout's text colour: the accent is not readable on every callout tint. */
.ad-callout-actions .ad-link{color:inherit;text-decoration:underline}
.ad-settings-form button.ad-link{background:none;border:0;padding:0;font:inherit;color:var(--text-2);text-decoration:underline;cursor:pointer}
.ad-settings-form .ad-key-remove input{margin:0;accent-color:var(--danger-fill)}
.ad-settings-form .ad-key-remove:has(input:checked){color:var(--danger);border-color:var(--danger-line);background:var(--danger-soft)}
@media (forced-colors:active){.ad-settings-form .ad-switch,.ad-settings-form .ad-seg label.is-active,.ad-settings-form .ad-rc.is-on{border:1px solid CanvasText}}
</style>

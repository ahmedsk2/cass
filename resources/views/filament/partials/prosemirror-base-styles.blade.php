{{--
    The stylesheet TipTap injects at runtime, shipped from the server with a
    nonce instead.

    Filament 5.8.1 builds its RichEditor's TipTap instance with
    `injectCSS: true, injectNonce: undefined`
    (vendor/filament/forms/dist/components/rich-editor.js), so the
    `<style data-tiptap-style>` element it appends to <head> carries no nonce -
    and this application's style-src is 'self' plus a per-request nonce, which
    makes the browser ignore 'unsafe-inline' entirely. The browser therefore
    refuses TipTap's own block and the editor loses `white-space: pre-wrap`,
    the gap cursor and the separator image.

    These rules are a verbatim copy of that block. When Filament is upgraded,
    diff it against the same constant in rich-editor.js; the drift is silent
    otherwise, because a duplicate rule set is harmless and a missing one only
    shows up as an editor that mangles blank lines.
--}}
<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" data-cass-prosemirror>
    .ProseMirror {
        position: relative;
    }

    .ProseMirror {
        word-wrap: break-word;
        white-space: pre-wrap;
        white-space: break-spaces;
        -webkit-font-variant-ligatures: none;
        font-variant-ligatures: none;
        font-feature-settings: "liga" 0;
    }

    .ProseMirror [contenteditable="false"] {
        white-space: normal;
    }

    .ProseMirror [contenteditable="false"] [contenteditable="true"] {
        white-space: pre-wrap;
    }

    .ProseMirror pre {
        white-space: pre-wrap;
    }

    img.ProseMirror-separator {
        display: inline !important;
        border: none !important;
        margin: 0 !important;
        width: 0 !important;
        height: 0 !important;
    }

    .ProseMirror-gapcursor {
        display: none;
        pointer-events: none;
        position: absolute;
        margin: 0;
    }

    .ProseMirror-gapcursor:after {
        content: "";
        display: block;
        position: absolute;
        top: -2px;
        width: 20px;
        border-top: 1px solid black;
        animation: ProseMirror-cursor-blink 1.1s steps(2, start) infinite;
    }

    @keyframes ProseMirror-cursor-blink {
        to {
            visibility: hidden;
        }
    }

    .ProseMirror-hideselection *::selection {
        background: transparent;
    }

    .ProseMirror-hideselection *::-moz-selection {
        background: transparent;
    }

    .ProseMirror-hideselection * {
        caret-color: transparent;
    }

    .ProseMirror-focused .ProseMirror-gapcursor {
        display: block;
    }
</style>

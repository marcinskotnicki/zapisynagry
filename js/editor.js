/* =============================================================================
 *  js/editor.js — the two things the page editor was missing.
 * -----------------------------------------------------------------------------
 *  Loaded only where a Trix editor is on screen (see admin_shell.php), after
 *  Trix itself, because both features attach to the element Trix upgrades.
 *
 *  1. IMAGES THAT SURVIVE SAVING. Trix shows an attached picture immediately,
 *     but that preview is a blob URL living in the browser. Unless something
 *     uploads the file and hands the attachment a real address, the picture is
 *     gone the moment the page is saved — which looked exactly like a bug and
 *     was reported as one. Trix's contract is that you listen for
 *     trix-attachment-add and call setAttributes({url, href}) when the upload
 *     finishes; that is what happens below.
 *
 *  2. AN HTML VIEW. Trix edits rich text and hides the markup, but an admin
 *     sometimes needs the markup itself — to paste an embed, to fix a stray
 *     tag, or just to see what is really stored. The toggle swaps the editor
 *     for a plain textarea over the same hidden input, so whichever is on
 *     screen is what gets saved.
 * ============================================================================= */
(function () {
    'use strict';

    var editor = document.querySelector('trix-editor');
    if (!editor) return;                      // no editor on this page

    /* ---- 1. Uploading an attachment ------------------------------------- */

    document.addEventListener('trix-attachment-add', function (event) {
        var attachment = event.attachment;
        // Attachments also fire this when an EXISTING image is pasted back in
        // from saved content; those already have a URL and nothing to upload.
        if (!attachment.file) return;
        uploadAttachment(attachment);
    });

    function uploadAttachment(attachment) {
        var form = new FormData();
        form.append('file', attachment.file);
        // The token from the surrounding form: upload_image.php checks it the
        // same way every other POST handler does.
        form.append('csrf', editorConfig('csrf'));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', editorConfig('upload-url'), true);

        // Trix draws its own progress bar from this.
        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                attachment.setUploadProgress((e.loaded / e.total) * 100);
            }
        });

        xhr.addEventListener('load', function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }

            if (xhr.status >= 200 && xhr.status < 300 && data && data.url) {
                // href as well as url: url is the picture, href is what a
                // reader clicks to see it full size.
                attachment.setAttributes({ url: data.url, href: data.url });
                return;
            }
            /* REMOVE THE ATTACHMENT ON FAILURE, rather than leaving the preview
             * sitting there. A picture that looks placed but was never stored is
             * the exact problem this file exists to fix, and leaving it would
             * recreate it one level further on. */
            attachment.remove();
            window.alert(editorConfig('upload-error') || 'Upload failed.');
        });

        xhr.addEventListener('error', function () {
            attachment.remove();
            window.alert(editorConfig('upload-error') || 'Upload failed.');
        });

        xhr.send(form);
    }

    /** A setting the template put on the editor element. */
    function editorConfig(name) {
        return editor.getAttribute('data-' + name) || '';
    }

    /* ---- 2. The HTML view ------------------------------------------------ */

    var toggle  = document.querySelector('.js-html-toggle');
    var area    = document.querySelector('.js-html-source');
    var input   = document.getElementById(editor.getAttribute('input'));
    if (!toggle || !area || !input) return;

    // The plain-textarea fallback is only useful with JavaScript off; with it
    // on, the toggle governs which view is showing.
    toggle.hidden = false;
    var showingSource = false;

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        showingSource = !showingSource;

        if (showingSource) {
            /* Editor -> markup. Read from the hidden input rather than from the
             * editor's own DOM: the input is what will actually be saved, so it
             * is the honest answer to "what is stored?". */
            area.value = input.value;
            editor.style.display = 'none';
            // The toolbar belongs to the editor and means nothing over a
            // textarea, so it goes with it.
            var bar = document.getElementById(editor.getAttribute('toolbar'));
            if (bar) bar.style.display = 'none';
            area.hidden = false;
            area.focus();
        } else {
            /* Markup -> editor. Trix owns the input while it is on screen, so
             * the edited markup is loaded through its own API; assigning to the
             * input directly would be overwritten on the next keystroke. */
            editor.editor.loadHTML(area.value);
            area.hidden = true;
            editor.style.display = '';
            var bar2 = document.getElementById(editor.getAttribute('toolbar'));
            if (bar2) bar2.style.display = '';
            editor.focus();
        }
        toggle.textContent = showingSource
            ? (toggle.getAttribute('data-label-rich') || 'Editor')
            : (toggle.getAttribute('data-label-html') || 'HTML');
    });

    /* Whichever view is open must be the one that is saved. With the source
     * view showing, Trix is not watching the textarea, so its contents are
     * copied into the input as the form is submitted. */
    var formEl = editor.closest ? editor.closest('form') : null;
    if (formEl) {
        formEl.addEventListener('submit', function () {
            if (showingSource) input.value = area.value;
        });
    }
})();

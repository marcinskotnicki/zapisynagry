/* =============================================================================
 *  js/scripts.js — shared client behaviour.
 * -----------------------------------------------------------------------------
 *  Kept deliberately small (project rule: minimal JS). Uses plain DOM APIs, no
 *  jQuery required for what's here so far. Strings that need translating come
 *  from window.APP_LANG (set inline in the page <head>).
 *
 *  Currently provides:
 *    1. New-event date cascade — pick the first day's date and the rest fill in
 *       as consecutive days.
 *    2. Copy-to-clipboard buttons (archive links).
 *    3. Hash highlight for in-page anchors.
 *    4. reCAPTCHA v3 token minting on form submit (invisible captcha mode).
 *  This file grows in the front-end phase (modals, add-game flow, etc.).
 * ========================================================================== */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initDateCascade();
        initCopyButtons();
        initHashHighlight();
        initRecaptchaV3();
    });

    /* ---- 1. Date cascade --------------------------------------------------- */
    function initDateCascade() {
        var dateInputs = Array.prototype.slice.call(
            document.querySelectorAll('input.day-date')
        );
        if (dateInputs.length < 2) return;

        var first = dateInputs[0];
        first.addEventListener('change', function () {
            if (!first.value) return;
            var base = new Date(first.value + 'T00:00:00');
            if (isNaN(base.getTime())) return;

            for (var i = 1; i < dateInputs.length; i++) {
                var d = new Date(base.getTime());
                d.setDate(d.getDate() + i);          // consecutive days
                dateInputs[i].value = toISODate(d);
            }
        });
    }

    // Format a Date as YYYY-MM-DD in local time.
    function toISODate(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    /* ---- 2. Copy buttons --------------------------------------------------- */
    function initCopyButtons() {
        document.querySelectorAll('.copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-copy-target'));
                if (!target) return;
                target.select();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(target.value);
                } else {
                    document.execCommand('copy');     // older browsers
                }
            });
        });
    }

    /* ---- 3. "Just interacted" highlight ------------------------------------ *
     * After signing up / voting / adding (the controllers redirect to #game-N
     * or #poll-N) and on timeline clicks, mark the card the hash points at
     * with .active — one card at a time. CSS decides what .active looks like.
     * ------------------------------------------------------------------------- */
    function initHashHighlight() {
        function apply() {
            // Clear the previous highlight first: exactly one card is .active.
            document.querySelectorAll('.game-card.active, .poll-card.active').forEach(function (n) {
                n.classList.remove('active');
            });
            if (location.hash.length < 2) return;
            var el = document.getElementById(location.hash.substring(1));
            if (el && (el.classList.contains('game-card') || el.classList.contains('poll-card'))) {
                el.classList.add('active');
            }
        }
        window.addEventListener('hashchange', apply);   // timeline / in-page clicks
        apply();                                        // arriving via a redirect anchor
    }

    /* ---- 4. reCAPTCHA v3 token -------------------------------------------- *
     * v3 is invisible and score-based: there's no widget to tick, so the page
     * has to ask Google for a token itself. Tokens expire after about two
     * minutes, so we mint one at SUBMIT time rather than on page load — a user
     * who spends a while filling the form would otherwise send a stale token.
     *
     * The hidden input is rendered by captcha_html() (PHP) and carries the site
     * key + action as data attributes, so no key is hardcoded here.
     *
     * If Google's script didn't load (blocked, offline), we let the submit go
     * through with an empty token: the server rejects it and shows the normal
     * captcha error, which is clearer than silently freezing the form.
     * --------------------------------------------------------------------- */
    function initRecaptchaV3() {
        var field = document.querySelector('input.recaptcha-v3-token');
        if (!field) return;                       // not in v3 mode on this page
        var form = field.form;
        if (!form) return;

        var siteKey = field.getAttribute('data-sitekey') || '';
        var action  = field.getAttribute('data-action') || 'submit';
        var minting = false;                      // guards the re-submit below

        form.addEventListener('submit', function (ev) {
            if (minting) return;                  // our own re-submit: let it pass
            if (typeof grecaptcha === 'undefined' || !siteKey) return;  // fail open to the server

            ev.preventDefault();
            minting = true;
            grecaptcha.ready(function () {
                grecaptcha.execute(siteKey, { action: action }).then(function (token) {
                    field.value = token;
                    form.submit();                // native submit; listener short-circuits
                })['catch'](function () {
                    field.value = '';             // let the server reject it
                    form.submit();
                });
            });
        });
    }
})();
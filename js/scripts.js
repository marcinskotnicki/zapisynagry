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
 *    5. Poll deadline live preview (add_poll.php): "resolves on ..." text.
 *    6. BGG search guard: no searching with an empty game name.
 *  This file grows in the front-end phase (modals, add-game flow, etc.).
 * ========================================================================== */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initDateCascade();
        initCopyButtons();
        initHashHighlight();
        initRecaptchaV3();
        initPollDeadlinePreview();
        initBggSearchGuard();
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

    /* ---- 5. Poll deadline preview ------------------------------------------ *
     * Both poll forms (create in add_poll.php, edit in edit_poll.php) carry the
     * day's date / opening hour / grace window as data-* attributes, because
     * only the server knows the event's calendar. From those plus the two live
     * inputs we reproduce day_rel_min()'s overnight rollover client-side and
     * show the exact moment the poll will auto-resolve, updating on every edit.
     *
     * Mirrors poll_deadline_from_hours() in inc/polls.php — including its clamp
     * — so the preview never promises a moment the server would overrule.
     * ------------------------------------------------------------------------- */
    function initPollDeadlinePreview() {
        document.querySelectorAll('form[data-day-date]').forEach(function (form) {
            var startInput    = form.querySelector('input[name="start_time"]');
            var deadlineInput = form.querySelector('input[name="deadline_hours"]');
            var out           = form.querySelector('.poll-deadline-preview');
            if (!startInput || !deadlineInput || !out) return;

            var dayDate  = form.getAttribute('data-day-date') || '';
            var dayStart = form.getAttribute('data-day-start') || '';
            var graceMin = (parseFloat(form.getAttribute('data-grace-hours')) || 0) * 60;
            if (!dayDate || !dayStart) return;   // no calendar date -> nothing to preview

            function update() {
                var deadlineHours = parseFloat(deadlineInput.value);
                if (!deadlineHours || deadlineHours <= 0) {
                    out.textContent = (window.APP_LANG && window.APP_LANG.pollNoAutoDeadline) || '';
                    return;
                }
                var startMin    = hhmmToMin(startInput.value);
                var dayStartMin = hhmmToMin(dayStart);
                if (startMin === null || dayStartMin === null) { out.textContent = ''; return; }

                // Same pivot as day_rel_min() in inc/events.php: a start earlier
                // than (opening hour - grace) belongs to the next calendar day.
                var pivot  = dayStartMin - graceMin;
                var relMin = startMin < pivot ? startMin + 1440 : startMin;

                var base = new Date(dayDate + 'T00:00:00');
                if (isNaN(base.getTime())) { out.textContent = ''; return; }
                var startMoment = new Date(base.getTime());
                startMoment.setMinutes(startMoment.getMinutes() + relMin);

                var deadlineMoment = new Date(startMoment.getTime() - deadlineHours * 3600000);
                // The server's clamp: a deadline that already passed becomes
                // "an hour from now", so the poll keeps some voting window.
                var now = new Date();
                if (deadlineMoment <= now) {
                    deadlineMoment = new Date(now.getTime() + 3600000);
                }

                var weekdays = (window.APP_LANG && window.APP_LANG.weekdays) || [];
                var template = (window.APP_LANG && window.APP_LANG.pollDeadlinePreview) || '';
                if (!template) { out.textContent = ''; return; }

                out.textContent = template
                    .replace('{date}', pad2(deadlineMoment.getDate()) + '.' + pad2(deadlineMoment.getMonth() + 1) + '.' + deadlineMoment.getFullYear())
                    .replace('{weekday}', weekdays[deadlineMoment.getDay()] || '')
                    .replace('{time}', pad2(deadlineMoment.getHours()) + ':' + pad2(deadlineMoment.getMinutes()));
            }

            startInput.addEventListener('input', update);
            deadlineInput.addEventListener('input', update);
            update();
        });
    }

    // 'HH:MM' -> minutes since midnight, or null when it isn't a time yet
    // (a half-typed <input type="time"> reports an empty value).
    function hhmmToMin(s) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(s || '');
        return m ? (parseInt(m[1], 10) * 60 + parseInt(m[2], 10)) : null;
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    /* ---- 6. BGG search guard ----------------------------------------------- *
     * Searching BoardGameGeek for an empty string returns nothing, which the
     * results page can only report as "no matches" — misleading, since the user
     * simply didn't type anything. Grey the button out until there's something
     * to search for.
     *
     * Applied HERE rather than as a `disabled` attribute in the markup on
     * purpose: with JS off the button must stay usable, and the server-side
     * check in add_game.php / add_poll_game.php produces the message instead.
     * This is the convenience; that is the guarantee.
     * ------------------------------------------------------------------------ */
    function initBggSearchGuard() {
        document.querySelectorAll('button[name="go"][value="bgg"]').forEach(function (btn) {
            // .form resolves both a nested button and one tied by form="...".
            var form = btn.form;
            if (!form) return;
            var nameInput = form.querySelector('input[name="name"]');
            if (!nameInput) return;

            function sync() {
                var empty = nameInput.value.trim() === '';
                btn.disabled = empty;
                btn.setAttribute('aria-disabled', empty ? 'true' : 'false');
            }
            nameInput.addEventListener('input', sync);
            sync();
        });
    }

})();
/* js/scripts.js — shared client behaviour. Plain DOM APIs, no dependencies. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initDateCascade();
        initCopyButtons();
        initHashHighlight();
        initRecaptchaV3();
        initPollDeadlinePreview();
        initBggSearchGuard();
        initDoubleSubmitGuard();
    });

    // 1. New-event date cascade: fill in consecutive days from the first.
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
                d.setDate(d.getDate() + i);
                dateInputs[i].value = toISODate(d);
            }
        });
    }

    function toISODate(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    // 2. Copy-to-clipboard buttons.
    function initCopyButtons() {
        document.querySelectorAll('.copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-copy-target'));
                if (!target) return;
                target.select();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(target.value);
                } else {
                    document.execCommand('copy');
                }
            });
        });
    }

    // 3. Highlight the card a redirect or in-page link points at.
    function initHashHighlight() {
        function apply() {
            document.querySelectorAll('.game-card.active, .poll-card.active').forEach(function (n) {
                n.classList.remove('active');
            });
            if (location.hash.length < 2) return;
            var el = document.getElementById(location.hash.substring(1));
            if (el && (el.classList.contains('game-card') || el.classList.contains('poll-card'))) {
                el.classList.add('active');
            }
        }
        window.addEventListener('hashchange', apply);
        apply();
    }

    // 4. reCAPTCHA v3: mint a token at submit time (tokens expire quickly).
    function initRecaptchaV3() {
        var field = document.querySelector('input.recaptcha-v3-token');
        if (!field) return;
        var form = field.form;
        if (!form) return;

        var siteKey = field.getAttribute('data-sitekey') || '';
        var action  = field.getAttribute('data-action') || 'submit';
        var minting = false;

        form.addEventListener('submit', function (ev) {
            if (minting) return;
            if (typeof grecaptcha === 'undefined' || !siteKey) return;

            ev.preventDefault();
            minting = true;
            grecaptcha.ready(function () {
                grecaptcha.execute(siteKey, { action: action }).then(function (token) {
                    field.value = token;
                    form.submit();
                })['catch'](function () {
                    field.value = '';
                    form.submit();
                });
            });
        });
    }

    // 5. Poll deadline live preview (mirrors poll_deadline_from_hours() server-side).
    function initPollDeadlinePreview() {
        document.querySelectorAll('form[data-day-date]').forEach(function (form) {
            var startInput    = form.querySelector('input[name="start_time"]');
            var deadlineInput = form.querySelector('input[name="deadline_hours"]');
            var out           = form.querySelector('.poll-deadline-preview');
            if (!startInput || !deadlineInput || !out) return;

            var dayDate  = form.getAttribute('data-day-date') || '';
            var dayStart = form.getAttribute('data-day-start') || '';
            var graceMin = (parseFloat(form.getAttribute('data-grace-hours')) || 0) * 60;
            if (!dayDate || !dayStart) return;

            function update() {
                var deadlineHours = parseFloat(deadlineInput.value);
                if (!deadlineHours || deadlineHours <= 0) {
                    out.textContent = (window.APP_LANG && window.APP_LANG.pollNoAutoDeadline) || '';
                    return;
                }
                var startMin    = hhmmToMin(startInput.value);
                var dayStartMin = hhmmToMin(dayStart);
                if (startMin === null || dayStartMin === null) { out.textContent = ''; return; }

                var pivot  = dayStartMin - graceMin;
                var relMin = startMin < pivot ? startMin + 1440 : startMin;

                var base = new Date(dayDate + 'T00:00:00');
                if (isNaN(base.getTime())) { out.textContent = ''; return; }
                var startMoment = new Date(base.getTime());
                startMoment.setMinutes(startMoment.getMinutes() + relMin);

                var deadlineMoment = new Date(startMoment.getTime() - deadlineHours * 3600000);
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

    function hhmmToMin(s) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(s || '');
        return m ? (parseInt(m[1], 10) * 60 + parseInt(m[2], 10)) : null;
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    // 6. BGG search guard: disable "search" until something is typed.
    function initBggSearchGuard() {
        document.querySelectorAll('button[name="go"][value="bgg"]').forEach(function (btn) {
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

    // 7. Double-submit guard: a second click cannot post the form twice.
    // Disabling is deferred so the clicked button's own name/value still
    // serialises; hooks 'submit' (fires only after validation passes) rather
    // than 'click'; and re-enables on a bfcache 'pageshow' restore.
    function initDoubleSubmitGuard() {
        if (document.documentElement.dataset.dsGuard === '1') return;
        document.documentElement.dataset.dsGuard = '1';

        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.hasAttribute('data-allow-resubmit')) return;

            if (form.dataset.submitting === '1') {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                return;
            }
            form.dataset.submitting = '1';

            window.setTimeout(function () {
                var controls = form.querySelectorAll(
                    'button[type="submit"], input[type="submit"], button:not([type])');
                for (var i = 0; i < controls.length; i++) {
                    controls[i].disabled = true;
                    controls[i].setAttribute('aria-busy', 'true');
                }
                form.classList.add('is-submitting');
            }, 0);
        });

        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) return;
            var forms = document.querySelectorAll('form[data-submitting="1"], form.is-submitting');
            for (var i = 0; i < forms.length; i++) {
                var f = forms[i];
                delete f.dataset.submitting;
                f.classList.remove('is-submitting');
                var controls = f.querySelectorAll(
                    'button[type="submit"], input[type="submit"], button:not([type])');
                for (var j = 0; j < controls.length; j++) {
                    controls[j].disabled = false;
                    controls[j].removeAttribute('aria-busy');
                }
            }
        });
    }

})();

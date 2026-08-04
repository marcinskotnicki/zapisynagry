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
        initChat();
        initNavBurger();
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

    // 8. Chat panel: open/close, poll for new messages, post, load earlier.
    // Messages are built with createElement/textContent, never innerHTML —
    // every line is untrusted visitor input.
    function initChat() {
        var toggle = document.getElementById('chat-toggle');
        var panel  = document.getElementById('chat-panel');
        if (!toggle || !panel || !window.APP_CHAT) return;

        var list    = document.getElementById('chat-messages');
        var form    = document.getElementById('chat-form');
        var earlier = document.getElementById('chat-earlier');
        var errBox  = document.getElementById('chat-error');
        var input   = document.getElementById('chat-message');
        var nameIn  = document.getElementById('chat-name');
        var L       = window.APP_LANG || {};

        var lastId   = 0;
        var oldestId = 0;
        var timer    = null;
        var loaded   = false;

        function fmt(tpl, fallback) { return tpl || fallback; }

        function renderMessage(m, prepend) {
            var line = document.createElement('p');
            line.className = 'chat-msg' + (m.admin ? ' chat-msg-admin' : (m.user ? ' chat-msg-user' : ''));
            line.setAttribute('data-id', m.id);

            var who = document.createElement('span');
            who.className = 'chat-msg-name';
            who.textContent = m.name;

            var when = document.createElement('span');
            when.className = 'chat-msg-time';
            when.textContent = ' (' + m.at + '): ';

            var body = document.createElement('span');
            body.className = 'chat-msg-body';
            body.textContent = m.message;

            line.appendChild(who);
            line.appendChild(when);
            line.appendChild(body);

            if (prepend) { list.insertBefore(line, list.firstChild); }
            else { list.appendChild(line); }
        }

        function atBottom() {
            var log = document.getElementById('chat-log');
            return log.scrollHeight - log.scrollTop - log.clientHeight < 40;
        }
        function scrollDown() {
            var log = document.getElementById('chat-log');
            log.scrollTop = log.scrollHeight;
        }

        function showError(msg) {
            if (!errBox) return;
            errBox.textContent = msg;
            errBox.hidden = !msg;
        }

        function apply(data, prepend) {
            if (!data || !data.ok) return;
            var msgs = data.messages || [];
            if (prepend) {
                // Reverse so repeated insertBefore keeps their original order.
                for (var i = msgs.length - 1; i >= 0; i--) renderMessage(msgs[i], true);
            } else {
                for (var j = 0; j < msgs.length; j++) renderMessage(msgs[j], false);
            }
            if (msgs.length) {
                if (!oldestId || msgs[0].id < oldestId) oldestId = msgs[0].id;
                var newest = msgs[msgs.length - 1].id;
                if (newest > lastId) lastId = newest;
            }
            if (typeof data.lastId === 'number' && data.lastId > lastId) lastId = data.lastId;
            if (earlier) earlier.hidden = !data.more;
        }

        function load() {
            var wasDown = atBottom();
            fetch('chat.php?action=fetch', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    apply(d, false);
                    loaded = true;
                    if (!(d.messages || []).length && L.chatEmpty) {
                        var p = document.createElement('p');
                        p.className = 'chat-empty';
                        p.textContent = L.chatEmpty;
                        list.appendChild(p);
                    }
                    scrollDown();
                })['catch'](function () { showError(fmt(L.chatFailed, '')); });
        }

        function poll() {
            if (!loaded) return;
            var wasDown = atBottom();
            fetch('chat.php?action=fetch&after=' + encodeURIComponent(lastId),
                  { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var before = lastId;
                    apply(d, false);
                    // Only auto-scroll if they were already at the bottom —
                    // yanking the view while someone reads back is worse than
                    // making them scroll.
                    if (lastId > before && wasDown) scrollDown();
                })['catch'](function () { /* transient: the next tick retries */ });
        }

        function start() {
            stop();
            timer = window.setInterval(poll, window.APP_CHAT.refresh);
        }
        function stop() {
            if (timer) { window.clearInterval(timer); timer = null; }
        }

        toggle.addEventListener('click', function () {
            var open = panel.hasAttribute('hidden');
            if (open) {
                panel.removeAttribute('hidden');
                document.body.classList.add('chat-open');
                toggle.setAttribute('aria-expanded', 'true');
                if (!loaded) load(); else scrollDown();
                start();
            } else {
                panel.setAttribute('hidden', '');
                document.body.classList.remove('chat-open');
                toggle.setAttribute('aria-expanded', 'false');
                // Polling stops with the panel: an idle background tab should
                // not keep hitting the server every few seconds.
                stop();
            }
        });

        if (earlier) {
            earlier.addEventListener('click', function () {
                if (!oldestId) return;
                var log = document.getElementById('chat-log');
                var before = log.scrollHeight;
                fetch('chat.php?action=older&before=' + encodeURIComponent(oldestId),
                      { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        apply(d, true);
                        // Hold the reading position: without this, inserting
                        // above jumps the view to a different message.
                        log.scrollTop = log.scrollHeight - before;
                    })['catch'](function () { showError(fmt(L.chatFailed, '')); });
            });
        }

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                showError('');
                var body = (input.value || '').trim();
                if (!body) return;
                var fd = new FormData(form);
                fetch('chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.ok) {
                            input.value = '';
                            // Poll immediately rather than rendering the reply
                            // locally: one code path builds the list, so a
                            // message can never appear twice or out of order.
                            poll();
                            scrollDown();
                            // The anti-bot timestamp is single-use per render;
                            // refresh it so a second message isn't rejected.
                            var ts = form.querySelector('input[name="af_ts"]');
                            if (ts) ts.value = Math.floor(Date.now() / 1000);
                        } else {
                            var code = d && d.error;
                            showError(code === 'name'  ? fmt(L.chatErrName, '')
                                    : code === 'empty' ? fmt(L.chatErrEmpty, '')
                                    : code === 'long'  ? fmt(L.chatErrLong, '')
                                    : fmt(L.chatFailed, ''));
                        }
                    })['catch'](function () { showError(fmt(L.chatFailed, '')); });
            });
        }
    }

    // 9. Mobile nav toggle. The panel is plain CSS below the breakpoint; this
    // only flips the class and keeps aria-expanded honest.
    function initNavBurger() {
        var burger = document.getElementById('nav-burger');
        var nav    = document.getElementById('topnav');
        if (!burger || !nav) return;

        burger.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Close on Escape: a panel that covers the page needs a way out that
        // isn't hunting for the button again.
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            if (!nav.classList.contains('is-open')) return;
            nav.classList.remove('is-open');
            burger.setAttribute('aria-expanded', 'false');
            burger.focus();
        });

        // Resizing past the breakpoint leaves the class set but the panel
        // styled as a normal inline row; clearing it keeps aria-expanded from
        // claiming "open" for a menu that no longer exists.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 700 && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                burger.setAttribute('aria-expanded', 'false');
            }
        });
    }

})();

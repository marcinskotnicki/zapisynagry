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
        initTabScroll();
        initOptionGroups();
        initSecretFields();
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

        // $historic says whether this response can speak about what came BEFORE
        // what is on screen. The initial load and "load earlier" can; a poll
        // cannot — it only ever reports what arrived since, and a quiet poll
        // returns nothing at all, which would otherwise read as "no history"
        // and hide the button every few seconds.
        function apply(data, prepend, historic) {
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
            if (earlier && historic) earlier.hidden = !data.more;
        }

        function load() {
            var wasDown = atBottom();
            fetch('chat.php?action=fetch', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    apply(d, false, true);
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
                    apply(d, false, false);
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

        // Hand over from the `hidden` attribute to a class. The attribute is the
        // no-JS fallback and maps to `display: none`, which cannot be animated;
        // from here the panel is driven by .is-open so it can slide.
        panel.removeAttribute('hidden');

        // One implementation of each direction, so the toggle button and the
        // click-outside handler can never drift apart.
        function openChat() {
            panel.classList.add('is-open');
            document.body.classList.add('chat-open');
            toggle.setAttribute('aria-expanded', 'true');
            if (!loaded) load(); else scrollDown();
            start();
        }
        function closeChat() {
            panel.classList.remove('is-open');
            document.body.classList.remove('chat-open');
            toggle.setAttribute('aria-expanded', 'false');
            // Polling stops with the panel: an idle background tab should not
            // keep hitting the server every few seconds.
            stop();
        }

        toggle.addEventListener('click', function () {
            if (panel.classList.contains('is-open')) closeChat(); else openChat();
        });

        // Optional: a click anywhere outside closes the panel.
        //
        // Bound on mousedown rather than click so a click that STARTS inside
        // the panel and ends outside — dragging to select a message, or
        // releasing off a button — is not treated as clicking away.
        //
        // The toggle is excluded, or its own handler would reopen what this
        // just closed.
        if (window.APP_CHAT.closeOutside) {
            document.addEventListener('mousedown', function (ev) {
                if (!panel.classList.contains('is-open')) return;
                if (panel.contains(ev.target) || toggle.contains(ev.target)) return;
                closeChat();
            });
        }

        if (earlier) {
            earlier.addEventListener('click', function () {
                if (!oldestId) return;
                var log = document.getElementById('chat-log');
                var before = log.scrollHeight;
                fetch('chat.php?action=older&before=' + encodeURIComponent(oldestId),
                      { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        apply(d, true, true);
                        // Hold the reading position: without this, inserting
                        // above jumps the view to a different message.
                        log.scrollTop = log.scrollHeight - before;
                    })['catch'](function () { showError(fmt(L.chatFailed, '')); });
            });
        }

        // Brief cool-off after sending. This is about someone hammering the
        // button because their message has not shown up yet, not about bots —
        // the server-side anti-bot check is the real gate.
        var sendBtn  = form ? form.querySelector('.chat-send') : null;
        var coolTimer = null;
        function coolOff() {
            var ms = window.APP_CHAT.sendDelay;
            if (!sendBtn || !ms) return;
            // Drive the CSS countdown from the admin's actual setting, so the
            // bar finishes exactly when the button comes back rather than at
            // some fixed guess.
            sendBtn.style.setProperty('--chat-cooloff', (ms / 1000) + 's');
            sendBtn.disabled = true;
            if (coolTimer) window.clearTimeout(coolTimer);
            coolTimer = window.setTimeout(function () {
                sendBtn.disabled = false;
                coolTimer = null;
            }, ms);
        }

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                showError('');
                var body = (input.value || '').trim();
                // Nothing was sent, so nothing to wait for — starting the
                // cool-off here would punish a stray click on an empty box.
                if (!body) return;
                coolOff();
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
                            // Refused: release the button at once so they can
                            // correct it, rather than sitting out a wait for a
                            // message that never posted.
                            if (sendBtn) {
                                if (coolTimer) { window.clearTimeout(coolTimer); coolTimer = null; }
                                sendBtn.disabled = false;
                            }
                            showError(code === 'name'  ? fmt(L.chatErrName, '')
                                    : code === 'empty' ? fmt(L.chatErrEmpty, '')
                                    : code === 'long'  ? fmt(L.chatErrLong, '')
                                    : fmt(L.chatFailed, ''));
                        }
                    })['catch'](function () {
                        if (sendBtn) {
                            if (coolTimer) { window.clearTimeout(coolTimer); coolTimer = null; }
                            sendBtn.disabled = false;
                        }
                        showError(fmt(L.chatFailed, ''));
                    });
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

    // 10. Scroll affordance for the tab strips (day tabs, event tabs).
    // CSS cannot ask whether an element overflows, so the arrows are toggled
    // from here — they appear only when there is really something to scroll to,
    // and each one hides again once that end is reached.
    function initTabScroll() {
        var wraps = document.querySelectorAll('.tab-scroll');
        if (!wraps.length) return;

        for (var i = 0; i < wraps.length; i++) {
            (function (wrap) {
                var strip = wrap.querySelector('.day-tabs, .event-tabs');
                if (!strip) return;

                function sync() {
                    // A 2px tolerance: fractional layout widths mean scrollLeft
                    // rarely lands exactly on the maximum, which would otherwise
                    // leave the right arrow lit at the very end.
                    var max = strip.scrollWidth - strip.clientWidth;
                    wrap.classList.toggle('can-scroll-left', strip.scrollLeft > 2);
                    wrap.classList.toggle('can-scroll-right', strip.scrollLeft < max - 2);
                }

                // Two escalating steps for a strip that does not fit, decided
                // here because CSS cannot ask whether an element overflows.
                //
                //   1. .tab-scroll-wide — let the strip use the whole window
                //      rather than the narrower content column. Padding puts the
                //      first tab back under the content's left edge, so nothing
                //      looks shunted sideways; only the room it may scroll
                //      through grows. For a lot of clubs this alone is enough.
                //   2. .tab-scroll-over — still too narrow even then, so it
                //      scrolls. The day tabs stop WRAPPING at this point:
                //      wrapping to four rows is worse than one scrolling row,
                //      but wrapping is right for the handful of days most clubs
                //      have, which is why it is not the default.
                //
                // Measured with the classes OFF first, so the answer is about
                // the content and not about a state left over from last time —
                // otherwise a strip that widened once would stay wide forever,
                // including after a resize that made room again.
                function widen(on) {
                    if (!on) {
                        wrap.classList.remove('tab-scroll-wide');
                        wrap.style.marginLeft = wrap.style.marginRight = '';
                        wrap.style.paddingLeft = wrap.style.paddingRight = '';
                        return;
                    }
                    /* Pixel values measured here rather than `width: 100vw` in
                     * CSS: 100vw INCLUDES the vertical scrollbar, so on a normal
                     * desktop page it overshoots by ~15px and gives the whole
                     * document a horizontal scrollbar. documentElement.clientWidth
                     * excludes it, so this lands exactly on the visible width.
                     *
                     * The negative margins pull the box out to the window edges;
                     * the matching padding puts the first tab back under the
                     * content's left edge, so nothing looks shunted sideways —
                     * only the room the strip may scroll through grows. */
                    var docW = document.documentElement.clientWidth;
                    var r = wrap.getBoundingClientRect();
                    var leftGap = Math.max(0, Math.round(r.left));
                    var rightGap = Math.max(0, Math.round(docW - r.right));
                    wrap.classList.add('tab-scroll-wide');
                    wrap.style.marginLeft = -leftGap + 'px';
                    wrap.style.marginRight = -rightGap + 'px';
                    wrap.style.paddingLeft = leftGap + 'px';
                    wrap.style.paddingRight = rightGap + 'px';
                }

                function fit() {
                    // Cleared before measuring, so the answer is about the
                    // content and not about a state left over from last time —
                    // otherwise a strip that widened once would stay wide even
                    // after a resize made room again.
                    widen(false);
                    wrap.classList.remove('tab-scroll-over');
                    if (strip.scrollWidth <= strip.clientWidth) return;

                    widen(true);
                    // Re-measured once the widening has landed: the strip is
                    // bigger now, and that may already be enough.
                    if (strip.scrollWidth <= strip.clientWidth) return;

                    wrap.classList.add('tab-scroll-over');
                }

                strip.addEventListener('scroll', sync);
                // The day tabs WRAP on desktop and only scroll on mobile, so the
                // answer changes with the viewport, not just with the content.
                window.addEventListener('resize', function () {
                    fit();
                    sync();
                });

                // Bring the CURRENT tab into view. On a phone showing four of
                // ten days, picking day seven reloads to a strip scrolled back
                // to day one — the tab you just chose is off-screen, and there
                // is nothing to say the strip moves at all.
                //
                // scrollLeft is set directly rather than using
                // scrollIntoView(): that scrolls every scrollable ancestor,
                // including the window, so landing on a day would also jump the
                // page down to the tab strip. This moves the strip and nothing
                // else.
                function centreActive() {
                    // Only when it actually scrolls: on desktop the strip wraps
                    // instead, and nudging scrollLeft there does nothing useful.
                    if (strip.scrollWidth <= strip.clientWidth) return;
                    var active = strip.querySelector('.day-tab-active, .event-tab-active');
                    if (!active) return;

                    // Rect deltas rather than offsetLeft: offsetLeft is measured
                    // from the offsetParent, which is whatever happens to be
                    // positioned nearby and is not reliably the strip.
                    var sr = strip.getBoundingClientRect();
                    var ar = active.getBoundingClientRect();
                    // Centre it, then clamp — a tab near either end cannot be
                    // centred, and asking for it would leave a gap.
                    var delta = (ar.left - sr.left) - (sr.width - ar.width) / 2;
                    strip.scrollLeft = Math.max(0, Math.min(strip.scrollLeft + delta,
                                                            strip.scrollWidth - strip.clientWidth));
                    sync();
                }

                // fit() BEFORE centreActive(): widening or switching to a
                // scrolling row changes clientWidth, and centring against the
                // pre-widening measurement would aim at the wrong place.
                fit();
                centreActive();
                sync();
            })(wraps[i]);
        }
    }

    // 11. Options accordion: only one group open at a time.
    // <details name="..."> already does this natively in current browsers; this
    // is the fallback for ones that ignore the attribute, and is a no-op where
    // the browser has already closed the siblings itself.
    function initOptionGroups() {
        var groups = document.querySelectorAll('details.opt-group[name]');
        if (groups.length < 2) return;

        for (var i = 0; i < groups.length; i++) {
            groups[i].addEventListener('toggle', function () {
                if (!this.open) return;
                for (var j = 0; j < groups.length; j++) {
                    if (groups[j] !== this && groups[j].open) groups[j].open = false;
                }
            });
        }
    }

    // 12. Credential fields show a row of stars when a value is stored. Select
    // it on focus so typing replaces it, instead of making the admin delete the
    // stars by hand first.
    function initSecretFields() {
        var fields = document.querySelectorAll('input.secret-field');
        for (var i = 0; i < fields.length; i++) {
            fields[i].addEventListener('focus', function () {
                if (/^\*+$/.test(this.value)) this.select();
            });
        }
    }

})();

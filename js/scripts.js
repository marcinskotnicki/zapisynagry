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
 *  This file grows in the front-end phase (modals, add-game flow, etc.).
 * ========================================================================== */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initDateCascade();
        initCopyButtons();
        initHashHighlight();
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
})();
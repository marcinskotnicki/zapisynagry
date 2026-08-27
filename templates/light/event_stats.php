<?php
/* =============================================================================
 *  templates/light/event_stats.php — the games/players summary for one event.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Rendered inside the row loop on both event lists, so the
 *  two cannot drift apart — one icon set, one order, one wording.
 *
 *  The icons are inline SVG rather than an image or a font: they inherit the
 *  surrounding colour through currentColor, which means they come out right on
 *  all fourteen themes without any of them being told about this feature.
 *
 *  Both numbers are counts of THINGS THAT HAPPENED, not of distinct people —
 *  see events_stats() for why a per-person count is not answerable here. The
 *  title attributes say so in words, since two bare numbers behind icons are
 *  otherwise open to being read as "how many members we have".
 *
 *  RENDER VARS:
 *    $stats — ['games' => int, 'players' => int] for this one event.
 * ============================================================================= */
?>
<span class="event-stats">
    <span class="event-stat" title="<?= e(t('evstats_games')) ?>">
        <?php // A box: the games themselves. ?>
        <svg class="event-stat-icon" viewBox="0 0 24 24" aria-hidden="true"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 8 12 3 3 8v8l9 5 9-5V8z"></path>
            <path d="M3 8l9 5 9-5"></path>
            <path d="M12 13v8"></path>
        </svg>
        <span class="event-stat-n"><?= (int)$stats['games'] ?></span>
    </span>
    <span class="event-stat-sep">/</span>
    <span class="event-stat" title="<?= e(t('evstats_players')) ?>">
        <?php // A person: the seats those games filled. ?>
        <svg class="event-stat-icon" viewBox="0 0 24 24" aria-hidden="true"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"></path>
        </svg>
        <span class="event-stat-n"><?= (int)$stats['players'] ?></span>
    </span>
</span>

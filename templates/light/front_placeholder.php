<?php
/* =============================================================================
 *  templates/light/front_placeholder.php — front page when there's no event.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. index.php renders this instead of the full event view
 *  when current_event() is null (a brand-new install, or every event archived
 *  with no live one). It nudges an admin toward "create event"; for everyone
 *  else it just shows the venue name and a friendly "no event yet".
 *
 *  RENDER VARS:
 *    $event — an event row, or null. In practice index.php only routes here when
 *             it's null; the $event branch is a defensive fallback.
 * ============================================================================= */
?>
<div class="card">
    <?php if (empty($event)): // the normal case: nothing scheduled yet ?>
        <h1><?= e(opt('venue_name') ?: t('app_name')) ?></h1>
        <p class="muted">No event yet.</p>
        <?php if (is_admin()): // only admins can create one, so only they see the button ?>
            <p><a class="btn btn-primary" href="admin.php?tab=new_event"><?= e(t('tab_new_event')) ?></a></p>
        <?php endif; ?>
    <?php else: // defensive fallback (index.php uses front_event for a real event) ?>
        <h1><?= e($event['name']) ?></h1>
        <p class="muted">Front-end view is built in the next phase.</p>
    <?php endif; ?>
</div>

<?php
/* =============================================================================
 *  templates/light/notify_optin.php — "email me about this" checkbox.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Shown on the four forms that record an address: adding a
 *  game, signing up, starting a poll and voting.
 *
 *  RENDERS NOTHING unless the club's notify_mode leaves the choice to people.
 *  Under 'always' or 'never' the club has already decided, and a checkbox that
 *  changes nothing is worse than no checkbox — so the caller can include this
 *  unconditionally and let the mode decide.
 *
 *  The starting state is the club's default, unless this person has chosen
 *  before — see notify_optin_default(), which remembers it for members.
 * ============================================================================= */
if (!notify_user_choice()) return;
?>
<div class="field field-notify">
    <label class="check">
        <input type="checkbox" name="notify_me" value="1"<?= notify_optin_default() ? ' checked' : '' ?>>
        <?= e(t('notify_optin')) ?>
    </label>
</div>

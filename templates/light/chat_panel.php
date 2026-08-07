<?php
/* =============================================================================
 *  templates/light/chat_panel.php — the shoutbox panel. Presentation only.
 * -----------------------------------------------------------------------------
 *  Rendered on every page (by footer.php) when the chat is enabled, and left
 *  closed until the toggle is clicked. The message list is filled entirely by
 *  scripts.js — nothing is server-rendered into it — so the initial load and
 *  the poll go through exactly one code path instead of two that can disagree.
 *
 *  RENDER VARS:
 *    $csrf        — hidden CSRF field (posting only).
 *    $antibot     — hidden anti-bot fields.
 *    $logged_in   — bool; hides the name field and makes the list taller.
 *    $can_post    — bool; false shows a "sign in to join in" note in place of
 *                   the form (chat_logged_in_only). The LOG still renders —
 *                   a guest who can read the conversation has a reason to
 *                   register, which a blank panel would not give them.
 *    $guest_name  — remembered guest name, prefilled.
 * ============================================================================= */
?>
<?php // The toggle lives outside the panel so it stays clickable when the panel
      // is closed. aria-expanded/aria-controls give it real button semantics
      // rather than "a div you happen to be able to click". ?>
<button type="button" class="chat-toggle" id="chat-toggle"
        aria-expanded="false" aria-controls="chat-panel"
        aria-label="<?= e(t('chat_title')) ?>" title="<?= e(t('chat_title')) ?>">
    <span class="chat-toggle-open" aria-hidden="true">&#128172;</span>
    <span class="chat-toggle-close" aria-hidden="true">&times;</span>
</button>

<aside class="chat-panel<?= $logged_in ? ' chat-panel-auth' : '' ?>" id="chat-panel" hidden>
    <div class="chat-head">
        <h2 class="chat-title"><?= e(t('chat_title')) ?></h2>
    </div>

    <?php // Filled by scripts.js. The "load earlier" button starts hidden and is
          // revealed only when the server says older messages actually exist,
          // so it never sits there doing nothing. ?>
    <div class="chat-log" id="chat-log" role="log" aria-live="polite" aria-atomic="false">
        <button type="button" class="btn btn-small chat-earlier" id="chat-earlier" hidden>
            <?= e(t('chat_load_earlier')) ?>
        </button>
        <div class="chat-messages" id="chat-messages"></div>
    </div>

    <?php if (!$can_post): ?>
        <?php // Not a disabled form: an input nobody can use invites clicking at
              // it. A plain note with the two links says what to do instead. ?>
        <p class="chat-locked">
            <?= e(t('chat_login_required')) ?>
            <a href="login.php"><?= e(t('login')) ?></a> ·
            <a href="register.php"><?= e(t('register')) ?></a>
        </p>
    <?php else: ?>
    <form class="chat-form" id="chat-form" method="post" action="chat.php" data-allow-resubmit>
        <?= $csrf ?>
        <?= $antibot ?>
        <input type="hidden" name="action" value="post">
        <?php if (!$logged_in): ?>
            <input type="text" class="chat-name" name="name" id="chat-name"
                   value="<?= e($guest_name) ?>" maxlength="200"
                   placeholder="<?= e(t('chat_name_placeholder')) ?>" autocomplete="nickname">
        <?php endif; ?>
        <div class="chat-send-row">
            <input type="text" class="chat-input" name="message" id="chat-message"
                   maxlength="500" placeholder="<?= e(t('chat_message_placeholder')) ?>"
                   autocomplete="off">
            <button type="submit" class="btn btn-small btn-primary chat-send"><?= e(t('chat_send')) ?></button>
        </div>
        <p class="chat-error" id="chat-error" hidden></p>
    </form>
    <?php endif; ?>
</aside>

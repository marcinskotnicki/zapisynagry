<?php
/* =============================================================================
 *  templates/light/admin_msg_subtabs.php — Chat / Comments switcher.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Shared by both sub-tabs so the pair cannot end up
 *  disagreeing about which one is open.
 *
 *  RENDER VARS:
 *    $sub — 'chat' | 'comments', the one currently showing.
 * ============================================================================= */
$sub = $sub ?? 'chat';
?>
<nav class="subtabs">
    <?php // Chat first: it is what this tab used to be on its own, so an admin
          // who knew where things were still finds them in the same place. ?>
    <a class="subtab<?= $sub === 'chat' ? ' subtab-active' : '' ?>"
       href="admin.php?tab=chat"<?= $sub === 'chat' ? ' aria-current="page"' : '' ?>>
        <?= e(t('msg_sub_chat')) ?>
    </a>
    <a class="subtab<?= $sub === 'comments' ? ' subtab-active' : '' ?>"
       href="admin.php?tab=chat&amp;sub=comments"<?= $sub === 'comments' ? ' aria-current="page"' : '' ?>>
        <?= e(t('msg_sub_comments')) ?>
    </a>
</nav>

<?php
/* =============================================================================
 *  defaults/texts/en.php — ready-made instruction texts, English.
 * -----------------------------------------------------------------------------
 *  These are OFFERED, never forced. The Options screen has a "load standard
 *  instruction texts" button that copies them into any custom message field
 *  that is still EMPTY; anything a club has already written is left alone.
 *
 *  WHO THEY ARE WRITTEN FOR: somebody who has not used a sign-up board before,
 *  and may be new to board games generally. So: short, plain, and concrete
 *  about what to press. No jargon that is not immediately explained — the
 *  add-a-game note is the only place "BoardGameGeek" appears, and it says what
 *  it is in the same breath.
 *
 *  HOW THEY ARE RENDERED: as a single escaped paragraph, so no HTML. Numbered
 *  steps run inline as "1) … 2) …", which reads fine in one line and survives
 *  any theme.
 *
 *  Keys are the custom message keys from custom_msg_keys(). A key missing here
 *  simply has no standard text — the field is left for the club to write.
 *  Editing this file is the intended way to change the wording; nothing else
 *  needs touching.
 * ============================================================================= */

return [

    // The event page — the first thing most visitors see.
    'msg_below_event' =>
        'New here? This page shows every table and the games planned on them. '
        . '1) Pick a day at the top. 2) Find a game you like the look of. '
        . '3) Press "Sign up" to take one of its seats. You do not need an account.',

    // The add-a-game gate: name field, then BGG / poll / manual buttons.
    'msg_adding_game' =>
        'Type the name of the game you want to bring, then choose how to add it. '
        . 'BoardGameGeek is a big online database of board games — adding from '
        . 'there fills in the cover picture, playing time and number of players '
        . 'for you. If your game is not there, or you cannot find it, use '
        . '"add manually" and just type the details yourself.',

    // Taking a seat at a game.
    'msg_assigning_player' =>
        'Give the name you want other players to see — a first name is plenty. '
        . 'If the game is already full you can still join the reserve list, and '
        . 'you will get the seat if somebody drops out.',

    // Starting a poll.
    'msg_adding_poll' =>
        'Not sure what to play? Start a poll instead of a single game. '
        . '1) Add two or more games people could choose from. 2) Everyone votes '
        . 'for the ones they fancy. 3) When enough players want the same game, '
        . 'it becomes a normal table you can sign up to.',

    // Voting in a poll.
    'msg_voting' =>
        'Vote for every game you would happily play — not just one. '
        . 'The more options you tick, the better the chance of finding something '
        . 'the whole table agrees on. You can change your mind later.',

    // The note above email fields.
    'msg_email_field' =>
        'Your email is only used to let the organiser or the other players at '
        . 'your table contact you about this game. It is never shown on the page.',

    // A member's own game library.
    'msg_my_library' =>
        'List the games you own so other members can see what is around. '
        . '1) Add a game from BoardGameGeek by pasting its address, or add one '
        . 'by hand. 2) Hide anything that is lent out or kept elsewhere — it '
        . 'stays on your list, but others will not see it as available.',

    // The shared club library.
    'msg_library' =>
        'These are the games club members own between them. '
        . 'Under "Games" you can see who has what; under "Members" you can '
        . 'browse one person\'s shelf. If you would like to play something, '
        . 'ask whoever owns it to bring it along.',
];

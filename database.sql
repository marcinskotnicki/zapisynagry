-- =============================================================================
--  zapisynagry — board game event signup app
--  SQLite schema (database.sql)
-- -----------------------------------------------------------------------------
--  This single file is consumed by the install script to build the database.
--  Design decisions (agreed in spec discussion):
--    * ONE database, everything scoped by event_id. Archived events keep their
--      rows; nothing is moved to separate files. Makes all-time stats trivial.
--    * Settings live in a key/value `options` table (not columns) so the update
--      script can introduce new settings by INSERTing a row — never a migration.
--    * Times are stored as TEXT 'HH:MM', dates as TEXT 'YYYY-MM-DD'. SQLite has
--      no native date/time type; PHP converts to minutes for timeline math.
--    * Booleans are INTEGER 0/1.
--    * "Choice" fields (explain-rules, knows-rules, verification method, etc.)
--      are stored as small INTEGER codes, NOT display text. The human-readable
--      label comes from the active language file. This keeps the DB language
--      neutral, which the multilingual requirement needs.
--    * Foreign keys use ON DELETE CASCADE where a child cannot outlive its
--      parent (e.g. deleting a game removes its players/comments). The "delete
--      everything" game action therefore just deletes the game row.
--
--  Enable FK enforcement on every connection from PHP with:
--      $db->exec('PRAGMA foreign_keys = ON;');
--  (SQLite has it OFF by default per-connection.)
-- =============================================================================

PRAGMA foreign_keys = ON;


-- =============================================================================
--  meta — internal app bookkeeping (NOT admin-editable). Distinct from
--  `options` so user settings and engine state never get mixed up. The update
--  script reads/writes schema_version here to decide what migrations to run.
-- =============================================================================
CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Bump this string whenever the schema changes; the update script compares it
-- against the version shipped in a new release.
INSERT INTO meta (key, value) VALUES ('schema_version', '10');


-- =============================================================================
--  options — every admin-editable setting/default/toggle as a key/value row.
--  Seeded below with defaults so a freshly installed app is immediately usable.
--  value is always TEXT; PHP casts as needed (intval / floatval / "1"==on).
-- =============================================================================
CREATE TABLE options (
    key   TEXT PRIMARY KEY,
    value TEXT
);

INSERT INTO options (key, value) VALUES
    -- ---- Settings (free text / numeric) -------------------------------------
    ('venue_name',            ''),     -- name of the venue
    ('email_address',         ''),     -- "from" address for outgoing mail
    ('email_login',           ''),     -- SMTP username
    ('email_password',        ''),     -- SMTP password
    ('email_smtp_server',     ''),     -- SMTP host; empty => fall back to mail()
    ('email_smtp_port',       '587'),  -- SMTP port
    ('max_tables',            '0'),    -- max gaming tables per day; 0 = unlimited
    ('bgg_api_code',          ''),     -- kept for forward-compat; xmlapi2 is public
    ('captcha_site_key',      ''),     -- reCAPTCHA site key (optional)
    ('captcha_secret_key',    ''),     -- reCAPTCHA secret key (optional)
    -- captcha_version: which reCAPTCHA the keys above belong to —
    --   'v2' = "I'm not a robot" checkbox (a visible challenge)
    --   'v3' = invisible, score-based (needs JS; rejects below the threshold)
    -- Key types are NOT interchangeable: a v3 key in v2 mode gives Google's
    -- "Invalid key type" error, and vice versa.
    ('captcha_version',       'v2'),
    -- captcha_v3_threshold: minimum v3 score (0.0-1.0) treated as human.
    -- Google's usual starting point is 0.5; raise it to be stricter.
    ('captcha_v3_threshold',  '0.5'),
    ('timeline_extension',    '3'),    -- hours added past the day's end on timeline
    ('login_days',            '365'),  -- how long logins persist (days); 0 = browser session only
    ('poll_default_deadline_hours', '48'),  -- default: polls close this many hours BEFORE the planned start
    ('allow_custom_game_links', '1'),  -- 1 = non-BGG games may carry a user-supplied link
    -- allow_manual_links: 1 = the person bringing a game may attach a rules /
    -- manual URL (a PDF, a video) which shows as a button on the card. Off
    -- turns the field and the button off everywhere; existing values are kept
    -- in the database but never rendered, so it is reversible.
    ('allow_manual_links',    '1'),
    -- home_layout: 'tables_first' (default) | 'timeline_first'. Purely a
    -- visual order swap (flex 'order' in CSS) — the tables list and the
    -- timeline are always both rendered; this never removes either.
    ('home_layout',           'tables_timeline_mail'),
    -- Optional per-day labels ("Tournament", "Family day"). Off by default:
    -- most events have nothing useful to call their days.
    ('use_day_names',         '0'),
    -- What a day tab shows, and in what order. See day_tab_parts().
    ('day_tab_format',        'num_date_hours'),
    -- Free HTML dropped at the very bottom of every page (copyright lines,
    -- parent-club or sponsor links). Rendered UNESCAPED on purpose: only an
    -- admin can set it, and the whole point is to allow markup.
    ('footer_custom_text',    ''),
    -- site_logo: version stamp, '' = no logo uploaded (same convention as
    -- site_icon). header.php shows logo.png INSTEAD of the venue-name text
    -- when both this is non-empty AND show_venue_name is on.
    ('site_logo',             ''),
    -- github_url: overrides the GITHUB_* coords config.php was installed
    -- with, for a fork. EMPTY means "use whatever install.php configured",
    -- which is the right default: seeding a literal URL here would be
    -- wrong for anyone who entered their own repo during install.
    ('github_url',            ''),
    -- Branch to pull updates from. Empty = inherit config.php's GITHUB_BRANCH,
    -- the same rule as github_url above: seeding a literal here would
    -- override whatever the installer was told.
    ('github_branch',         ''),
    -- Chat (shoutbox). Off by default: it is an unmoderated public text field,
    -- so it should be a deliberate choice rather than something a new install
    -- discovers it is already running.
    ('chat_enabled',          '0'),
    -- 'event' = wipe the log whenever a new event is created (the chat is
    -- about tonight); 'global' = keep it across events, trimmed only by size.
    ('chat_scope',            'event'),
    ('chat_max_messages',     '500'),   -- hard cap; oldest trimmed past this
    ('chat_initial_messages', '20'),    -- how many the panel shows on open
    -- Poll interval while the panel is open. 30s keeps the load light on
    -- shared hosting and is fine for a room-sized conversation; an admin can
    -- lower it where a busier club wants livelier updates.
    ('chat_refresh_seconds',  '30'),
    -- Seconds the send button stays disabled after a post. Aimed at someone
    -- hammering the button because their message has not appeared yet, not
    -- at bots; 0 disables the wait entirely.
    ('chat_send_delay',       '2'),
    -- Click anywhere outside the panel to close it. Off by default: the
    -- chat is a side panel people may deliberately leave open while
    -- reading the page, and closing it on every stray click would be
    -- worse than an extra press of the toggle.
    ('chat_close_outside',    '0'),
    ('chat_logged_in_only',   '0'),
    ('notify_new_event',      '0'),
    ('custom_css',            ''),
    ('club_library',          '0'),
    ('library_show_members',  '1'),
    ('library_allow_contact', '0'),
    ('library_mail_venue',    '1'),
    -- 'all' | 'pages' | 'alpha'. Defaults to 'alpha' for a NEW install — a
    -- club's shared library tends to grow past a screenful, and grouping by
    -- letter is useful from the start rather than something to discover later.
    -- An install that upgrades keeps whatever it already has: this default is
    -- read only when database.sql seeds a fresh options table, never applied
    -- to a row that already exists.
    ('library_pagination',    'alpha'),
    -- The CLUB's own shelf, independent of the members' shared library above:
    -- either can be on without the other.
    ('club_shelf',            '0'),
    -- Offer the club's own games as a source when adding a game to a table or a
    -- poll. Only does anything while club_shelf is on: there is nothing to pick
    -- from otherwise.
    ('club_shelf_pick',       '0'),
    -- Put the club's own games first: the pick button leads the add-a-game
    -- screen instead of sitting under the search, and the club tab opens by
    -- default on the library page. For clubs whose cabinet is the usual source.
    ('library_prefer_club',   '0'),
    -- Offer a second name box on the sign-up form, so one person can enter
    -- another (a parent booking a seat for a child).
    ('signup_proxy_name',     '0'),
    -- Restrict that second box to signed-in members. Only means anything while
    -- signup_proxy_name is on.
    ('signup_proxy_members',  '0'),
    -- Offer a "choose version" button beside the game name when proposing a
    -- game from BGG, so a club running a localised copy can label the table
    -- with the title on the box.
    ('game_version_pick',     '0'),
    -- Also list the club's own games on the members' library page, credited to
    -- "CLUB". They keep their own tab either way; this only adds them to the
    -- merged list, where a game the club AND members own shows one line with
    -- CLUB first among the owners.
    ('library_show_club',     '0'),
    -- Where a "contact CLUB about this game" message goes. Empty means no
    -- contact button for the club — there is nowhere to send it.
    ('library_club_email',    ''),
    ('library_per_page',      '50'),
    ('last_update_at',        ''),
    ('remote_commit_cache',   ''),
    -- Public archives. Off by default: switching it on changes how EVENTS
    -- behave (creating a new one no longer archives the old), so it must be a
    -- deliberate choice rather than a surprise on upgrade.
    ('public_archives',       '0'),
    ('archive_per_page',      '20'),   -- events per page on the public list
    ('admin_per_page',        '50'),   -- rows per page on admin lists
    -- Days of audit log to keep; 0 keeps everything. A year by default: the
    -- rows are tiny (~114 bytes each, so a busy club is a megabyte or two a
    -- year), but they carry names and IP addresses, and keeping personal
    -- data forever with no reason to is exactly what a retention period is
    -- for.
    ('log_retention_days',    '365'),
    ('log_pruned_on',         ''),      -- date of the last prune; throttles it to daily
    -- 0 = never auto-archive. Otherwise an event is archived this many days
    -- after its LAST day ends. Only consulted when public_archives is on.
    ('auto_archive_days',     '0'),
    ('site_icon',             ''),     -- '' = no site icon; otherwise a version stamp (files live in /icons)
    ('game_languages',        'PL
EN
niezależna językowo
inna'),                                -- game-language dropdown options, ONE PER LINE (admin-editable)

    -- ---- Defaults -----------------------------------------------------------
    ('default_event_name',    'Spotkanie planszowe'), -- prefilled new-event name
    ('default_start_time',    '10:00'),
    ('default_end_time',      '22:00'),
    ('default_language',      'pl'),   -- must match a file in /languages
    ('default_template',      'classic'),-- must match a dir in /templates; classic eases migration from the old app

    -- ---- Toggles (stored as "0"/"1", except the two enumerations) -----------
    ('allow_unregistered_add_games', '1'),
    ('allow_unregistered_signup',    '1'),
    -- registration_mode: 'registration' = accounts allowed, 'guest_only' =
    -- no accounts (the two toggles above are then irrelevant).
    ('registration_mode',            'registration'),
    ('send_emails',                  '1'),
    -- require_email: 0 = emails never required, 1 = always required,
    -- 2 = per-game: the proposer decides via a checkbox when adding a game or
    --     poll (and must then give their OWN email too).
    ('require_email',                '2'),
    -- overnight_grace_hours: times up to N hours BEFORE a day's opening hour
    -- still count as that same day (early setup); anything earlier flips to
    -- "after midnight / next morning" on overnight days. See day_rel_min().
    -- timezone: the clock the SITE runs on. Event start times, poll deadlines
    -- and the "has this deadline passed?" sweep are all wall-clock times, so
    -- this must match the venue's actual timezone or polls resolve at the wrong
    -- moment. Any PHP timezone identifier; an unknown value falls back to UTC.
    -- mailing_list: 0/1 master switch for the per-event mailing list (the
    -- signup box under the timeline, the new-game notifications, and the
    -- admin's Mailing tab).
    -- email_subject_prefix: 'venue' or 'event' — which name every outgoing
    -- subject is prefixed with. A venue with frequent events usually wants
    -- 'event', so recipients can tell one event's mail from another's.
    ('email_subject_prefix',         'venue'),
    ('mailing_list',                 '0'),
    -- mailing_gdpr_text: consent wording shown beside a REQUIRED checkbox on
    -- the signup box. Left empty, no checkbox is shown and none is demanded —
    -- so this doubles as the on/off switch for asking consent at all.
    ('mailing_gdpr_text',            ''),
    -- Start the consent box ticked for someone who already agreed to this
    -- exact wording. Convenience for regulars; switch it off for the
    -- stricter reading, where every consent is a fresh affirmative tick.
    ('gdpr_prefill',          '1'),
    -- antibot_delay_form / antibot_delay_click: minimum seconds between a
    -- guarded form's render and its submission (see inc/antibot.php). 0
    -- disables the check for that bucket. Ignored for logged-in users.
    ('antibot_delay_form',           '2'),
    ('antibot_delay_click',          '1'),
    -- antibot_honeypot: 0/1, an invisible "Website" field on the same forms —
    -- a real visitor never fills it in, a naive bot often does. Independent
    -- of the two delays above; also ignored for logged-in users.
    ('antibot_honeypot',             '1'),
    ('timezone',                     'Europe/Warsaw'),
    ('overnight_grace_hours',        '1'),
    -- allow_start_outside_hours: 1 = a game/poll may start at any time (current
    -- behaviour); 0 = the start-time input is clamped to the day's own hours
    -- (min = opening, max = closing) and the same is enforced server-side.
    ('allow_start_outside_hours',    '1'),
    -- header_button_style: how the top-bar nav links render —
    --   'text' = text only (current), 'icon' = icon only, 'both' = icon + text.
    ('header_button_style',          'both'),
    -- show_venue_name: 1 = show the venue name top-left (current); 0 = hide it,
    -- useful when the venue and event names are the same (avoids showing both).
    ('show_venue_name',              '1'),
    -- What the header's left slot shows: 'auto' (logo if one is uploaded,
    -- otherwise the venue name), 'both' (logo then name), or 'none'.
    -- Seeded EMPTY on purpose: empty means "derive from the older
    -- show_venue_name toggle above", so an existing site that had the
    -- name switched off does not have it reappear on update. Saving the
    -- Options form once makes the choice explicit.
    ('header_brand',          ''),
    -- site_url: absolute address of the app, used for the link at the foot of
    -- every email. Blank = work it out from the current request, which is fine
    -- for web-triggered mail but NOT for cron (no HTTP_HOST there), so set it
    -- if you use the deadline sweep.
    ('site_url',                     ''),
    -- verification_method for editing/deleting unregistered-added content:
    --   'none'        = no check, anyone may proceed
    --   'registered'  = must be logged in (no code/email)
    --   'email_code'  = 6-digit code emailed, must be entered
    --   'email_match' = retype original email, case-insensitive match
    -- (Admins and the original logged-in owner always skip this. If no email
    --  was stored on the item, the action is always free regardless of method.)
    ('verification_method',          'email_code'),
    -- table_names_mode: who may set / edit the optional table names:
    --   'off'     = table names are not used at all
    --   'admin'   = only admins may set and edit them
    --   'add_any' = anyone may set a name when ADDING a table; only admins edit
    --   'any'     = anyone may set and edit table names
    ('table_names_mode',             'off'),
    ('allow_polls',                  '1'),
    -- Who decides how a game is removed: 'choose' offers both, the other two
    -- take the decision away and always do that one.
    ('game_deletion',         'choose'),
    -- How a soft-deleted game appears: just its name, or the whole card
    -- greyed out with its sign-ups still visible.
    ('deleted_games_display', 'name'),
    ('allow_discussions',            '1'),
    ('use_captcha',                  '0'),
    ('allow_messaging',              '0'),
    ('allow_guest_messaging',        '0'),  -- 1 = anyone may send messages; 0 = logged-in accounts only
    ('allow_user_template',          '1'),  -- 1 = logged-in users may pick a theme (user panel)
    ('allow_guest_template',         '1'),  -- 1 = guests may pick a theme (topbar dropdown)
    ('allow_user_language',          '1'),  -- 1 = logged-in users may pick a language (user panel)
    ('allow_guest_language',         '1'),  -- 1 = guests may pick a language (topbar dropdown)
    -- WHERE the two switchers appear: 'header' | 'footer' | 'both' | 'none'.
    -- Separate from the allow_* flags above, which decide WHETHER a given
    -- audience may switch at all; these only decide placement.
    ('switcher_pos_template',        'footer'),
    ('switcher_pos_language',        'footer'),
    -- Whether logged-in users see the switchers in the page chrome. Off by
    -- default: they already have both controls in the user panel, so showing
    -- them twice is clutter — but some admins prefer the convenience.
    ('switcher_show_user_template',  '0'),
    ('switcher_show_user_language',  '0');


-- =============================================================================
--  users — registered accounts. Login is by EMAIL + password (no usernames).
--  display_name is shown publicly and need not be unique.
-- =============================================================================
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,          -- login identifier
    password_hash TEXT NOT NULL,                 -- password_hash() bcrypt output
    display_name  TEXT NOT NULL,
    is_admin      INTEGER NOT NULL DEFAULT 0,    -- 0/1
    is_blocked    INTEGER NOT NULL DEFAULT 0,    -- 0/1; blocked accounts cannot log in
    -- Opt-in mail when a new event is created. Defaults to 0: nobody is signed
    -- up to anything by having an account, and existing accounts must not start
    -- receiving mail because a site upgraded.
    notify_new_event INTEGER NOT NULL DEFAULT 0,
    -- May other members contact this one about their library games? Defaults to
    -- 1 (the member-facing checkbox is pre-ticked), but it only has any effect
    -- while the admin's library_allow_contact option is on — so the default
    -- never exposes anyone until an admin deliberately enables the feature.
    library_contact_ok INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Persistent logins ("remember me"). One row per logged-in DEVICE: the cookie
-- holds a random token, this table stores only its sha256 (a leaked DB cannot
-- be replayed as cookies). Sliding expiry: active use pushes expires_at ahead.
-- Logging out deletes that device's row; expired rows are purged on new logins.
CREATE TABLE auth_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token_hash  TEXT NOT NULL,                    -- sha256(raw cookie value)
    expires_at  TEXT NOT NULL,                    -- 'Y-m-d H:i:s', server time
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Password recovery: one-time tokens emailed as a reset link.
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT NOT NULL UNIQUE,             -- random, goes in the email link
    expires_at TEXT NOT NULL,                    -- 'YYYY-MM-DD HH:MM:SS'
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


-- =============================================================================
--  events — one row per event. Exactly one is "current" at a time; the rest
--  are archived (kept viewable via access_token). Creating a new event archives
--  the previous one.
-- =============================================================================
--  is_deleted = hidden from the whole front end, recoverable from the admin
--  Archive tab. Deleting ALWAYS sets is_archived = 1 as well, which is what
--  makes this safe to add: every controller already refuses to edit an
--  archived event, so a deleted one inherits those guards without fourteen
--  separate permission checks having to learn about the new flag.
--  A hard "delete permanently" removes the row and cascades days, tables,
--  games, players, comments and polls with it.
CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    num_days     INTEGER NOT NULL DEFAULT 1,
    is_archived  INTEGER NOT NULL DEFAULT 0,     -- 0 = current, 1 = archived
    is_deleted   INTEGER NOT NULL DEFAULT 0,     -- 1 = hidden everywhere but the Archive tab
    access_token TEXT NOT NULL UNIQUE,           -- unguessable; used for archive links
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    archived_at  TEXT,                           -- set when archived
    deleted_at   TEXT,                           -- set when deleted
    -- Which day opens by default when no ?day= is given. NULL (the default)
    -- keeps the old behaviour: the first day. Stored as a day_index rather than
    -- an event_days.id so it survives days being rebuilt when an event's length
    -- changes — and it is re-checked against the days that actually exist, so a
    -- stale index left by shortening an event cannot open a day that is gone.
    highlight_day INTEGER
);


-- =============================================================================
--  event_days — per-day date and hours for an event. day_index is 1-based.
--  A single-day event has exactly one row here.
-- =============================================================================
CREATE TABLE event_days (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   INTEGER NOT NULL,
    day_index  INTEGER NOT NULL,                 -- 1, 2, 3 ...
    day_date   TEXT,                             -- 'YYYY-MM-DD'
    day_name   TEXT,                             -- optional label, shown when 'use_day_names' is on
    start_time TEXT NOT NULL,                    -- 'HH:MM'
    end_time   TEXT NOT NULL,                    -- 'HH:MM'
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);


-- =============================================================================
--  game_tables — the gaming tables. ("table" is a reserved word, hence the
--  name.) Each table belongs to a specific DAY (a day is divided into tables).
--  table_number is the "table #N" label, numbered within its day.
-- =============================================================================
CREATE TABLE game_tables (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     INTEGER NOT NULL,               -- denormalised for easy queries
    day_id       INTEGER NOT NULL,
    table_number INTEGER NOT NULL,               -- "table #{table_number}"
    table_name   TEXT,                           -- optional label shown after the number (option-gated)
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (event_id) REFERENCES events(id)     ON DELETE CASCADE,
    FOREIGN KEY (day_id)   REFERENCES event_days(id) ON DELETE CASCADE
);


-- =============================================================================
--  games — a single game session sitting on a table.
--  explain_rules code:  0 = I explain the rules
--                       1 = quick summary only
--                       2 = players need to know the rules
--  thumbnail holds EITHER a local path under /thumbnails (predefined upload)
--  OR a remote BGG image URL — whichever applies. bgg_id is set when sourced
--  from BoardGameGeek (NULL for manual entries).
--  is_archived = the "keep archived" soft-delete state (greyed out, can be
--  brought back). A hard "delete everything" removes the row (cascades players
--  & comments).
-- =============================================================================
CREATE TABLE games (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    table_id         INTEGER NOT NULL,
    event_id         INTEGER NOT NULL,           -- denormalised
    day_id           INTEGER NOT NULL,           -- denormalised
    name             TEXT NOT NULL,
    length_minutes   INTEGER NOT NULL DEFAULT 60,
    weight           REAL NOT NULL DEFAULT 1,    -- difficulty 1..5 (float)
    max_players      INTEGER NOT NULL DEFAULT 4,
    start_time       TEXT NOT NULL,              -- 'HH:MM'
    thumbnail        TEXT,                        -- local path OR BGG image URL
    bgg_id           INTEGER,                     -- NULL if not from BGG
    language         TEXT,                        -- edition/language of the copy (e.g. 'PL'); free text
    link             TEXT,                        -- custom external URL for non-BGG games (BGG games link by bgg_id)
    manual_link      TEXT,                        -- optional rules/manual URL (PDF, video) shown as a button on the card
    brings_name      TEXT,                        -- who brings the game (shown)
    brings_email     TEXT,                        -- stored, NEVER shown publicly
    brings_user_id   INTEGER,                     -- for "games brought" stats
    explain_rules    INTEGER NOT NULL DEFAULT 0,  -- see code map above
    require_email    INTEGER NOT NULL DEFAULT 0,  -- 0/1; per-game email rule (only honoured when option require_email = 2)
    comment          TEXT,
    added_by_user_id INTEGER,                     -- NULL if added while unregistered
    is_archived      INTEGER NOT NULL DEFAULT 0,  -- soft-deleted ("keep archived")
    created_at       TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (table_id)         REFERENCES game_tables(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id)         REFERENCES events(id)      ON DELETE CASCADE,
    FOREIGN KEY (day_id)           REFERENCES event_days(id)  ON DELETE CASCADE,
    FOREIGN KEY (brings_user_id)   REFERENCES users(id)       ON DELETE SET NULL,
    FOREIGN KEY (added_by_user_id) REFERENCES users(id)       ON DELETE SET NULL
);


-- =============================================================================
--  players — signups for a game.
--  knows_rules code:  0 = I know the rules
--                     1 = I know the rules somewhat
--                     2 = I don't know the rules
--                     NULL = unspecified (e.g. a poll proposer auto-added)
--  is_reserve = on the reserve list. FIFO promotion uses created_at (ties
--  broken by id), so the earliest reserve is promoted when a seat frees up.
--  user_id links the signup to an account for "games played" stats.
-- =============================================================================
-- =============================================================================
--  mail_subscribers — the per-event mailing list.
--
--  PER EVENT ON PURPOSE: someone interested in this month's meetup shouldn't
--  start receiving mail about every future event, so a subscription is scoped to
--  the event it was made on and dies with it (ON DELETE CASCADE).
--
--  consent_text stores a SNAPSHOT of the GDPR wording the person actually
--  agreed to. Keeping only a boolean would be useless the moment an admin edits
--  the text — you could no longer say what anyone consented to. NULL means the
--  admin had configured no text, so no consent was asked for or given.
-- =============================================================================
CREATE TABLE mail_subscribers (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     INTEGER NOT NULL,
    email        TEXT NOT NULL,
    token        TEXT NOT NULL,               -- unguessable; the unsubscribe link
    consent_text TEXT,                        -- what they agreed to, verbatim
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);
-- One subscription per address per event; re-subscribing must not duplicate.
CREATE UNIQUE INDEX idx_mailsub_event_email ON mail_subscribers (event_id, email);
--  chat_messages — the shoutbox. One row per posted line.
--
--  user_id is nullable and ON DELETE SET NULL: a guest has none, and deleting
--  an account must not erase what they said. The display name is stored
--  ALONGSIDE it rather than joined at read time, so a message keeps the name it
--  was posted under even if the account is later renamed or removed — which is
--  what makes an old conversation still make sense.
--
--  is_admin is frozen at post time for the same reason: it colours the name in
--  the panel, and re-deriving it later would silently restyle history when
--  someone's role changes.
CREATE TABLE chat_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    name       TEXT NOT NULL,
    is_admin   INTEGER NOT NULL DEFAULT 0,
    message    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

--  custom_pages — admin-written pages linked from a menu in the footer.
--
--  body is stored and rendered as raw HTML: only an admin can write it, and the
--  point is to allow formatting and links. The TITLE is escaped wherever it is
--  shown, because it is used as link text and in <title>.
--
--  Ordered by sort_order then id, so the admin can arrange the footer menu and
--  new pages simply land at the end.
CREATE TABLE custom_pages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_mailsub_token ON mail_subscribers (token);


CREATE TABLE players (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id     INTEGER NOT NULL,
    name        TEXT NOT NULL,
    email       TEXT,                             -- may be NULL
    knows_rules INTEGER,                          -- see code map; NULL allowed
    is_reserve  INTEGER NOT NULL DEFAULT 0,
    user_id     INTEGER,                          -- NULL if signed up unregistered
    -- Who signed this person up, when it was somebody else — a parent entering
    -- a child, say. Stored as free text rather than derived from user_id,
    -- because the commonest case is a GUEST signing up a guest, where there is
    -- no account on either side to derive it from.
    signed_up_by TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);


-- =============================================================================
--  comments — discussion thread under a game (only used when discussions are
--  enabled). name is free text (prefilled for registered users); user_id links
--  to the account when available.
-- =============================================================================
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id    INTEGER NOT NULL,
    name       TEXT NOT NULL,
    user_id    INTEGER,
    comment    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);


-- =============================================================================
--  poll_comments — discussion under a poll, mirroring `comments` for games.
--  Deliberately a separate table rather than a nullable game_id/poll_id pair on
--  `comments`, because the self-updater is additive only (it can create tables
--  and add columns, but cannot relax an existing NOT NULL).
--  When a poll resolves into a real game these rows are copied across into
--  `comments` so the discussion survives, then cascade away with the poll.
-- =============================================================================
CREATE TABLE poll_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    name       TEXT NOT NULL,
    user_id    INTEGER,
    comment    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);


-- =============================================================================
--  polls — a poll sits on a table in place of a game. Poll-level attributes
--  (proposer, time, rules-explanation) are set once; candidate games hang off
--  it in poll_games. When an option wins it is converted into a real `games`
--  row and the poll (with its options/votes) is deleted.
--  explain_rules code: same map as games.
--  add_self = proposer ticked "add yourself as first player" => seeded one vote
--             on every option at creation.
-- =============================================================================
CREATE TABLE polls (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    table_id         INTEGER NOT NULL,
    event_id         INTEGER NOT NULL,           -- denormalised
    day_id           INTEGER NOT NULL,           -- denormalised
    proposer_name    TEXT,
    proposer_email   TEXT,
    proposer_user_id INTEGER,
    comment          TEXT,
    start_time       TEXT NOT NULL,              -- 'HH:MM'
    explain_rules    INTEGER NOT NULL DEFAULT 0,
    require_email    INTEGER NOT NULL DEFAULT 0,  -- 0/1; votes need an email (only honoured when option require_email = 2); carried into the resolved game
    allow_others_add INTEGER NOT NULL DEFAULT 0,  -- 0/1; proposer opted in to letting anyone add candidate games (they can still remove them)
    add_self         INTEGER NOT NULL DEFAULT 1,
    wait_for_deadline INTEGER NOT NULL DEFAULT 0, -- 0/1; 1 = ignore the "a candidate hit its player count" trigger and run to the deadline, so every option keeps collecting votes. Only honoured when a deadline is actually set (see poll_check_resolve), or the poll could never end.
    deadline         TEXT,                       -- 'Y-m-d H:i:s' (server time); poll auto-resolves once passed; NULL = never
    created_at       TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (table_id)         REFERENCES game_tables(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id)         REFERENCES events(id)      ON DELETE CASCADE,
    FOREIGN KEY (day_id)           REFERENCES event_days(id)  ON DELETE CASCADE,
    FOREIGN KEY (proposer_user_id) REFERENCES users(id)       ON DELETE SET NULL
);


-- =============================================================================
--  poll_games — candidate games within a poll. Mirrors the game fields the
--  add-game form collects, plus required_players (the vote threshold that ends
--  the poll). No explain_rules/brings here — those are poll-level.
-- =============================================================================
-- =============================================================================
--  library_games — one game owned by one member ("club library").
--  A member's library is a flat list; the public Library page aggregates every
--  member's rows into a single game list plus a per-member view.
--
--  bgg_id is set for BGG-sourced entries (which link by id and can be synced);
--  link holds a custom URL for games added from outside BGG. Both may be NULL —
--  a game can be a plain name with nothing to link to.
--
--  UNIQUE(user_id, bgg_id) keeps a BGG collection sync idempotent: re-running it
--  cannot duplicate a game. Manual entries have bgg_id NULL, which SQLite treats
--  as distinct for uniqueness, so a member may add two same-named manual rows —
--  their own list, their own business.
-- =============================================================================
CREATE TABLE library_games (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT NOT NULL,
    year       INTEGER,                      -- year published, NULL when unknown
    bgg_id     INTEGER,                      -- NULL for games added outside BGG
    link       TEXT,                         -- custom URL for non-BGG entries
    thumbnail  TEXT,                         -- remote BGG image URL, NULL when none
    -- 0 hides the game from the PUBLIC library while leaving it in the owner's
    -- own list: lent to a friend, left at a parent's house, in a box in the
    -- loft. Defaults to 1 so nothing a member already added disappears when an
    -- install upgrades.
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, bgg_id)
);
CREATE INDEX idx_library_user ON library_games(user_id);
CREATE INDEX idx_library_name ON library_games(name);


-- =============================================================================
--  club_library_games — games the CLUB itself owns, as opposed to games its
--  members own. Same shape as library_games minus the owner.
--
--  A SEPARATE TABLE rather than a flag on library_games, or a virtual user:
--    * library_games.user_id is NOT NULL with ON DELETE CASCADE, so hanging
--      club games off some admin's id would delete the club's shelf the day
--      that person's account is removed;
--    * making user_id nullable needs a table rebuild, and the updater is
--      strictly additive by design (it adds tables and columns, never retypes);
--    * a users row with id 0 would satisfy the foreign key but then has to be
--      excluded from the member list, the admin user manager, mailing, login
--      and every other place that counts users — a lot of places to keep in
--      step for one flag's worth of meaning.
--  A new table costs a short SQL layer and nothing else: everything above it
--  (BGG lookup, promotion, letters, pagination, rendering) is shared.
--
--  UNIQUE(bgg_id) because there is only one shelf here — no per-owner scoping —
--  so the club cannot list the same BGG game twice, and a sync stays idempotent.
-- =============================================================================
CREATE TABLE club_library_games (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    year       INTEGER,
    bgg_id     INTEGER UNIQUE,               -- NULL for games added outside BGG
    link       TEXT,
    thumbnail  TEXT,
    is_active  INTEGER NOT NULL DEFAULT 1,   -- 0 hides it from the public view
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_club_library_name ON club_library_games(name);


CREATE TABLE poll_games (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id          INTEGER NOT NULL,
    name             TEXT NOT NULL,
    length_minutes   INTEGER NOT NULL DEFAULT 60,
    weight           REAL NOT NULL DEFAULT 1,
    max_players      INTEGER NOT NULL DEFAULT 4,
    thumbnail        TEXT,
    bgg_id           INTEGER,
    language         TEXT,                        -- edition/language of the copy (mirrors games.language)
    required_players INTEGER NOT NULL DEFAULT 1, -- votes >= this => option wins
    link             TEXT,                        -- custom external URL for non-BGG candidates (mirrors games.link)
    manual_link      TEXT,                        -- mirrors games.manual_link
    created_at       TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);


-- =============================================================================
--  poll_votes — a vote for one candidate option. "cancel vote" deletes the row.
--  knows_rules carries the voter's answer (from the assign-player form) so it
--  transfers cleanly into players when the option wins. poll_id is denormalised
--  for convenient per-poll queries.
-- =============================================================================
CREATE TABLE poll_votes (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_game_id INTEGER NOT NULL,
    poll_id      INTEGER NOT NULL,               -- denormalised
    name         TEXT NOT NULL,
    email        TEXT,
    knows_rules  INTEGER,
    user_id      INTEGER,                         -- NULL if voted unregistered
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (poll_game_id) REFERENCES poll_games(id) ON DELETE CASCADE,
    FOREIGN KEY (poll_id)      REFERENCES polls(id)      ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE SET NULL
);


-- =============================================================================
--  predefined_thumbnails — admin-uploaded fallback images for unknown games.
--  Files live in /thumbnails (JPG, longest edge <= 600px); this table just
--  tracks which ones exist so the add-game picker can list them.
-- =============================================================================
CREATE TABLE predefined_thumbnails (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    filename   TEXT NOT NULL,                     -- path/name under /thumbnails
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);


-- =============================================================================
--  verification_codes — one-time 6-digit codes for the "email_code" edit/delete
--  verification method. Tied to the target item + email; checked then consumed.
--  target_type: 'game' or 'player'.
-- =============================================================================
CREATE TABLE verification_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    target_type TEXT NOT NULL,                    -- 'game' | 'player'
    target_id   INTEGER NOT NULL,
    email       TEXT NOT NULL,
    code        TEXT NOT NULL,                    -- 6 digits
    expires_at  TEXT NOT NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);


-- =============================================================================
--  logs — audit trail. One row per noteworthy action (add/sign-up/delete/...).
--  Scoped by event_id so the admin can view the current event's log or any
--  past event's. action is a short code; detail is a human-readable summary.
-- =============================================================================
CREATE TABLE logs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id      INTEGER,                        -- which event (NULL = global)
    action        TEXT NOT NULL,                  -- e.g. 'game_add', 'signup'
    detail        TEXT,                           -- readable description
    actor_name    TEXT,                           -- display/entered name if any
    actor_user_id INTEGER,                        -- account if logged in
    ip            TEXT,                            -- request IP
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (event_id)      REFERENCES events(id) ON DELETE SET NULL,
    FOREIGN KEY (actor_user_id) REFERENCES users(id)  ON DELETE SET NULL
);


-- -----------------------------------------------------------------------------
--  Helpful indexes for the hot read paths (front page render, timeline, stats).
-- -----------------------------------------------------------------------------
-- Chat reads are always ordered by id: newest N for the initial load,
-- "anything above id X" for the poll, "below id X" for load-earlier.
CREATE INDEX idx_chat_id           ON chat_messages(id);
CREATE INDEX idx_tables_day        ON game_tables(day_id);
CREATE INDEX idx_games_table       ON games(table_id);
CREATE INDEX idx_games_day         ON games(day_id);
CREATE INDEX idx_players_game      ON players(game_id);
CREATE INDEX idx_players_user      ON players(user_id);
CREATE INDEX idx_games_brings_user ON games(brings_user_id);
CREATE INDEX idx_comments_game     ON comments(game_id);
CREATE INDEX idx_polls_table       ON polls(table_id);
CREATE INDEX idx_pollgames_poll    ON poll_games(poll_id);
CREATE INDEX idx_pollvotes_pg      ON poll_votes(poll_game_id);
CREATE INDEX idx_logs_event        ON logs(event_id);

-- =============================================================================
--  NOTE: no admin account is seeded here (never ship a default password). The
--  install script / first-run flow is responsible for creating the first admin.
-- =============================================================================

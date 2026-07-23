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
INSERT INTO meta (key, value) VALUES ('schema_version', '8');


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
    ('msg_below_event',       ''),     -- optional custom text under the event name
    ('msg_adding_game',       ''),     -- optional custom text on the add-game screen
    ('msg_assigning_player',  ''),     -- optional custom text on the signup screen
    ('login_days',            '365'),  -- how long logins persist (days); 0 = browser session only
    ('poll_default_deadline_hours', '48'),  -- default: polls close this many hours BEFORE the planned start
    ('msg_adding_poll',       ''),     -- optional custom text above the add-poll form
    ('msg_voting',            ''),     -- optional custom text on the vote form
    ('msg_email_field',       ''),     -- optional note shown above every email input
    ('allow_custom_game_links', '1'),  -- 1 = non-BGG games may carry a user-supplied link
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
    ('send_emails',                  '0'),
    -- require_email: 0 = emails never required, 1 = always required,
    -- 2 = per-game: the proposer decides via a checkbox when adding a game or
    --     poll (and must then give their OWN email too).
    ('require_email',                '0'),
    -- overnight_grace_hours: times up to N hours BEFORE a day's opening hour
    -- still count as that same day (early setup); anything earlier flips to
    -- "after midnight / next morning" on overnight days. See day_rel_min().
    ('overnight_grace_hours',        '1'),
    -- allow_start_outside_hours: 1 = a game/poll may start at any time (current
    -- behaviour); 0 = the start-time input is clamped to the day's own hours
    -- (min = opening, max = closing) and the same is enforced server-side.
    ('allow_start_outside_hours',    '1'),
    -- header_button_style: how the top-bar nav links render —
    --   'text' = text only (current), 'icon' = icon only, 'both' = icon + text.
    ('header_button_style',          'text'),
    -- show_venue_name: 1 = show the venue name top-left (current); 0 = hide it,
    -- useful when the venue and event names are the same (avoids showing both).
    ('show_venue_name',              '1'),
    -- verification_method for editing/deleting unregistered-added content:
    --   'none'        = no check, anyone may proceed
    --   'registered'  = must be logged in (no code/email)
    --   'email_code'  = 6-digit code emailed, must be entered
    --   'email_match' = retype original email, case-insensitive match
    -- (Admins and the original logged-in owner always skip this. If no email
    --  was stored on the item, the action is always free regardless of method.)
    ('verification_method',          'none'),
    -- table_names_mode: who may set / edit the optional table names:
    --   'off'     = table names are not used at all
    --   'admin'   = only admins may set and edit them
    --   'add_any' = anyone may set a name when ADDING a table; only admins edit
    --   'any'     = anyone may set and edit table names
    ('table_names_mode',             'off'),
    ('allow_polls',                  '1'),
    ('allow_discussions',            '1'),
    ('use_captcha',                  '0'),
    ('allow_messaging',              '0'),
    ('allow_guest_messaging',        '0'),  -- 1 = anyone may send messages; 0 = logged-in accounts only
    ('allow_user_template',          '1'),  -- 1 = logged-in users may pick a theme (user panel)
    ('allow_guest_template',         '1'),  -- 1 = guests may pick a theme (topbar dropdown)
    ('allow_user_language',          '1'),  -- 1 = logged-in users may pick a language (user panel)
    ('allow_guest_language',         '1');  -- 1 = guests may pick a language (topbar dropdown)


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
CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    num_days     INTEGER NOT NULL DEFAULT 1,
    is_archived  INTEGER NOT NULL DEFAULT 0,     -- 0 = current, 1 = archived
    access_token TEXT NOT NULL UNIQUE,           -- unguessable; used for archive links
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    archived_at  TEXT                            -- set when archived
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
CREATE TABLE players (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id     INTEGER NOT NULL,
    name        TEXT NOT NULL,
    email       TEXT,                             -- may be NULL
    knows_rules INTEGER,                          -- see code map; NULL allowed
    is_reserve  INTEGER NOT NULL DEFAULT 0,
    user_id     INTEGER,                          -- NULL if signed up unregistered
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
    add_self         INTEGER NOT NULL DEFAULT 1,
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

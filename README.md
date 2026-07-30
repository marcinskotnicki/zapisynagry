# zapisynagry

A sign-up board for board game events. One page shows every table, every game
planned on it and who is playing — anyone can add a game, take a seat, or start a
poll to decide what gets played.

Built for board game clubs and conventions: a visitor needs no account, and an
organiser needs no spreadsheet.

*[Polska wersja tego pliku](README.pl.md) · [Technical documentation](docs/TECHNICAL.md)*

---

## What it does

**Tables and games.** An event is divided into days, a day into tables, and a
table holds a sequence of games. Each game shows its start time, length, player
count, complexity weight and who has signed up.

**Signing up.** Click a free seat, leave a name, and you are in. When a game
fills, later arrivals join a reserve list and are promoted automatically if
someone drops out.

**Polls.** Not sure what to play? Start a poll instead of a game: list a few
candidates and let people vote. The poll turns into a real game automatically —
either as soon as one candidate has enough players, or, if you choose, when the
voting deadline arrives, so every option keeps collecting votes until then.

**Discussions.** Every game and poll can carry a short comment thread.

**Messages.** Contact the person who brought a game, or everyone signed up to it,
without exposing anybody's address.

**Mailing list.** Visitors can ask to be told when new games appear at an event.

---

## For visitors

### Adding a game

Pick a table and choose **Add a game**. You can search BoardGameGeek for the
title — that fills in the thumbnail, length and weight for you — or enter
everything by hand.

You will be asked how you want to handle the rules: whether you will teach them,
give a short summary, or expect everyone to know the game already. Players see
this before they sign up.

If you leave an email address you get told when someone joins or leaves your
game. It also lets you edit or delete the game later without an account.

### Taking a seat

Click a free seat on any game. If the game is full you join the reserve list, and
you move up automatically when a seat frees up.

To cancel, use the **Resign** button next to your own name.

### Polls

A poll is a game slot that has not been decided yet. Add two or more candidate
games and let people vote for what they want to play.

The poll resolves on its own. By default that happens the moment one candidate
reaches the number of players it needs. If the person who started it ticked
**Keep voting open until the deadline**, it runs to the end instead and the
candidate with the best fill ratio wins.

The proposer can also end voting early, edit the poll, or delete it.

### Editing something you added

If you were logged in when you added it, just edit it again.

If you were not, the site asks you to confirm you are the same person — usually
by retyping the email you used, or by entering a code sent to that address. Which
one applies is up to the organiser.

### Getting notified

If the organiser has enabled it, a box under the timetable lets you leave your
address to hear about new games at that event. Every email carries an
unsubscribe link, and subscriptions are per event — signing up for one does not
sign you up for the next.

---

## For organisers

The admin panel is at `/admin.php` (or just `/admin`). Everything below lives
there.

### Setting up an event

**New event** creates one: a name, how many days it runs, the opening and closing
hours of each day, and how many tables there are.

Days may cross midnight — an 18:00 → 03:00 day is treated as one evening rather
than two, and games starting after midnight are placed correctly on the
timetable.

### Options

The **Options** tab is the main control panel. The settings worth knowing:

| Setting | What it controls |
|---|---|
| Site timezone | The clock everything runs on. Must match the venue, or polls resolve at the wrong moment. |
| Accounts | Whether visitors may register, or the site is guest-only. |
| Who may add games / sign up | Guests, or logged-in users only. |
| Require email | Never, always, or let each game's owner decide. |
| Verification method | How a guest proves they are the person who added something. |
| Captcha | Protects the public forms; needs reCAPTCHA keys. |
| BGG API code | Enables BoardGameGeek search. Without it, games are added by hand. |
| Mailing list | The signup box and the new-game notifications. |
| Theme and language | The defaults, and whether visitors may change them. |

### Sending email

The **Mailing** tab writes to one of four groups:

- everyone subscribed to the current event
- everyone who ever subscribed, to any event
- everyone who took part in this event (added a game, signed up, or voted)
- both of the event-scoped groups at once

Anyone on a mailing list gets an unsubscribe link automatically. The number
beside each option shows how many people it reaches.

### Other tabs

- **Users** — create accounts, grant admin rights, block someone.
- **Thumbnails** — stock images for games with no BGG entry, plus the site's own
  favicon.
- **Logs** — an audit trail of what was added, edited and deleted, and by whom.
- **Archive** — past events, kept read-only.
- **Update** — pulls the newest version from GitHub and applies it, including any
  database changes.

---

## Installing

**Upload `install.php` on its own and open it in a browser.** It downloads the
rest of the application from GitHub itself, so there is nothing else to copy.

The installer walks through three steps: it checks the server meets the
requirements, downloads and unpacks the app, creates the database, and asks for
your admin account. It deletes itself when it finishes.

**Requirements.** PHP 7.4 or newer (8.x recommended) with the `pdo_sqlite`,
`curl`, `zip`, `gd` and `mbstring` extensions, and a writable install directory.
Data is stored in SQLite, so there is no separate database server to set up. Any
shared host running PHP will do.

**Afterwards**, in Options:

- Set the **site timezone** straight away — game times and poll deadlines depend
  on it.
- Enter your **SMTP details** if you want email. Without them the site works
  normally but sends nothing.
- Set the **site URL** so links in emails point back to the right place.

---

## Credits

Board game data comes from [BoardGameGeek](https://boardgamegeek.com/) via their
XML API. Email is sent with [PHPMailer](https://github.com/PHPMailer/PHPMailer).

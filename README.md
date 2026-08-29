# zapisynagry

A sign-up board for board game meetups. One page shows all the tables, the games planned for them, and who is playing — anyone can add a game, take a seat, or start a poll to decide together what to play.

It was built with clubs and conventions in mind: guests don't need an account, and organizers don't need a spreadsheet.

*[Polish version of this file](README.pl.md) · [Technical documentation (EN)](docs/TECHNICAL.md)*

---

## License

The terms are in [LICENSE.md](LICENSE.md).

---

## What it can do

**Tables and games.** An event is divided into days, a day into tables, and each table can have several games. Each game shows its start time, duration, player count, weight (complexity), and the list of players.

**Sign-ups.** Click an available seat, enter your name, and you're in. Once a game is full, additional players are added to the waitlist and are automatically moved in when someone drops out.

**Polls.** Not sure what to play? Start a poll instead of a game: add a few suggestions and let people vote. The poll turns into a game automatically — either as soon as one suggestion gets enough players, or (if you choose this option) only after the deadline, so all suggestions have a chance to collect votes.

**Discussions.** Every game and poll has a place for a short discussion.

**Messages.** Message the person who brought the game or everyone signed up for it — without exposing anyone's email address.

**Mailing list.** Visitors can subscribe to notifications about new games added to a particular event.

---

## For visitors

A short user guide is available in [help/FOR-PLAYERS.md](help/FOR-PLAYERS.md).

---

## For organizers

The administration panel is available at `/admin.php` (or simply `/admin`).

A getting-started guide is available in [help/GETTING-STARTED.md](help/GETTING-STARTED.md).

---

## Installation

**Upload only the `install.php` file and open it in your browser.** The installer will download the rest of the application from GitHub, so you don't need to copy anything else.

The installer will take you through three steps: it will check whether the server meets the requirements, download and unpack the application, create the database, and ask for the administrator account details. Once the installation is complete, it removes itself.

**Requirements.** PHP 7.4 or newer (8.x recommended), with the `pdo_sqlite`, `curl`, `zip`, `gd`, `mbstring`, and `simplexml` extensions, plus a writable directory. Data is stored in SQLite, so you don't need a separate database server. Any shared hosting with PHP should be enough.

**After installation**, in Options:

- Set the **time zone** right away — game times and poll deadlines depend on it.
- Enter your **SMTP details** if you want the application to send emails. Without them, the application will work normally, but won't send anything.
- Set the **site URL** so that links in emails point to the correct place.

---

## Credits

Game data comes from [BoardGameGeek](https://boardgamegeek.com/) through their XML API. Email delivery is handled by [PHPMailer](https://github.com/PHPMailer/PHPMailer).

[info about the usage of artificial intelligence in this project](ai.md).

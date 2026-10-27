# Zapisy na gry — getting started

Welcome! Your club has its own sign-up board for board game meetups. This guide
walks you through everything worth setting up at the start.

You do not need to be technical. All of it is done through an ordinary web page,
by filling in forms. Nothing here can be broken permanently — every setting can
be changed back.

**How long will it take?** About half an hour, taken calmly. You can also do
just sections 1–4 (fifteen minutes) and come back to the rest later.

---

## Contents

1. [The two addresses you need](#1-the-two-addresses-you-need)
2. [Changing your own password and email](#2-changing-your-own-password-and-email)
3. [Adding more administrators](#3-adding-more-administrators) (and moderators)
4. [Basic club settings](#4-basic-club-settings)
5. [Appearance: logo, name, theme](#5-appearance-logo-name-theme)
6. [Fallback pictures for games](#6-fallback-pictures-for-games)
7. [Your first event](#7-your-first-event)
8. [Several events at once](#8-several-events-at-once)
9. [Game libraries](#9-game-libraries)
10. [Consent and a privacy policy](#10-consent-and-a-privacy-policy)
11. [Who may add games and sign up](#11-who-may-add-games-and-sign-up)
12. [Sending email](#12-sending-email)
13. [Chat and comments](#13-chat-and-comments)
14. [Before you send the link to your club](#14-before-you-send-the-link-to-your-club)
15. [Everyday use](#15-everyday-use)
16. [Common questions](#16-common-questions)

---

## 1. The two addresses you need

There are two. Worth saving both in your phone.

**The page for club members** — the address you were given by whoever installed
the system. It looks roughly like this:

```
https://yourclub.com
```

This is where everyone goes: they see the tables, the games and who has signed
up. This is the address you send round.

**The admin panel** — the same address with `/admin` on the end:

```
https://yourclub.com/admin
```

Only you and other administrators go here. Ordinary members will not see this
panel even if they type the address — they will simply be asked to log in.

> **Tip:** open the admin panel in a separate browser tab. Then you can change
> settings in one tab and refresh the club page in the other to see the effect
> straight away.

---

## 2. Changing your own password and email

Start here, especially if somebody else set your account up for you.

1. Log in on the club page (the **Log in** link in the top right).
2. Click your name, or the **User panel** link.
3. You will see three separate boxes: **name**, **email address** and
   **password**. Each is changed on its own — type the new value and press the
   button under that particular box.

When changing your password you will first be asked for your current one. That
is normal: it stops somebody who sits down at your unlocked computer from taking
over the account.

**Forgotten your password?** There is a reset link on the login page. It only
works once email sending has been set up (section 12). If that is not done yet,
ask another administrator to set you a new password from the **Users** tab.

---

## 3. Adding more administrators

**Do this early.** If you are the only administrator and lose access to your
account, nobody in the club can help you — recovering it then means digging
about in files on the server.

Go to the **Users** tab in the panel.

**To add somebody:** fill in the form at the top (email address, name, starting
password) and save. Then pass that person the email and password — they will
change the password themselves in their user panel, as in section 2.

**To make somebody an administrator:** find them in the list and press the
button that grants admin rights. The button then turns into "remove rights", so
the same place undoes it later.

The list also has buttons to block an account and to delete it. Clicking
somebody's **name** opens their profile, where you can change their email,
password and other details if they need help.

> The system will not let you remove rights from, or delete, the last remaining
> administrator. That is deliberate — otherwise it would be possible to lock the
> door from the inside by accident.

### Moderators — help without the whole panel

If you want somebody to keep the comments and chat tidy without giving them the
whole admin panel, make them a **moderator**. In the **Users** tab, press
**Make moderator** next to their name (the button then becomes **Remove
moderator**). You can also create a new account as a moderator straight away —
tick **Moderator account** in the form at the top.

**A moderator can:**

- **delete comments** on games and polls — anyone's, not just their own,
- **delete single chat messages**,
- **set and change table names**, if the **Table names** option says only
  administrators may.

Moderators get a **Moderation** link in the top menu. It leads to one tab,
**Messages**, listing the chat messages and comments, each with a delete
button. They can also delete a comment with the little cross right under the
game, just as you can.

**A moderator cannot:**

- **edit other people's comments** — only delete them (their own they can edit
  like anyone else),
- **clear the whole chat at once** — that stays with administrators,
- **edit or delete other people's games, sign-ups or polls**,
- **open any other tab of the panel** — options, events, users, logs and the
  rest.

Apart from that, a moderator is an ordinary user.

> The **Moderation** link only appears while the chat or comments are switched
> on — without them there is nothing to moderate. If you make a moderator an
> administrator, the moderator role goes; take the admin rights away later and
> they are left an ordinary user, not a moderator.

---

## 4. Basic club settings

Go to the **Options** tab. This is the most important tab in the panel.

Settings are grouped, and the groups fold open and shut. To begin with you want
the **Basic settings** group.

### Must set

**Venue name** — your club's name. It appears in the page header. It is really
the only thing in this group you have to type in yourself.

### Already set for you — just check they look right

**Site timezone** — already set. Every time in the system depends on it, so if
your club is in Poland, simply leave it alone.

**Site URL** — the installer detected and filled this in. The system uses it for
the links at the end of outgoing emails.

> **When do you need to come back here?** Only if the club changes its web
> address (moving to a different domain). Then **Site URL** must be corrected by
> hand, or links in emails will point at the old address.

### Worth setting, because it saves you work

In the **Defaults** group:

**Default event name** — for example "Board game night". This name will be
suggested every time you create a new event.

**Default start time** and **Default end time** — for example 17:00 and 23:00.
These are your usual opening hours.

With those three set, creating the next meetup is a few clicks rather than
filling everything in from scratch.

> **Meetups running past midnight?** If you play from, say, 18:00 to 02:00, put
> exactly those hours in. The system understands that this is one evening rather
> than two days, and places games starting after midnight correctly.

### Worth considering

**BoardGameGeek API code** — note that this one is **not in the basic
settings**: you will find it right at the bottom of the options page, in the
**Advanced** group.

If somebody else installed the system for you, it is most likely filled in
already and **you do not need to do anything**.

The simplest way to check: go to the club page, start adding a game to a table,
and see whether searching by title works. If it does, it is set — leave it
alone.

What it does: without it everything works, but games have to be typed in by
hand. With it you get a search box that fetches the cover, playing time and
complexity by itself.

> **When do you need to come back here?** Much like the site address — really
> only when moving to a different domain, since the code can be tied to a
> particular address. If game search stops working after such a move, this is
> the first place to check. If there is no code at all, you get one from the
> BoardGameGeek site — ask somebody more technical, it is a one-off job.

---

**Allow polls** — lets people post a "poll" instead of a specific game: several
suggestions for others to vote on, which the system turns into a real game once
enough people want it. Useful when you do not know in advance what you will
play.

---

## 5. Appearance: logo, name, theme

In the **Appearance** group.

**Theme** — the system comes with a dozen or so ready-made looks (light, dark,
steampunk, space and others). Pick whichever suits the club. You can also let
visitors switch the theme for themselves.

**Header: name and logo** — you decide whether the top of the page shows the
club name, the logo, or both.

**The logo is uploaded in the Pictures tab**, not here. This trips people up
regularly: in Options you choose *whether* to show a logo, and in **Pictures**
you upload *the file itself*.

The same **Pictures** tab is where you set the site icon — the small one that
shows on a browser tab.

---

## 6. Fallback pictures for games

The **Pictures** tab.

Games fetched from BoardGameGeek come with their own covers. Games typed in by
hand have none — unless you upload a set of fallback pictures here. Whoever adds
a game then picks one of them.

A handful of neutral images is plenty (dice, meeples, cards). Without them,
hand-added games simply have no picture — that is not an error, it just looks
plainer.

---

## 7. Your first event

The **Events** tab, **New event** button at the top of the list.

An event is a single meetup — or a convention lasting several days. Creating
one takes two steps:

1. On the first screen you give a **name** (the default from section 4 will be
   suggested) and the **number of days**. If you have switched on additional
   event details or an event description, the place, address, picture and
   description go here too.
2. After **Next**, you give each day a **date** and **hours** (the default
   hours are suggested). Type in the first day's date and the following days
   fill themselves in, one day after another (if your days are not
   consecutive, correct them by hand).
3. Press **Create event**.

Once saved, the event appears on the club page immediately.

**Tables are not set up in advance.** They are added on the event page with
the **Add a new table** button — as many as are needed. Anyone who may add
games can add one. To limit how many there can be, set **Max gaming tables**
in the **Basic settings** group (0 = unlimited).

### Copying an earlier event

When your meetups repeat, there is no need to type everything in again. At the
very top of the new-event screen there is a **Copy an existing event** list —
pick an earlier meetup and press **Load**. The form fills itself in with its
name, number of days, hours, day names, place, description and picture. You
only enter the new dates (and change the name, if needed).

The **Copy** button next to an event in the **Events** tab does the same.

Games, tables and sign-ups are not copied — the new event starts empty, and the
one you copied from is left untouched.

Events are changed and deleted in the **Events** tab. That is also where you add
an extra day to an existing meetup, if something runs on.

> **Do experiment.** Create a test event, add some games, sign up under made-up
> names, break whatever you like — then delete it. It is the best way to learn
> the system before anyone else sees it.

---

## 8. Several events at once

By default the system shows one event — the current one. For most clubs that is
all they need.

If you want sign-ups open for several meetups at the same time (say a weekly
game night plus a tournament next month), switch on **Public event archive** —
you will find it in the **Basic settings** group, along with the other switches
that turn features on. Tabs for switching between events then appear at the top
of the page.

> **Two different places.** The switch itself ("do we want this at all") is in
> **Basic settings**. The detailed settings for the feature have their own group
> further down, **Archive and event list**. Most features in this system work
> that way: the switch near the top, the settings in their own group.

The **Archive and event list** group has several settings worth knowing about:

**Display active event list as home page** — instead of opening one meetup
straight away, the front page shows a list of all the active ones. Good for
clubs running several events in parallel.

**Display each day separately on the home page** — on that list, each day
becomes its own entry, ordered by date. Useful when you have several multi-day
events and want the next session always at the top.

**Hide past days on the home page** — days earlier than yesterday drop off that
list. (Yesterday itself stays on purpose — a meetup that ended after midnight is
still the session people are looking for.)

**Enable additional event details** — adds a location name, address and picture
to an event. Handy when the club plays in different places: people can see
straight away where you are meeting.

**Hide the event tabs** — leaves out the event switcher bar entirely. For clubs
that would rather people navigated through the list on the front page.

---

## 9. Game libraries

The system can keep track of games. There are two separate things here and they
are easy to confuse:

**The club's own game collection** (the *Enable the club's own game collection*
option) — games the club owns, in its own cupboard. You maintain it, in the
**Club games** tab. When adding a game to a table, it can be picked from this
list instead of typed in again.

**Members' libraries** (the *Enable members' game libraries* option) — each
logged-in member can list the games they own and could bring. Everyone else can
see them, so asking somebody for a particular title is easier.

Both are switched on in Options, in the **Basic settings** group — the
*Enable the club's own game collection* and *Enable members' game libraries*
switches. The remaining settings for both libraries (how many games to show per
page, and so on) have their own group further down, **Club library** — the same
split as the event archive above.

### Adding games to a list

Games are added in three ways:

- **searching BoardGameGeek** — type a title, pick from the list, and the rest
  fills itself in (needs the API code from section 4),
- **pasting a link** to a game on BoardGameGeek,
- **by hand** — you type the title and year yourself.

There is also **syncing with a BoardGameGeek collection**: you give your username
there, and the system pulls in the whole collection at once.

> **Syncing can take a while** — sometimes ten seconds or more. BoardGameGeek
> only builds a collection when asked for it. After you press the button it will
> show a spinner and the words "Syncing…". Wait calmly and **do not press it
> again**.
>
> If you still get a message saying the collection is being prepared, wait a
> minute and try once more. The second attempt almost always works, because
> BoardGameGeek has finished building it in the meantime.

When syncing you also choose **what should happen to what is already on the
list**. The option that deletes games carries a warning and needs an extra
confirmation — read it carefully, because that one cannot be undone.

---

## 10. Consent and a privacy policy

If you collect email addresses — and you do, if you switch on sign-ups with an
email or the mailing list — it is worth having a consent box and a privacy
policy.

### The consent box

In Options, find **Consent text (GDPR)**.

The rule is simple: **an empty field means no checkbox**. If you put any text in
it, a tick box appears on the forms and nothing can be submitted without it.

In the text you may use bold (`<b>text</b>`), italics (`<i>text</i>`) and links
(`<a href="https://...">text</a>`) — a link to the privacy policy you are about
to write, for instance.

Next to it is **Remember consent between forms**. Switched on, somebody who has
already agreed to that exact text will not be asked again on every later
sign-up. If you change the consent text, everyone is asked afresh anyway.

### A privacy policy as a page

The **Pages** tab.

Here you can write any pages you like, and they appear as a menu at the bottom
of every page — privacy policy, club rules, how to get there, whatever you need.

1. Click **Add a new page**.
2. Give it a title (e.g. "Privacy policy") and the text.
3. Save.

Until you add a page, the footer menu does not appear at all.

You can then link that page from the consent text: open the finished page, copy
its address from the browser bar, and paste it into the link.

---

## 11. Who may add games and sign up

The **Accounts and permissions** group.

The important one here is **Account mode**:

- **with registration** — people can create accounts. An account lets them edit
  their own entries later, keep their own game library, and so on.
- **guests only** — nobody logs in or registers; giving a name is enough. The
  simplest option for a small club. (You, as an administrator, still log in.)

Below that you decide whether **unregistered visitors may add games** and
whether they **may sign up**. For an open club, leave both on — the fewer
obstacles, the more sign-ups.

There is also **Emails when adding games / signing up** — whether an address is
required, never needed, or left to whoever adds the game. An email is useful for
two things: notifications about sign-ups to your own game, and editing your
entry later without an account.

The **Allow users to delete their account** option adds a section to the user
panel for closing an account. That person's games and sign-ups stay on the board
under their name — closing an account does not change what already happened.

### Who may change and delete entries

When somebody adds a game or signs up without logging in, the system does not
know who they are. The **Verification for edit/delete** option (in the
**Basic settings** group) decides who may change or delete such an entry
afterwards:

- **No verification** — anyone. The simplest, and the easiest to prank.
- **Registered users only** — guests cannot change or delete anything, and
  logged-in users only their own entries. Entries added without logging in can
  then only be changed by an administrator.
- **Send code to email** (the default) — whoever wants to change an entry gets
  a code at the address given when it was added, and has to type it in.
- **Input matching email** — typing the same address that was given when the
  entry was added is enough. More convenient, but weaker: knowing somebody's
  address is all it takes.
- **Only admins can edit/delete** — nobody but administrators, not even
  logged-in people on their own entries.

Entries added by logged-in people are changed by their author with one click
and no confirmation — under every method except the last. Nobody else apart
from administrators can touch them.

Right below is **Games and sign-ups without an email**. It only matters with
the email methods (code or matching email): an entry without an address has
nothing to be verified against, so here you decide whether **everyone** may
change it (the default) or **only admins**. The second is handy at primary
school events, for instance — children often have no email, and enjoy
pranking each other by deleting one another's games. With the other methods
this setting is ignored.

---

## 12. Sending email

Without this the system works normally but **sends nothing**: no notifications
about sign-ups, and no password reset.

To switch it on you need the SMTP details for your mailbox — server, port,
username and password. You get them from whoever runs your mail or hosting.
They go in Options, in the **Email** group.

If you do not know where to get those details, this is the one moment where it
is worth asking somebody technical. The rest of this guide you can do on your
own.

Once email works, the **Mailing** tab lets you write to:

- people subscribed to notifications for the current event,
- everyone who ever subscribed,
- everyone who took part in this event (added a game, signed up, or voted),
- both of the event groups at once.

Each option shows how many people it covers. Every email automatically contains
an unsubscribe link.

---

## 13. Chat and comments

Two switches in the **Basic settings** group:

- **Allow discussions** — comments under games and polls,
- **Enable chat** — a small speech-bubble window at the edge of the screen,
  for writing to everyone who happens to be looking at the page.

The chat's details are in the **Chat** group:

**Keep messages** — whether the chat is cleared with each new event, or kept
(then the oldest messages only go once the message cap is reached). If you run
several events at once (section 8), choose **Across all events**.

**New-message marker on the chat button** — when something new arrives, the
chat button shows a red **dot** or the **number** of new messages. It goes
away once the chat is opened. Next to it you set **how many minutes** an open
page waits between checks (5 by default; 0 = only when a page loads).

**Email notifications about new chat messages** — logged-in users can tick a
box in their user panel to be emailed about new messages. The choices:

- **One email, then quiet until they look at the chat** — recommended: one
  email, and the next only once the person has actually looked at the chat,
- **An email for every message** — fine for a very quiet chat; a busy one will
  flood people's inboxes.

It needs working email (section 12). Nobody is emailed about their own message.

The **Only logged-in users can post in the chat** option (in the **Accounts
and permissions** group) lets guests read but not write.

### Keeping things tidy

In the panel, the **Messages** tab has two sub-tabs: **Chat** and
**Comments**. In the chat you delete single messages, or clear the whole chat
at once; comments can be edited and deleted. You can also delete a comment
with the little cross right under the game.

If you would rather not do it yourself, appoint a moderator (section 3).

---

## 14. Before you send the link to your club

A short checklist:

- [ ] Venue name filled in
- [ ] Timezone and site URL checked (both should already be set)
- [ ] BoardGameGeek game search works (or you have deliberately decided against it)
- [ ] Default event name and hours set
- [ ] There are at least two administrators
- [ ] Your password changed from the one you were given
- [ ] Theme chosen, logo uploaded (if you have one)
- [ ] A test event created, clicked around, and deleted
- [ ] Verification for edit/delete chosen deliberately (section 11)
- [ ] Consent text written, if you collect email addresses
- [ ] Privacy policy page added, if you need one

**Check how it looks to an ordinary visitor.** Open the club page in a private
browser window (Ctrl+Shift+N in Chrome, Ctrl+Shift+P in Firefox). You will see
exactly what somebody without an account sees — no admin buttons.

Check how it looks on a phone, too. That is where most people will open it.

---

## 15. Everyday use

Once everything is set up, your job is usually:

**Before a meetup** — create the event (the *Events* tab, *New event* button)
and send the link round. Quickest of all: copy the previous meetup (section 7)
and enter only the new dates.

**During** — usually nothing. People add games and sign up themselves. If
somebody needs help, you can correct or remove any game or sign-up.

**Afterwards** — nothing you have to do. Events can be archived in the *Events*
tab, but they can equally be left as they are.

**Updates** — look in at the **Update** tab now and then. It will tell you
whether a newer version exists and install it in one click. Best done calmly,
not ten minutes before a meetup.

The **Logs** tab holds a record of who added, changed and deleted what. Useful
when you need to work out what happened to somebody's game.

---

## 16. Common questions

**I changed a setting and nothing happened.**
Refresh the club page (F5). If you are looking at it in a second tab, it will
not reload by itself.

**Somebody signed up and now cannot cancel.**
Cancelling works for the person who signed up — as far as the chosen
verification allows (section 11). If they signed up as a guest from a different
phone, or got their name wrong, just remove the entry yourself: while you are
logged in as an administrator, there is a delete button beside every name on
the event page.

**People keep deleting each other's games as a joke.**
Change **Verification for edit/delete** (section 11) — for example to
**Registered users only** or **Only admins can edit/delete**. With the email
methods, also set **Games and sign-ups without an email** to **Only admins**.

**A game has disappeared / somebody deleted it.**
Look in the **Logs** tab. It will show who deleted it and when.

**I want to change the hours of an event that has already started.**
The **Events** tab, pick the event, change the day's hours. Games already added
stay where they are.

**Somebody posted an unpleasant comment.**
Any comment can be deleted from the panel, exactly like a game or a sign-up.
If you would rather not police it yourself, appoint a moderator (section 3) —
they can delete comments and chat messages too.

**I have forgotten my password and there is no second administrator.**
This is why section 3 comes so early in this guide. If it has already happened,
you will need help from somebody with access to the server.

**The club has changed its web address — what do I need to fix?**
Two things in Options: **Site URL** (otherwise links in emails will point at the
old place) and the **BoardGameGeek API code**, if game search stops working
after the move. Nothing else — the remaining settings do not depend on the
address.

**Can I rearrange all of this later?**
Yes. Every setting in Options can be changed at any time, and the change takes
effect immediately. Nothing here is set in stone.

---

## Need help?

If something is unclear, or the system behaves oddly, ask whoever installed it
for you. Describe it as precisely as you can: what you clicked, what you
expected, and what happened instead. That is usually enough to find the cause
quickly.

Enjoy your games!

# Testing Guide for Contributors

Thanks for contributing to the DCW Certificate Portal. Every change here eventually
touches a real participant's certificate, so we ask contributors to actually run their
work before opening a pull request rather than relying on the code looking right.

This guide covers how to test locally and what's worth checking in each part of the
portal.

---

## 1. Local testing (preferred)

Wherever possible, test your changes on your own machine first — especially interface
changes and anything touching PDF generation.

[CONTRIBUTING.md](CONTRIBUTING.md) has the full setup: `composer install`, copy
`config.example.php` and `.env.example`, import `database.sql`, then
`php -S localhost:8000`. It takes about ten minutes.

**There are no admin accounts on the live portal for contributors, and we don't issue
them.** Please don't ask for a login to test a patch — you don't need one, and no
contribution will be held up for lack of it. Equally, please don't create events,
participants, or admin users on the live site, and don't point scanners, load tests, or
brute-force tools at it. If you've found a security weakness,
[SECURITY.md](SECURITY.md) explains how to report it privately.

---

## 2. Checking your work against the dummy event

Once a change is merged, you can see how it behaves on the live portal without going
anywhere near real participant data.

Visit **certificates.dcwwiki.org** and select **DCW Dummy Testing Event (ID 14)**. The
entries below are fictional participant records we maintain there specifically so that
contributions can be checked against a live certificate, with testing templates assigned
across the roles.

| Name | Email | Assigned Role |
| :--- | :--- | :--- |
| Hazrat Daktar Farhan | drfarhan@dcwwiki.org | Organizer |
| Shaikh Muhammad Amy | shkamy@dcwwiki.org | Organizer |
| Ali | ali@dcwwiki.org | Organizer |
| Abid | abid@dcwwiki.org | Assistant |
| Christopher Due | christdue@dcwwiki.org | Assistant |
| Mr. Aristotle aka Arastu | arastu@dcwwiki.org | Assistant |
| Suqraat urf Buqraat | suqraturfbuqrat@dcwwiki.org | Fellow |
| Maulana John | maulanajohn@dcwwiki.org | Fellow |
| Farhan | farhan@dcwwiki.org | Fellow |
| John Due | johndue@dcwwiki.org | Participant |
| Tabassum Farhat Shaikheee | tabassum@dcwwiki.org | Participant |
| Mr. Christopher Bartholomew Alexander III | chrstbarth@dcwwiki.org | Participant |

These records are deliberately varied — very long names, single-word names, and titles —
because that's where certificate layout bugs show up.

Leave this event and these entries as they are. Don't rename, delete, or re-import them,
and don't add new ones. They're shared, and quietly editing them breaks the reference
point for everyone else.

---

## 3. Test data for local runs

The `tests/` directory has sample participant CSVs covering different roles:

| File | Use it for |
| :--- | :--- |
| `test_participants_attendees.csv` | Ordinary bulk import |
| `test_participants_organizers.csv` | Role-specific templates |
| `test_participants_speakers.csv` | Role-specific templates |

Please use these rather than real participant data. If you need to add a fixture, make
the names and email addresses obviously fake — `example.com` addresses are ideal — and
never copy rows out of the live database.

Names are where certificate bugs hide, so it's worth throwing awkward ones at your
build: very long names that might overflow the template, names with apostrophes or
hyphens, single-word names, and names in Arabic, Urdu, or Devanagari script to check
the PDF font handles them.

---

## 4. What to check, by area

Test the part you changed properly, and give the neighbouring parts a quick click
through. You don't need to work through everything below every time.

### Admin panel and login

- Logging in with the right password works; the wrong password is rejected.
- Pages under `admin/` bounce you to the login screen when you're logged out.
- Any form you added still saves correctly after a page refresh.

### CSV import

- A clean file imports the expected number of rows.
- A malformed file fails with a readable message instead of a blank page.
- Duplicate rows and empty columns don't create broken participant records.

### PDF template editor

- Fields you drag onto the canvas stay where you put them after saving and reloading.
- The QR code lands in the right place and keeps its colour.
- Replacing the template PDF doesn't scramble the existing field positions.

### Certificate generation and download

- Generated PDFs open without errors and show the right name, credential ID, and date.
- Text sits inside the template rather than running off the edge.
- Role-specific templates are applied to the right roles.

### Public verification

- Scanning or opening a certificate's QR link shows the correct participant and event.
- An invalid or made-up credential ID is reported as not found, and doesn't leak
  anything about other certificates.

### Email

- Only test this if your local `.env` has SMTP settings you're entitled to use. Point it
  at your own mailbox or a service like Mailtrap.
- Never point a local build at the project's production mail account.

---

## 5. Before you open the pull request

Run a syntax check on everything you touched:

```bash
php -l path/to/file.php
```

CI runs the same check across the whole repository, so a parse error will fail the
build. Then write down in the pull request what you actually did to test it. Being
specific — which CSV, how many rows, what you saw — is genuinely more useful to a
reviewer than a checklist of ticks, and saying "I couldn't test the email flow" is
better than leaving a reviewer to assume you did.

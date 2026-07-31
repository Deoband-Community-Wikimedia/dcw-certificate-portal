<!--
Thanks for sending a patch. Fill in what's relevant and delete what isn't —
this template is a memory aid, not a form to be completed under duress.

Found a SECURITY problem? Please close this and report it privately instead:
see SECURITY.md.
-->

## What does this change?

<!-- A couple of sentences in plain language. What was wrong or missing, and what
     does the portal do differently now? -->

Closes #

## How did you test it?

<!-- Be specific. "Imported tests/test_participants_attendees.csv into a new event,
     generated 12 certificates, scanned one QR code and the verification page showed
     the right name" tells a reviewer far more than "tested locally".

     Say if you couldn't test something and why — that's genuinely useful, and much
     better than leaving a reviewer to assume it was covered. -->

- [ ] Ran `php -l` on every file I changed
- [ ] Clicked through the affected pages in a browser
- [ ] Checked it on a small screen, if this touches the interface

## Screenshots

<!-- Close to mandatory for any visual change: before and after if you can.
     Blur or fake any real participant names and email addresses. -->

## Does this change the database?

- [ ] No, `database.sql` is untouched
- [ ] Yes — and I've included the `ALTER TABLE` statements that existing installs need

<!-- If yes, paste the exact statements a maintainer has to run here. Editing
     database.sql alone only helps brand new installs; the live portal already has its
     tables and will never re-run that file, so without a migration this merge takes
     the admin panel down. -->

```sql

```

## Security checklist

<!-- Tick what applies to the code in this PR. If a line genuinely doesn't apply,
     say so rather than ticking it. -->

- [ ] All new database queries use prepared statements with bound parameters
- [ ] Every new `POST` form has a CSRF token, and its handler verifies it
- [ ] Everything echoed to the page goes through `htmlspecialchars()`
- [ ] Any change to logins, permissions, or certificates is written to the audit log
- [ ] No credentials, API keys, real participant data, or `.env` / `config.php`
      contents are included anywhere in this diff

## Anything reviewers should know

<!-- Trade-offs you made, parts you're unsure about, things you deliberately left for
     a follow-up. Flagging your own doubts speeds up review, it doesn't count
     against you. -->

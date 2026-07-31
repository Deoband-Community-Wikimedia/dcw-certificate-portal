# Security Policy

The DCW Certificate Portal issues and verifies credentials that people put on their
CVs and LinkedIn profiles. If the portal can be tricked into issuing a certificate
that was never earned, or into letting someone into the admin panel, that damages
real people's trust in the credential. We take reports about that seriously and we'd
much rather hear from you privately than read about it in a public issue.

## Reporting a vulnerability

**Please do not open a public issue, discussion, or pull request for a security
problem.** A public report tells everyone about the weakness at the same moment it
tells us, and this portal runs live.

Use GitHub's private vulnerability reporting instead:

1. Go to the **Security** tab of this repository.
2. Choose **Report a vulnerability**.
3. Fill in what you found.

That opens a private thread visible only to you and the maintainers. If you can't use
that form for any reason, email the maintainers directly at the address listed on the
[Deoband Community Wikimedia](https://dcwwiki.org/) site and put "security" in the
subject line.

### What to include

You don't need a formal write-up. What genuinely helps us reproduce and fix things:

- What you were able to do that you shouldn't have been able to do.
- The steps to get there, in order. A short list beats a long essay.
- Which part of the portal it affects: the admin panel, the public verification page,
  the PDF editor, the certificate download, or the email flow.
- Whether it needs an existing admin account, or works for an anonymous visitor.
  This makes a big difference to how urgently we treat it.

Screenshots or a short screen recording are welcome. Proof-of-concept code is welcome
but never required.

### Please test locally, not against the live portal

Set the project up on your own machine using the steps in
[CONTRIBUTING.md](CONTRIBUTING.md). Please don't run scanners, brute-force tools, or
exploit attempts against the live instance. It's a small volunteer-run service, that
kind of traffic knocks it over for actual participants trying to collect their
certificates, and it makes it much harder for us to tell a friendly researcher apart
from an attacker.

Don't access, modify, or download data belonging to real participants. If you stumble
into someone's personal data while testing, stop, and tell us what you saw so we can
assess the exposure. We won't hold that against you.

## What happens next

We're a small group of volunteers, not a company with an on-call rotation, so please
read these as honest intentions rather than a contractual SLA:

- We'll acknowledge your report within about **3 days**.
- We'll tell you whether we've confirmed it, and roughly how serious we think it is,
  within about **10 days**.
- Serious issues that let someone reach the admin panel or forge a certificate get
  patched as fast as we can manage, usually days rather than weeks.
- Lower-severity issues get folded into normal development.

We'll keep you in the loop as we work on it, and we'll credit you in the release notes
or the advisory unless you'd rather stay anonymous. Just tell us which you prefer.

We don't run a paid bug bounty. We can't offer money, swag, or a job. What we can offer
is a fast, respectful response and public credit.

## What's in scope

Anything in this repository, and the behaviour of a default installation set up by
following the README. Things we especially want to hear about:

- Getting into the admin panel without valid credentials, or as a different admin.
- Making the public verification page confirm a certificate that was never issued,
  or that belongs to someone else.
- Reading or changing participant data (names, email addresses) without authorisation.
- Injection of any kind: SQL, command, template, or script that runs in an admin's
  browser.
- Getting the server to write, read, or execute a file it shouldn't.
- Anything that leaks the contents of `config.php`, `.env`, or the `uploads/` directory
  over HTTP.

## What's out of scope

To save you time, these are things we already know about or have decided not to treat
as vulnerabilities:

- **The default administrator account.** A fresh install ships with a documented
  default username and password so that a new operator can log in the first time. The
  README tells operators to change it immediately. Reporting that the documented
  default password works on an unconfigured install isn't a finding. An actual live
  deployment still running the default password *is* worth telling us about, privately.
- **Missing hardening headers, cookie flags, or TLS configuration on a live host.**
  Those are deployment settings rather than repository code. Tell us anyway if you spot
  something badly wrong, but we'll usually fix it in server config rather than in code.
- **Findings from an automated scanner with no demonstrated impact.** A tool saying
  "possible XSS" without a working example is very hard for us to action.
- **Denial of service through sheer volume of traffic**, or anything that relies on
  flooding the server.
- **Vulnerabilities in third-party dependencies that are already publicly known and
  have a fix upstream.** Please just open a normal pull request bumping the version.
- **Social engineering of the maintainers or DCW volunteers.**

## Supported versions

This project doesn't publish tagged releases. There is one supported version: the
current `main` branch, which is what the live portal runs. We fix security problems by
committing to `main`; we don't backport to older commits, and we'd encourage anyone
self-hosting the portal to track `main` rather than pinning to an old checkout.

## For people running their own copy

If you've deployed this portal for your own organisation, a few things matter more than
anything in the code:

- Change the default administrator password before the site is reachable from the
  internet.
- Keep `config.php` and `.env` out of version control. They're in `.gitignore` for a
  reason, and they hold your database and mail credentials.
- Make sure your web server actually blocks HTTP access to `config.php`, `.env`, and
  `.git/`. The bundled `.htaccess` handles this on Apache; on Nginx you have to write
  the equivalent `deny all;` rules yourself.
- Serve the portal over HTTPS. Certificate links, admin logins, and password reset
  emails all assume a trustworthy connection.

Thank you for helping keep this thing safe for the participants who rely on it.

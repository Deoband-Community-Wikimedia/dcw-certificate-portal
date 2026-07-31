# Contributing

This portal is built and maintained by volunteers from the Deoband Community Wikimedia
user group. It's a small project with a real audience: the certificates it generates go
to people who attended our events, and the verification page is what an employer sees
when they check whether a credential is genuine. That shapes how we review changes, so
it's worth knowing before you start.

Contributions of every size are welcome, including fixing a typo in this file.

## Before you write code

If there's an open issue for what you want to do, leave a comment saying you're picking
it up. That saves two people writing the same patch. If there isn't an issue, open one
first for anything beyond a small fix. It's much less painful to disagree about an
approach in an issue than in a finished pull request.

Found a security problem? Don't open an issue. See [SECURITY.md](SECURITY.md).

## Setting up locally

You need PHP 8.0 or newer with the `pdo_mysql`, `gd`, and `mbstring` extensions, plus
MySQL or MariaDB, and Composer. XAMPP, MAMP, or Laragon all work fine, as does a plain
`php -S` server.

```bash
git clone https://github.com/Deoband-Community-Wikimedia/dcw-certificate-portal.git
cd dcw-certificate-portal
composer install
```

Create an empty database and import the schema:

```bash
mysql -u root -p your_database_name < database.sql
```

Copy the two example config files. **Neither of the copies is tracked by git, and
neither should ever be committed:**

```bash
cp config.example.php config.php
cp .env.example .env
```

Open `config.php` and point `$host`, `$db`, `$user`, and `$pass` at the database you
just created. Open `.env` and fill in your own values. If you're not working on the
email flow you can leave the SMTP settings empty; the rest of the portal runs fine
without them, you just won't be able to send mail.

Give the app somewhere to write PDFs:

```bash
chmod -R 755 uploads/
```

Then start it up:

```bash
php -S localhost:8000
```

The public site is at `http://localhost:8000/` and the admin panel at
`http://localhost:8000/admin/login.php`. Log in with the default account documented in
the README, and change the password from **Manage Users** straight away so you're not
in the habit of leaving it as-is.

The `tests/` directory has sample participant CSVs you can use to exercise the bulk
import without inventing data. [TESTING.md](TESTING.md) covers what's worth checking in
each part of the portal.

Test locally. There are no admin accounts on the live portal for contributors and we
don't issue them, so please don't ask for a login — you don't need one to get a
contribution merged. Once your change is merged you can check it against the shared
dummy event described in TESTING.md, which exists so nobody has to touch real
participant data.

## How the codebase is put together

There's no framework and no build step, which is deliberate. This has to run on cheap
shared hosting, so it's plain PHP, plain CSS, and plain JavaScript, and a `.php` file
you edit is the file the server runs.

- Public-facing pages sit in the repository root: `index.php`, `verify.php`,
  `download.php`, `success.php`.
- Everything behind a login lives in `admin/`.
- Shared functions live in `helpers.php`. Database connection, CSRF helpers, and the
  audit log helper live in `config.php` (created from `config.example.php`).
- `database.sql` is the schema.
- `vendor/` is Composer's and isn't tracked. Don't commit it.

Because `config.php` is generated from `config.example.php` on every install, a change
to a shared helper in there means editing `config.example.php` in your PR **and** every
operator having to hand-apply the same edit to their live `config.php`. It's worth
avoiding when you can put the code in `helpers.php` instead.

## Things reviewers will always check

These aren't style preferences, they're the properties that keep the portal
trustworthy. A pull request that misses one of them will get change requests even if
the feature itself is good.

**Every database query uses a prepared statement.** `$pdo->prepare()` with bound
parameters, always. Never build SQL by concatenating a variable into the string, not
even for something that "can only ever be a number".

**Every state-changing form carries a CSRF token.** Any `POST` that writes to the
database needs `generate_csrf_token()` in a hidden field and `verify_csrf_token()` at
the top of the handler. Follow the pattern in the existing admin pages.

**Everything that reaches the page is escaped.** Run user-supplied and
database-supplied values through `htmlspecialchars()` before echoing them, including
values you're confident about. Confidence is how XSS gets in.

**Passwords go through `password_hash()` and `password_verify()`.** Never store, log,
or email a password in plain text.

**Anything that changes access, credentials, or certificates gets written to the audit
log.** Use `log_audit_action()`. Note that it deliberately records nothing when there's
no logged-in admin in the session, so if you're adding something that happens *before*
login, check that your logging actually fires rather than assuming it does.

**Nothing secret ends up in the repository.** No database passwords, SMTP credentials,
API keys, participant names, or real email addresses in code, comments, test fixtures,
or screenshots. Blur anything sensitive in screenshots.

## If you change `database.sql`

This one causes the most trouble, so it gets its own section.

Editing `database.sql` only helps people setting up a *brand new* install. Every
existing deployment, including the live portal, already has its tables and will never
re-run that file. If your code starts selecting a column that only exists in your
updated schema, every existing install breaks the moment your PR is merged, usually
with a blank white page.

So when you touch the schema:

1. Add the change to `database.sql` as usual, for new installs.
2. Also write the `ALTER TABLE` statements that bring an *existing* database up to
   date, and put them in the schema file where they're easy to find.
3. Say so explicitly in your pull request description, and paste the exact statements
   a maintainer needs to run.
4. Where it's reasonable, write the PHP so it degrades gracefully if the migration
   hasn't been run yet, rather than throwing a fatal error.

## Branches and commits

Branch off `main` and name the branch after what it does. If it's tied to an issue,
lead with the issue number, which makes it obvious later what a branch was for:

```
issue-42-fix-qr-alignment
feature/bulk-role-assignment
fix/pdf-font-fallback
```

We loosely follow Conventional Commits, matching the existing history:

```
feat: add role filter to the participants table
fix(editor): keep QR position when the template is replaced
docs: explain the migration step for schema changes
```

Write the subject line in the imperative and keep it under about 72 characters. If the
change needs explaining, put that in the body rather than stretching the subject. One
logical change per commit where you can manage it; nobody will reject a PR over commit
granularity, but a reviewable history helps when something needs tracing back later.

## Opening the pull request

Run a syntax check over anything you touched before you push:

```bash
php -l path/to/file.php
```

CI runs the same check across the whole repository on every pull request, so a parse
error will fail the build. Actually running the affected page in a browser catches far
more than linting does, so please do that too.

Fill in the pull request template honestly. The "how did you test this" section is the
part reviewers lean on hardest, and "it should work" is not a test. Screenshots or a
short recording are close to mandatory for anything that changes the interface, since
the reviewer otherwise has to set up your branch to see what you did.

Link the issue your PR closes with `Closes #123`.

Small, focused pull requests get reviewed quickly. A large one that fixes a bug,
refactors a helper, and restyles a page at the same time is genuinely hard to review
and will sit for longer.

## Review and merge

Every pull request needs an approving review from a maintainer, and CI has to be green.
We'll usually get to it within a few days, though this is volunteer time around jobs and
studies, so quiet weeks happen. A ping on the PR after a week is completely fair.

Expect questions and change requests, including on good work. Review comments are about
the code and the people who depend on it, never about you. If you think a reviewer has
got something wrong, say so and explain why. That's a normal part of the process, and
maintainers get things wrong too.

Once merged, a maintainer takes care of getting the change onto the live portal. You
don't need to do anything for that, and contributors don't need access to any of the
production infrastructure to have their work shipped.

## A note on conduct

Be decent to each other. Assume the person you're replying to is acting in good faith,
especially across a language barrier. See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

Thanks for taking the time. This project exists because volunteers kept chipping at it.

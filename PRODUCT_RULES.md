# Product Rules

The rules the portal must always follow. Most of them live only in the code, so they are
written down here for contributors and reviewers. If your pull request changes one of
these, say so in the PR description and update this file in the same PR.

## Organizations and admins

One install can host many organizations. The first one (ID 1, Deoband Community Wikimedia
by default) is the root portal at `/`. Every other organization gets a community hub at
`/c/<slug>`, with its own events at `/c/<slug>/e/<event-slug>`.

There are two admin roles:

| | Super admin | Organization admin |
|---|---|---|
| Sees | Every organization | Only their own organization |
| Events, participants, email log, audit log | All organizations | Their own organization only |
| Creates admins | Super admins, or org admins for any active organization | Org admins for their own organization only |
| Adds, activates or deactivates organizations | Yes | No |

- Only a super admin adds an organization. Its first admin is invited by email, and the
  invitation link works for 48 hours.
- An organization admin must belong to an active organization, or they cannot log in.
- Deactivating an organization blocks its admins from logging in, takes its hub offline
  (it shows "not found"), and stops its event links from accepting claims.
- The default `admin` account in `database.sql` is a super admin.
- An organization admin edits their own hub profile (cover banner, bio, Meta-Wiki,
  Telegram and X links) from the **"Organization"** tab. Banners must be JPEG, PNG or WebP
  (no SVG), and links must be `http` or `https`.

## Participants

- A participant belongs to one organization, and an email address is unique within it.
  Two organizations keep separate records of the same person.
- Adding or importing an email address the organization already has reuses that
  participant and **replaces their name** with the new one.
- Editing a participant's name or email changes it on **every** certificate that
  organization has issued them, not only the current event's.

## Certificates

- A certificate ID is the event's certificate prefix followed by eight characters, without
  `I`, `O`, `0` or `1`. It is printed on the certificate, linked from its QR code, and never
  changes.
- A revoked certificate never passes as valid: it cannot be claimed, downloaded or emailed,
  and the verification page shows the revocation date and reason instead.
- Deleting an event, deleting a participant, and opening the audit log as a super admin all
  need the Super Admin Passcode (`SUPER_ADMIN_PASSCODE` in `config.php`).

## Claiming a certificate

- A participant claims a certificate by proving they own its email address with a 6-digit
  code sent to that address.
- A code works for 10 minutes and 5 wrong tries. Asking for a new code cancels the old one,
  and a new code can be requested only once a minute.
- Code requests are limited to 5 per IP address and 3 per email address in any 10 minutes.
- A correct code lets that browser download the certificate for 1 hour. After that, the
  participant claims it again.
- The certificate itself is emailed at most once every 10 minutes.

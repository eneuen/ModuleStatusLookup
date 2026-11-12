# Security Policy

## Supported versions

Only the latest release of Status Lookup receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report it privately through GitHub's "Report a vulnerability" button on this repository's Security tab. Include a description, steps to reproduce, the impact, and a suggested fix if you have one.

We aim to acknowledge reports within 48 hours.

## What the module exposes

Status Lookup reads information out to anyone who can dial the business, so it is built to tell callers as little as possible and to fall back to a person:

- **On the phone,** callers hear only a status (and, if the status says so, the ID read back or a date), never names or notes. A status can require the record's PIN or a call from the record's own phone number. Unknown IDs, wrong PINs and calls from other numbers all sound the same ("not found") and count as misses; once a caller number, or all callers together, has too many misses in an hour, the next ID goes straight to the operator, and an ID with 10 wrong PINs in a day stops being asked for its PIN. Outside callers can only reach the lookup through a route or menu option an administrator pointed at its number; staff can dial it and transfer callers to it.
- **The admin page** is for administrators only; every write is an AJAX POST that must carry the session's Bearer token.
- **The REST API** (`/pbxcore/api/v3/module-status-lookup/`) uses the PBX's own authentication: API keys scoped per path (replacing all records is a path of its own), administrators' logins, and, as everywhere in the MikoPBX REST API, **requests from the PBX itself (localhost) without any key**. Anything running on the PBX can therefore read and change records; don't run untrusted software there.
- **PINs** are stored only as HMAC-SHA256 hashes under a random key in the module's `db/pin.key` (owner-only). Anyone who can read both the database and the key can test PINs offline, as with any short PIN; protect backups of the module's folder accordingly.
- **Logs:** the call log keeps callers' numbers and the IDs they typed for the PBX's call-history period (or the module's own setting) and offers "forget" for one ID or number. Note that MikoPBX's Asterisk log records every key pressed on any call, IDs and PINs included; that is a PBX-wide setting outside the module.

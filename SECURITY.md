# Security Policy

## Supported versions

Only the latest release of Status Lookup receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report it privately through GitHub's "Report a vulnerability" button on this repository's Security tab. Include a description, steps to reproduce, the impact, and a suggested fix if you have one.

We aim to acknowledge reports within 48 hours.

## What the module exposes

Status Lookup reads information out to anyone who can dial the business, so it is built to tell callers as little as possible and to fall back to a person:

- **On the phone,** callers hear only a status (and, if the status says so, the ID read back or a date), never names or notes. A status can require the record's PIN, or a call from the record's own phone number; caller ID can be forged by whoever controls the calling line, so use a PIN for anything sensitive.
- **Guessing:** unknown IDs, wrong PINs and calls from other numbers sound the same ("not found") and count as misses; once a caller number, or all callers together, has too many misses in an hour, the next ID goes straight to the operator. Each wrong PIN is counted against its ID the moment it is typed, and an ID with 10 wrong PINs in a day is no longer asked for its PIN (callers are put through instead). The number of callers in the lookup at once is limited (10 by default), which bounds what many parallel calls can try. Known limits: IDs that are found are not limited, so statuses without a PIN are only as private as the IDs are hard to guess; being asked for a PIN, or put through for a record without a PIN or phone, shows that an ID exists; and someone who keeps missing on purpose can send every caller to the operator for a while.
- **Reachability:** outside callers reach the lookup only through a route or menu option an administrator pointed at its number; staff can dial it and transfer callers to it. Destinations are checked against the PBX's own internal destinations, so the line can't send a caller to an outside number.
- **The admin page** is for administrators only; every write is an AJAX POST that must carry a valid token of an administrator's session, which a page on another site can neither read nor send.
- **The REST API** (`/pbxcore/api/v3/module-status-lookup/`) uses the PBX's own authentication: API keys scoped per path, administrators' logins, and, as everywhere in the MikoPBX REST API, **requests from the PBX itself (localhost) without any key**. Anything running on the PBX can therefore read and change records; don't run untrusted software there. Replacing all records is a path of its own and refuses to delete most records unless asked to explicitly; a key that may write records can still delete them one by one, and each deletion is noted in the PBX's system log with the key's description. A key that may read records sees their notes and phone numbers.
- **PINs** are stored only as HMAC-SHA256 hashes under a random key in the module's `db/pin.key` (owner-only). Anyone who can read both the database and the key can test PINs offline, as with any short PIN; protect backups of the module's folder accordingly.
- **The PBX's own user:** MikoPBX gives module folders to the web server's user (www), and root runs code from them. So anyone who can run code as that user can read the key and the database and can act as root; the module only keeps its own root steps (creating and reading the PIN key) from being turned against the system.
- **Logs:** the call log keeps callers' numbers and the IDs they typed for the PBX's call-history period (or the module's own setting), and "forget" deletes one ID's or one number's data, overwriting it in the database file. MikoPBX's Asterisk log records every key pressed on any call, IDs and PINs included, and the module's lines there name each lookup's ID and status; that log belongs to the PBX. Uninstalling with kept settings leaves the module's data, call log included, in the PBX's module backup folder until the next install.

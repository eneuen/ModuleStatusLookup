# Status Lookup for MikoPBX

**Module ID:** `ModuleStatusLookup`

A MikoPBX extension module that answers "Is it ready yet?" calls without a person. The caller types their ticket, order or prescription number on the keypad and hears its status from a recording. Statuses live in the module's own database on the PBX: loaded from a CSV file, edited by hand, or pushed by shop software over the REST API. No cloud service, CRM or internet connection is involved, and pressing 0 always reaches a person.

> **Status: in development.** Not ready for use yet. What has changed so far is in [CHANGELOG.md](CHANGELOG.md).

## Planned features

- **A lookup line** with its own internal number (2300 by default). Choose it as the destination of an incoming route or an IVR menu option, like a queue; staff can also dial it or transfer callers to it.
- **One short exchange:** the caller enters an ID and hears its status, optionally followed by the ID read back or a date. Three tries at most; 0 reaches a person at any prompt, and every dead end (not found, too many tries, a status that needs a conversation) has its own destination.
- **Templates** for IT and device repair, auto workshops, pharmacies, and retail and e-commerce, each with its statuses, ID length and privacy defaults, plus a blank one.
- **Records** from a CSV import (with a dry-run preview), single-record editing, or the REST API. Records can expire, so stale statuses are never read out.
- **Privacy:** only the status is spoken, never names or notes; optional PIN or caller-ID check per status; a limit on missed lookups per caller and for the whole PBX against guessing.
- **Activity:** how many calls the line answered, how many reached a person, and where callers got stuck.
- **Built-in prompts** in English and Russian, so a new install speaks before anyone records anything.

## REST API

Shop, POS or ticketing software can keep the records up to date through the PBX's REST API (v3). The base path is `/pbxcore/api/v3/module-status-lookup/`.

| Method | Path | What it does |
| --- | --- | --- |
| GET | `records?search=&status=&expired=1&page=` | Lists records, newest change first, 50 a page. `search` is the start of an ID, or text in the note. |
| GET | `records/{lookup_id}` | One record; 404 when there is none. |
| PUT | `records/{lookup_id}` | Creates the record (201) or changes it (200). Fields left out keep their value; an empty string clears one. |
| DELETE | `records/{lookup_id}` | Deletes one record; 404 when there is none. |
| POST | `records:import` | Imports a CSV file (add and update): `{"csv": "...", "dryRun": true}`. With `dryRun`, nothing changes and the answer says what would. |
| POST | `records-replace:import` | The same, but the file becomes the full list: records not in it are deleted. |
| GET | `statuses` | The status codes records can have. |
| GET | `stats?from=YYYY-MM-DD&to=YYYY-MM-DD` | The Activity tab's totals for up to a year of PBX days. |

A record ID is the digits callers type, and leading zeros count. The fields PUT accepts are `status_code` (needed for a new record), `note` (for staff, never spoken), `status_date` and `expires_on` (YYYY-MM-DD), `phone`, `pin` (4 to 8 digits; only ever written, never shown) and `remove_pin` (true). Anything else is refused, so a misspelt field never goes unnoticed. The CSV format is the Import tab's; the CSV travels inside the JSON body, which the PBX limits to 4 MB.

```bash
curl -X PUT "https://pbx.example.com/pbxcore/api/v3/module-status-lookup/records/10445" \
  -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
  -d '{"status_code": "READY", "expires_on": "2026-12-31"}'
```

**Access.** Create an API key on the PBX's API keys page and allow it the paths it needs, for example `/api/v3/module-status-lookup/records` with write access. Replacing all records is a path of its own (`/api/v3/module-status-lookup/records-replace`), so a key that updates records can't delete them all unless it is given that too. A key without the right access is refused with 401. Administrators' logins work as well, and, as everywhere in the PBX's REST API, scripts running on the PBX itself (localhost) need no key. A record changed through the API shows "API" and the key's description as who changed it.

A refusal answers 400 with an error code in `data.error` and its details in `data.params`, such as `lookup_id` (the ID isn't within the call flow's lengths), `record_status` (unknown status code), `status_required`, `unknown_fields`, `field_type` (a field that isn't text, or a `dryRun` that isn't true or false), `pin`, `phone`, `note`, `note_http` or `date`.

The PBX's REST layer treats any text value starting with "http" as a web address and removes its spaces and non-Latin letters before the module sees it, so a note starting with "http" is refused rather than stored damaged; put a word before the link.

## Requirements

MikoPBX 2026.6.34 or later.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

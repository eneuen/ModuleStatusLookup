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

## Requirements

MikoPBX 2026.6.34 or later.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

# Contributing to Status Lookup

Thanks for your interest. This guide covers how to report problems, how to set up the code, the checks a change must pass, where things live, and how a release is made.

## Reporting problems and suggesting changes

- **Bugs and ideas:** open an issue. For a bug, say which MikoPBX version you run, what you did, what you expected and what happened.
- **Security problems:** please don't open a public issue; follow [SECURITY.md](SECURITY.md).
- **Pull requests:** welcome. Keep each one to a single change, add or update tests where the code allows it, and update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]`. CI must pass.

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
composer check-banned
```

`check-banned` fails if shipped code uses shell or code execution or `unserialize`, or if the browser script or views use `eval`, HTML injection, inline scripts or inline event handlers. Caller numbers come from outside callers, and notes and status names from staff and other software, so the page only ever inserts them as text.

Static analysis runs PHPStan against the MikoPBX core release the module targets. The core and its dependencies are large (about 240 MB), so they live in their own folder:

```bash
composer install -d tools/phpstan --ignore-platform-req='ext-*'
composer analyse
```

The browser assets are built from `public/assets/js/src/` and `build/echarts-entry.js`, and the built files are committed:

```bash
npm ci
npm run build
```

CI (`.github/workflows/ci.yml`) runs all of the above on every push, runs `npm audit`, and checks that the committed bundle matches a fresh build.

Keep code that needs no core classes (the lookup, the dialplan builder, the hand-off, the editors, the importer, PINs, retention, the API's logic) free of core and Phalcon dependencies, so it stays unit-testable here. The unit tests build the module's database from the model annotations in `Models/`, as the core does on install.

Code that needs the MikoPBX core (the hooks, the worker, the AGI scripts, the controller, the REST controllers) is tested on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`. [tools/callsim](tools/callsim/README.md) places real SIP calls through such a test PBX and checks the line end to end, and `api_check.py` checks the REST API with real API keys; run both before a release.

## How a call runs

The module adds its line through MikoPBX's module dialplan hooks; it doesn't edit custom dialplan files.

- **The number:** on install the module registers its internal number (2300, or the first free one up to 2399) as a module extension, so it appears in the destination pickers of incoming routes and IVR menus. `[internal]` sends the number to the module's `[status-lookup]` context; nothing else reaches it.
- **Collecting digits:** the dialplan plays the prompts and collects the ID with `Read()`, so a key pressed during a prompt counts. PHP runs only for a moment per try: `agi-bin/status_lookup.php` looks the ID up (a few indexed reads, read only) and sets channel variables the dialplan acts on. PINs are collected by the same script with `GET DATA`, so they never sit in a channel variable.
- **Limits:** tries per call, the miss limits per caller number and for all callers together (counted from the call log over the last hour), wrong PINs per ID (over the last day), and a 2-minute `TIMEOUT(absolute)` that is cleared before the caller is sent on.
- **Always a person:** 0 at any prompt that listens for keys, the last try, a record the caller may not hear, and any fault lead to a destination the admin chose. A destination that no longer exists falls back to the fault destination, then to an apology.
- **Logging:** a hangup handler (or the line itself, just before it sends the caller on) runs `agi-bin/status_lookup_end.php` once per visit. It checks every value against a fixed pattern (`Lib/Handoff.php`) and passes the record to the module's worker over the PBX's Beanstalk queue. The worker checks it again and is the only writer of the call log and the daily counts. It also places the page's test calls and runs the nightly clean-up.
- **Built-in prompts:** `Sounds/<language>/<key>.wav` (8 kHz, 16-bit mono WAV) are copied by the core into Asterisk's sound folder for that language as `modulestatuslookup-<key>` when the module is enabled, and removed when it is disabled. Settings store them as negative prompt IDs (`BuiltInPrompts::IDS`); the PBX's sound files have positive IDs. To add one, add its key to `BuiltInPrompts::IDS`, a recording in each language folder, and a label in the page script and `Messages/`.

New or re-recorded prompts are converted from the original recording (any format ffmpeg reads) so every prompt has the same level and a short pad at each end:

```bash
ffmpeg -i original.mp3 -af "highpass=f=90,loudnorm=I=-19:TP=-3:LRA=11,adelay=150,apad=pad_dur=0.25,aresample=8000" \
  -ac 1 -ar 8000 -c:a pcm_s16le -map_metadata -1 -fflags +bitexact -flags:a +bitexact Sounds/en-en/<key>.wav
```

## The REST API

The REST controllers in `Lib/RestAPI/<Resource>/Controller.php` are found by the core in every enabled module and only route; the core loads them in every REST request, so keep them trivial (an error there breaks the whole REST API). Their processors run in the core's API worker, which keeps classes in memory: restart it (or the PBX) after changing them on a test PBX. All logic is in `Lib/RecordsApi.php`, which reuses the page's own editors, so the API can't store anything the page couldn't.

## Changing the database

The core creates the module's tables from the model annotations in `Models/` on install, and adds new columns on update. It doesn't reliably change an existing table's indexes or move data; a change like that needs a numbered migration step that the installer runs after the core has restored the kept database, with a unit test that upgrades a database from the previous version. Version 1 is the first release's schema.

## Layout

| Path | What it holds |
| --- | --- |
| `module.json` | Module manifest: ID, version, minimum MikoPBX version |
| `Lib/StatusLookupConf.php` | Hooks the core calls: dialplan, the worker, the test call, the PBX settings the module reads |
| `Lib/DialplanBuilder.php` | Builds every line of dialplan the module adds, from validated settings |
| `Lib/Lookup.php` | One lookup: the ID, the miss limits, expiry, PIN and caller-ID checks, the status to play |
| `agi-bin/status_lookup.php`, `agi-bin/status_lookup_end.php` | The per-try lookup (and PIN, and spoken date), and the end-of-call hand-off |
| `Lib/Handoff.php`, `Lib/CallLogStore.php` | The end-of-call record and its validation; writing it to the call log and the daily counts |
| `Lib/WorkerStatusLookup.php` | Background worker: call records, test calls, the nightly clean-up |
| `Lib/IvrNumber.php`, `Lib/CoreNumber.php` | Choosing and registering the line's internal number |
| `Lib/CallFlowEditor.php`, `Lib/StatusEditor.php`, `Lib/RecordEditor.php` | The page's editors: every change validated |
| `Lib/Importer.php` | CSV import: checking, preview, applying, history |
| `Lib/Templates.php`, `Setup/templates/` | Business templates, one JSON file each |
| `Lib/Pin.php`, `Lib/PinFails.php` | PIN hashing and the key file; a call's wrong PINs per ID |
| `Lib/Retention.php` | Call-log retention, purging expired records, and "forget" |
| `Lib/Activity.php` | The Activity tab's numbers and the call log |
| `Lib/RecordsApi.php`, `Lib/RestAPI/` | The REST API's logic, and its routes and processors |
| `Lib/BuiltInPrompts.php`, `Sounds/` | The module's own prompts: their IDs and names, and the recordings in `Sounds/en-en` and `Sounds/ru-ru` (CC0, `Sounds/LICENSE`) |
| `Lib/PbxOptions.php` | What the page picks from in the PBX: sound files, destinations, extensions |
| `Lib/AccessPolicy.php` | Who may use the page, and what a write request must carry |
| `Lib/Databases.php`, `Lib/Settings.php` | Database connections and transactions; the module's settings row |
| `Models/` | Tables in the module's database; the settings and statuses declare relations, so the PBX refuses to delete a sound file or destination the line uses |
| `Setup/PbxExtensionSetup.php` | Installer: sidebar entry, the number, the PIN key's rights, and on uninstall the AGI links and the number |
| `App/Controllers/`, `App/Views/` | The module page and its JSON actions. Reads are AJAX GETs (the CSV export is a plain GET, so the browser downloads it); writes must be AJAX POSTs with a Bearer token |
| `public/assets/` | Page script and styles; `js/vendor/` holds the ECharts bundle and its license |
| `Messages/` | Interface text (English and Russian) |
| `build/`, `package.json` | Builds the browser assets with esbuild, and the release zip (`build/package.sh`) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tools/callsim/` | Real-call test harness, the probe scenarios and the REST API check |
| `tools/loadtest/` | Concurrent calls during a large import |
| `tools/check-banned.php` | Fails the build if shipped code uses a banned function |
| `tools/phpstan/` | PHPStan setup against the MikoPBX core |
| `docs/` | The README's screenshots |
| `.gitattributes` | Lists the development files that are left out of every release |

## Packaging a release

This section is for maintainers.

What ships is everything except the development files listed as `export-ignore` in `.gitattributes`: tests, tools, build scripts, script sources, dependency manifests, the PHPUnit and git settings, CI files, this guide and the README's screenshots. That one list applies to every archive git makes, including the release zip and GitHub's "Source code" downloads. Those downloads are for developers only, because `module.json` keeps its `%ModuleVersion%` placeholder until a release is packaged. To work on the module, clone the repository.

To release a version:

1. In `CHANGELOG.md`, move the entries under `## [Unreleased]` into a new section for the version and date, for example `## [1.0.0] - 2026-12-01`, leave `## [Unreleased]` empty above it, and commit.
2. Tag that commit with the version (`1.0.0` or `v1.0.0`) and push the tag.
3. The Release workflow runs every CI check on the tagged commit, builds `ModuleStatusLookup-<version>.zip` and its SHA-256 checksum with `build/package.sh`, and attaches them to a **draft** GitHub release, with that version's CHANGELOG section as the notes.
4. Review the draft and publish it.

To build a package locally, for testing:

```bash
build/package.sh 1.0.0-rc.1
```

This writes `dist/ModuleStatusLookup-1.0.0-rc.1.zip` and its checksum, built from the committed files of `HEAD` (or a commit given as the second argument), with the version filled into `module.json`. The same commit always gives the same zip.

## License

By contributing, you agree that your contributions are licensed under the project's license, GPL-3.0-or-later.

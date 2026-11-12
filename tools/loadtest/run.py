#!/usr/bin/env python3
"""Status Lookup load test: concurrent calls while large imports run.

Keeps a Replace All import of 100,000 records running, one after another,
through the REST API (from the PBX itself, as a shop system's script on the
PBX would), and meanwhile places 20 calls to the lookup line at once. Each
call types an ID that is in every import. Then checks that every call was
answered, heard its status and was logged once, and reports how long the
imports and the lookups took.

  run.py [--calls 20] [--rows 100000]

The calls are placed inside the PBX (Asterisk's originate, with a temporary
dialplan helper that sends the ID's keys), so no phones are needed, but it
uses CallSim's probe set-up: run `tools/callsim/callsim.py setup` first and
set the same environment variables. It replaces the module's records and
statuses, so run it on a test PBX only.
"""
import argparse
import json
import re
import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "callsim"))

from callsim import PBX_CONTAINER, docker  # noqa: E402
from probe import VERBOSE_LOG, Probe, events, log_size, rows, sql  # noqa: E402

HELPER = "sl-loadtest"
ID = "10445"
CSV_IN_CONTAINER = "/tmp/sl-loadtest.json"
URL = "http://127.0.0.1/pbxcore/api/v3/module-status-lookup/records-replace:import"
STAMP = re.compile(r"^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]")


def asterisk(command):
    return docker("exec", PBX_CONTAINER, "asterisk", "-rx", command, capture=True)


def add_helper():
    """A context that waits a moment, types the ID and #, and stays on the line while the status plays."""
    asterisk(f"dialplan remove context {HELPER}")
    for priority, app in enumerate(["Wait(1.5)", f"SendDTMF({ID}#,120)", "Wait(60)", "Hangup()"], start=1):
        asterisk(f"dialplan add extension s,{priority},{app} into {HELPER}")
    # A dialplan reload (the module's install, a settings change) removes it again.
    if "Hangup()" not in asterisk(f"dialplan show {HELPER}"):
        sys.exit("The helper context couldn't be added to the dialplan")


def originate(number, calls):
    """Places the calls at once through the Asterisk manager interface (MikoPBX doesn't load the CLI's originate)."""
    script = ("require_once 'Globals.php'; $ami = MikoPBX\\Core\\System\\Util::getAstManager('off');"
              f"for ($i = 0; $i < {int(calls)}; $i++) {{ $r = $ami->Originate('Local/{number}@internal', 's', '{HELPER}', 1,"
              " null, null, null, 'Load test <000' . $i . '>', null, null, true);"
              " if (($r['Response'] ?? '') !== 'Success') { fwrite(STDERR, json_encode($r) . PHP_EOL); } }")
    docker("exec", PBX_CONTAINER, "php", "-r", script)


def write_csv(n):
    lines = ["lookup_id,status_code,note"] + [f"{ID},READY,load test"]
    lines += [f"{20000000 + i},READY,load test row {i}" for i in range(n - 1)]
    body = json.dumps({"csv": "\r\n".join(lines) + "\r\n"})
    local = Path(__file__).resolve().parent / ".import.json"
    local.write_text(body)
    try:
        docker("cp", str(local), f"{PBX_CONTAINER}:{CSV_IN_CONTAINER}")
    finally:
        local.unlink()
    return len(body)


def import_once():
    started = time.time()
    out = docker("exec", PBX_CONTAINER, "curl", "-s", "-m", "130", "-H", "Content-Type: application/json",
                 "--data-binary", f"@{CSV_IN_CONTAINER}", URL, capture=True)
    try:
        body = json.loads(out)
    except ValueError:
        body = {"raw": out[:200]}
    return time.time() - started, body


def clean_up(probe):
    """The helper context, the CSV in the container, the import history and the probe's own records back."""
    asterisk(f"dialplan remove context {HELPER}")
    docker("exec", PBX_CONTAINER, "rm", "-f", CSV_IN_CONTAINER, check=False)
    sql("DELETE FROM m_StatusLookupImports WHERE file_name = 'API'")
    probe.configure()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--calls", type=int, default=20)
    parser.add_argument("--rows", type=int, default=100000)
    args = parser.parse_args()

    probe = Probe()          # the probe's call flow and statuses, and a dialplan reload
    size = write_csv(args.rows)
    print(f"CSV of {args.rows:,} rows ({size / 1048576:.1f} MB as JSON); warming up with one import")
    seconds, body = import_once()
    if not body.get("result"):
        sys.exit(f"The import failed: {body}")
    print(f"  first import: {seconds:.1f} s, {body['data'].get('added', 0):,} added")

    imports = []
    stop = threading.Event()

    def keep_importing():
        while not stop.is_set():
            imports.append(import_once())

    since_log, since_id = log_size(), probe.last_log_id()
    # A daemon thread, stopped in every case: a failed or interrupted run must not leave imports running.
    worker = threading.Thread(target=keep_importing, daemon=True)
    worker.start()
    found = []
    try:
        time.sleep(0.5)
        print(f"Placing {args.calls} calls while imports run")
        add_helper()
        originate(probe.number, args.calls)
        deadline = time.time() + 120
        while time.time() < deadline:
            found = rows(f"SELECT * FROM m_StatusLookupCallLog WHERE id > {since_id} ORDER BY id")
            if len(found) >= args.calls:
                break
            time.sleep(2)
    finally:
        stop.set()
        worker.join(timeout=150)
        if worker.is_alive() or sys.exc_info()[0] is not None:
            clean_up(probe)

    ok = True

    def check(cond, what):
        nonlocal ok
        ok = ok and bool(cond)
        print(f"  {'PASS' if cond else 'FAIL'}  {what}")

    seen = events(since_log)
    lookups = [e for e in seen if e["event"] == "lookup"]
    inputs = {e.get("chan"): e for e in seen if e["event"] == "input"}
    times = [t for t, b in imports if b.get("result")]
    print(f"Imports while the calls ran: {len(imports)} ({len(times)} succeeded), "
          f"{min(times or [0]):.1f}-{max(times or [0]):.1f} s each")
    check(len(times) == len(imports) and len(imports) >= 1, "every import succeeded")
    check(len(lookups) == args.calls and all(e.get("result") == "hit" for e in lookups),
          f"every call's lookup found the record ({sum(e.get('result') == 'hit' for e in lookups)} of {args.calls}: "
          f"{sorted({e.get('result') for e in lookups})})")
    check(len(found) == args.calls and all(r["outcome"] == "hit" for r in found),
          f"every call logged once, as heard its status ({len(found)} rows: {sorted({r['outcome'] for r in found})})")
    # The log has whole seconds; a lookup that waited for the import's write lock would show here.
    log = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since_log + 1} {VERBOSE_LOG} | grep SLPROBE || true", capture=True)
    stamps = {}
    for line in log.splitlines():
        m = STAMP.match(line)
        chan = re.search(r"chan=(\S+)", line)
        event = re.search(r"SLPROBE event=(\S+)", line)
        if m and chan and event and "Executing [" not in line:
            stamps.setdefault(chan.group(1), {})[event.group(1)] = time.mktime(time.strptime(m.group(1), "%Y-%m-%d %H:%M:%S"))
    waits = [s["lookup"] - s["input"] for s in stamps.values() if "lookup" in s and "input" in s]
    check(waits and max(waits) <= 1, f"each lookup answered within a second of the ID (longest: {max(waits or [0]):.0f} s)")
    clean_up(probe)
    print("\nLoad test passed." if ok else "\nLoad test failed.")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()

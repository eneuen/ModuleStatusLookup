#!/usr/bin/env python3
"""Status Lookup probe: real calls to the lookup line, checked end to end.

The module's dialplan writes a "SLPROBE event=... key=value" line to
Asterisk's verbose log at every decision. Each scenario places a real call
with CallSim's carrier or phones, presses keys, and checks those lines, which
phone rang, whether the caller is still connected, and the row the module's
worker wrote to its call log.

  probe.py list            list the scenarios
  probe.py run [names...]  run all scenarios (or the named ones)

Needs `callsim.py setup` first, the module installed and enabled, and the
same environment variables as callsim.py. The probe replaces the module's
call flow, statuses and records with its own test set, and adds an inbound
route for DID 5551091 to the lookup line (named so `callsim.py teardown`
removes it), so run it on a test PBX only.
"""
import json
import re
import sys
import time

import callsim
from callsim import NAME, PBX_CONTAINER, Line, Pbx, callsim_routes_in_hours, docker, load_state, pause

MODULE_DB = "/storage/usbdisk1/mikopbx/custom_modules/ModuleStatusLookup/db/module.db"
VERBOSE_LOG = "/storage/usbdisk1/mikopbx/log/asterisk/verbose"
PROBE_DID = "5551091"
PROBE_ROUTE = f"{NAME} Status Lookup probe {PROBE_DID}"

OPERATOR = "301"
TOO_MANY = "3100"     # CallSim's queue: rings 302 and 303
FAULT = "304"
APPROVAL = "305"      # where the NEEDS_APPROVAL status transfers
PROMPT = 2            # a PBX sound file every MikoPBX has (the default greeting)

LINE_RE = re.compile(r"SLPROBE event=(\S+)(.*)$")
PAIR_RE = re.compile(r"(\w+)=(\S*)")

PIN = "4821"
OTHER_PHONE = "4915112345678"

STATUSES = [
    # code, prompt, spoken extra, after-action, transfer number, asks for the PIN, own phone only
    ("READY", PROMPT, "id", "repeat", "", 0, 0),
    ("SHIPPED", PROMPT, "date", "hangup", "", 0, 0),
    ("NEEDS_APPROVAL", PROMPT, "none", "transfer", APPROVAL, 0, 0),
    ("GONE_TARGET", PROMPT, "none", "transfer", "2999", 0, 0),
    ("PRIVATE", PROMPT, "none", "hangup", "", 1, 0),
    ("MINE", PROMPT, "none", "hangup", "", 0, 1),
]
RECORDS = [
    # lookup ID, status, date, last day, phone, PIN
    ("10445", "READY", "", "", "", ""),
    ("00451", "SHIPPED", "2026-11-12", "", "", ""),
    ("20001", "NEEDS_APPROVAL", "", "", "", ""),
    ("20002", "GONE_TARGET", "", "", "", ""),
    ("30001", "READY", "", "2020-01-01", "", ""),
    ("40001", "PRIVATE", "", "", "", PIN),
    ("40002", "PRIVATE", "", "", "", ""),
    ("40003", "MINE", "", "", callsim.CALLER, ""),
    ("40004", "MINE", "", "", OTHER_PHONE, ""),
    ("40005", "MINE", "", "", "", ""),
]
SETTINGS = {
    "active": "1", "operator_number": OPERATOR, "too_many_tries_number": TOO_MANY, "fault_number": FAULT,
    "id_min_length": 4, "id_max_length": 10, "digit_timeout": 3, "max_tries": 3,
    "miss_limit_caller": 0, "miss_limit_total": 0,
}


# --------------------------------------------------------------------------- the PBX side

def sql(statement):
    # The module's worker and imports write too: wait for their lock instead of failing.
    return docker("exec", PBX_CONTAINER, "sqlite3", "-cmd", ".timeout 10000", "-json", MODULE_DB, statement, capture=True).strip()


def rows(statement):
    out = sql(statement)
    return json.loads(out) if out else []


def quote(value):
    return "'" + str(value).replace("'", "''") + "'"


def pin_hash(pin):
    """The PIN's hash, made with the PBX's own key (as the page and imports store it)."""
    return docker("exec", PBX_CONTAINER, "php", "-r",
                  "require_once 'Globals.php'; echo Modules\\ModuleStatusLookup\\Lib\\Pin::fromFile("
                  "Modules\\ModuleStatusLookup\\Lib\\StatusLookupConf::pinKeyFile())->hash('" + pin + "');", capture=True).strip()


def reload_dialplan():
    docker("exec", PBX_CONTAINER, "php", "-r",
           "require_once 'Globals.php'; MikoPBX\\Core\\Workers\\WorkerModelsEvents::invokeAction("
           "MikoPBX\\Core\\Workers\\Libs\\WorkerModelsEvents\\Actions\\ReloadDialplanAction::class);")


def wait_dialplan(text, timeout=30):
    deadline = time.time() + timeout
    while time.time() < deadline:
        if text in docker("exec", PBX_CONTAINER, "asterisk", "-rx", "dialplan show status-lookup", capture=True):
            return
        time.sleep(1)
    raise RuntimeError(f"the lookup dialplan has no {text!r} after {timeout} s")


class Probe:
    def __init__(self):
        self.pbx = Pbx()
        self.number = rows("SELECT internal_number FROM m_StatusLookupSettings WHERE id = 1")[0]["internal_number"]
        self.route_id = self.ensure_route()
        self.configure()

    def configure(self, **changes):
        """The probe's call flow, statuses and records, then a dialplan reload."""
        settings = {**SETTINGS, **changes}
        assignments = ", ".join(f"{k} = {quote(v)}" for k, v in settings.items())
        statements = [f"UPDATE m_StatusLookupSettings SET {assignments} WHERE id = 1",
                      "DELETE FROM m_StatusLookupStatuses", "DELETE FROM m_StatusLookupRecords",
                      # Wrong PINs from earlier runs would lock the probe's PIN record.
                      "UPDATE m_StatusLookupCallLog SET pin_fails = 0, pin_id = ''"]
        for code, prompt, extra, action, transfer, pin, own in STATUSES:
            statements.append("INSERT INTO m_StatusLookupStatuses (code, name, prompt_id, spoken_extra, after_action, transfer_number, "
                              f"requires_pin, requires_callerid) VALUES ({quote(code)}, {quote(code)}, {prompt}, {quote(extra)}, {quote(action)}, "
                              f"{quote(transfer)}, {quote(pin)}, {quote(own)})")
        hashes = {pin: pin_hash(pin) for *_, pin in RECORDS if pin}
        for lookup_id, status, date, expires, phone, pin in RECORDS:
            statements.append("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, status_date, expires_on, phone, pin_hash, updated_at, source) "
                              f"VALUES ({quote(lookup_id)}, {quote(status)}, {quote(date)}, {quote(expires)}, {quote(phone)}, "
                              f"{quote(hashes.get(pin, ''))}, strftime('%s','now'), 'form')")
        sql("; ".join(statements))
        reload_dialplan()
        if settings["active"] != "1":
            wait_dialplan("Set(SL_OUTCOME=inactive)")
            return
        wait_dialplan(f"GotoIf($[${{SL_TRIES}} >= {settings['max_tries']}]?too_many)")
        wait_dialplan(f"Set(SL_TARGET={settings['too_many_tries_number']})")
        wait_dialplan(f"Read(SL_IN,${{SL_ASK}},,,1,{settings['digit_timeout']})")

    def ensure_route(self):
        """The probe's own inbound route to the lookup line (named so CallSim's teardown removes it)."""
        for r in self.pbx.items("incoming-routes"):
            if r.get("rulename") == PROBE_ROUTE:
                return int(r["id"])
        route = self.pbx.default("incoming-routes")
        route.update({"rulename": PROBE_ROUTE, "number": PROBE_DID, "providerid": load_state()["provider_id"],
                      "extension": self.number, "timeout": 300})
        route_id = int(self.pbx.post("/incoming-routes", route)["data"]["id"])
        deadline = time.time() + 30
        while time.time() < deadline:
            if f"Local/{self.number}@internal-incoming" in docker("exec", PBX_CONTAINER, "asterisk", "-rx", "dialplan show", capture=True):
                return route_id
            time.sleep(1)
        raise RuntimeError("the probe route never reached the dialplan")

    def route_timeout(self, seconds):
        self.pbx.patch(f"/incoming-routes/{self.route_id}", {"timeout": seconds})
        deadline = time.time() + 30
        while time.time() < deadline:
            if f"Set(M_TIMEOUT={seconds})" in docker("exec", PBX_CONTAINER, "asterisk", "-rx", "dialplan show", capture=True):
                return
            time.sleep(1)
        raise RuntimeError(f"the route timeout {seconds} never reached the dialplan")

    def last_log_id(self):
        found = rows("SELECT COALESCE(MAX(id), 0) AS id FROM m_StatusLookupCallLog")
        return int(found[0]["id"]) if found else 0

    def logged(self, since_id, count=1, timeout=20):
        """The call-log rows the worker wrote after `since_id`, once there are `count` of them."""
        deadline = time.time() + timeout
        found = []
        while time.time() < deadline:
            found = rows(f"SELECT * FROM m_StatusLookupCallLog WHERE id > {since_id} ORDER BY id")
            if len(found) >= count:
                return found
            time.sleep(1)
        return found


def log_size():
    return int(docker("exec", PBX_CONTAINER, "sh", "-c", f"wc -c < {VERBOSE_LOG}", capture=True).strip() or 0)


def events(since_bytes):
    """SLPROBE lines written after the log offset, as dicts in log order."""
    out = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since_bytes + 1} {VERBOSE_LOG} | grep SLPROBE || true", capture=True)
    found = []
    for line in out.splitlines():
        # The verbose log also traces each Verbose() call ("Executing [...] Verbose(...)"); keep the message only.
        if "Executing [" in line:
            continue
        m = LINE_RE.search(line)
        if m:
            found.append({"event": m.group(1), **dict(PAIR_RE.findall(m.group(2)))})
    return found


def wait_event(since_bytes, name, timeout=20, **match):
    deadline = time.time() + timeout
    while time.time() < deadline:
        for e in events(since_bytes):
            if e["event"] == name and all(e.get(k) == v for k, v in match.items()):
                return e
        time.sleep(0.5)
    return None


# --------------------------------------------------------------------------- checks

class Checks:
    def __init__(self):
        self.items = []

    def that(self, ok, what):
        self.items.append((bool(ok), what))

    @property
    def ok(self):
        return all(ok for ok, _ in self.items)


def rang(line, timeout):
    try:
        line.ringing(timeout)
        return True
    except TimeoutError:
        return False


def connected(line, timeout=10):
    try:
        line.established(timeout)
        return True
    except TimeoutError:
        return False


def closed(line, timeout):
    try:
        line.closed(timeout)
        return True
    except TimeoutError:
        return False


def call_in(p):
    """The carrier calls the probe DID; returns the caller's line once the lookup has answered."""
    caller = Line("carrier")
    caller.dial(PROBE_DID)
    if not connected(caller):
        raise RuntimeError("the lookup line didn't answer")
    return caller


def enter(since, caller, digits, try_number=1):
    """Waits until the lookup asks for the ID (try `try_number`), then types it."""
    pause(2.5 if try_number == 1 else 1.5)
    caller.keys(digits)


def check_row(c, p, since_id, **expected):
    found = p.logged(since_id)
    c.that(len(found) == 1, f"one call-log row ({len(found)})")
    if found:
        row = found[0]
        for key, value in expected.items():
            c.that(str(row.get(key)) == str(value), f"call log {key} = {value} ({row.get(key)})")


SCENARIOS = []


def scenario(description):
    def wrap(fn):
        SCENARIOS.append({"name": fn.__name__, "description": description, "run": fn})
        return fn
    return wrap


# --------------------------------------------------------------------------- scenarios

@scenario("Valid ID with #: the status plays with the ID read back, then the repeat menu; no key, so goodbye and hang up.")
def valid_id(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "10445#")
    hit = wait_event(since, "lookup", result="hit")
    c.that(hit and hit.get("id") == "10445" and hit.get("status") == "READY", f"lookup hit READY ({hit})")
    c.that(wait_event(since, "goodbye", timeout=40), "goodbye after the repeat menu times out")
    c.that(closed(caller, 10), "the lookup hangs up")
    check_row(c, p, log_id, outcome="hit", id_entered="10445", status_code="READY", target="", tries=1, misses=0)


@scenario("ID with leading zeros, typed without #: found after the digit timeout; the status's date is spoken.")
def leading_zeros_no_hash(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "00451")
    hit = wait_event(since, "lookup", timeout=15, result="hit")
    c.that(hit and hit.get("id") == "00451" and hit.get("status") == "SHIPPED", f"lookup hit SHIPPED ({hit})")
    c.that(wait_event(since, "goodbye", timeout=30), "goodbye (hang-up after-action)")
    c.that(closed(caller, 10), "the lookup hangs up")
    played = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since + 1} {VERBOSE_LOG} | grep -o \"Playing 'digits/[a-z0-9-]*\" || true",
                    capture=True)
    # Thursday, November twelfth: weekday, month and day, no year.
    c.that(all(f"digits/{f}" in played for f in ("day-4", "mon-10", "h-12")), "the date is spoken: weekday, month, day")
    c.that("digits/thousand" not in played, "without the year")
    check_row(c, p, log_id, outcome="hit", id_entered="00451", status_code="SHIPPED")


@scenario("Unknown ID three times: 'not found' each time, then the too-many-tries destination (the queue) rings.")
def not_found_three_times(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, q1, q2 = call_in(p), Line("302"), Line("303")
    for n in (1, 2, 3):
        enter(since, caller, "99999#", n)
        c.that(wait_event(since, "lookup", timeout=15, result="not_found", id="99999") is not None, f"try {n}: not found")
        pause(2)
    c.that(rang(q1, 20) and rang(q2, 3), "the queue's members ring")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="too_many", last_result="not_found", tries=3, misses=3, target=TOO_MANY)


@scenario("0 at the first prompt: the operator rings at once, without a lookup.")
def zero_first(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "0#")
    c.that(rang(op, 15), "the operator rings")
    c.that(wait_event(since, "lookup", timeout=1) is None, "no lookup ran")
    pause(1); caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="operator", target=OPERATOR, misses=0)


@scenario("0 in the middle of the status sentence cuts it short and reaches the operator.")
def zero_during_status(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "10445#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit") is not None, "lookup hit")
    pause(5)   # the test sentence is 16 s long
    t0 = time.time()
    caller.keys("0")
    c.that(rang(op, 5), f"the operator rings ({time.time() - t0:.1f} s after 0)")
    played = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since + 1} {VERBOSE_LOG} | grep -c \"Playing 'digits/\" || true",
                    capture=True)
    c.that(played.strip() == "0", "the ID was not read back: the sentence was cut short")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="hit", target=OPERATOR)


@scenario("Expired record: heard as not found; then 0 reaches the operator.")
def expired_record(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "30001#")
    c.that(wait_event(since, "lookup", timeout=15, result="expired") is not None, "lookup result: expired")
    pause(2)
    caller.keys("0#")
    c.that(rang(op, 15), "the operator rings after 0")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="operator", last_result="expired", misses=1)


@scenario("A status with a transfer after-action: the caller goes straight to its destination.")
def transfer_status(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, target = call_in(p), Line(APPROVAL)
    enter(since, caller, "20001#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit", status="NEEDS_APPROVAL") is not None, "lookup hit NEEDS_APPROVAL")
    c.that(rang(target, 20), f"{APPROVAL} rings")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="hit", status_code="NEEDS_APPROVAL", target=APPROVAL)


@scenario("A status that transfers to a number that no longer exists: the fault destination rings instead.")
def deleted_destination(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, fault = call_in(p), Line(FAULT)
    enter(since, caller, "20002#")
    c.that(wait_event(since, "missing_target", timeout=20, target="2999") is not None, "the missing destination is noticed")
    c.that(rang(fault, 20), f"the fault destination {FAULT} rings")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="fault", status_code="GONE_TARGET", target=FAULT)


@scenario("Hang up during the enter-ID prompt: the call is still logged, as abandoned.")
def hang_up_in_prompt(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    pause(2)
    caller.hangup()
    c.that(wait_event(since, "end", timeout=10, outcome="abandoned") is not None, "the hangup handler ran")
    check_row(c, p, log_id, outcome="abandoned", tries=1, target="")


@scenario("A route with a 15 s timeout: the caller is still in the lookup after 20 s, and 0 then reaches the operator.")
def short_route_timeout(p, c):
    p.route_timeout(15)
    try:
        since, log_id = log_size(), p.last_log_id()
        caller, op = call_in(p), Line(OPERATOR)
        t0 = time.time()
        enter(since, caller, "10445#")
        c.that(wait_event(since, "lookup", timeout=15, result="hit") is not None, "lookup hit")
        pause(max(0, 20 - (time.time() - t0)))
        c.that(not closed(caller, 1), f"still connected after {time.time() - t0:.0f} s (route timeout 15 s)")
        # A key during the ID read-back is lost; keep pressing 0 until a prompt that takes keys hears it.
        for _ in range(4):
            caller.keys("0")
            if rang(op, 3):
                break
        else:
            c.that(False, "the operator rings after 0")
        caller.hangup(); pause(2)
        check_row(c, p, log_id, outcome="hit", target=OPERATOR)
    finally:
        p.route_timeout(300)


@scenario("After goodbye the call ends: it doesn't fall through to the route's default action, and no phone rings.")
def no_fall_through(p, c):
    since = log_size()
    caller, phones = call_in(p), [Line(n) for n in callsim.PHONES if n != "305"]
    enter(since, caller, "00451#")
    c.that(wait_event(since, "goodbye", timeout=30) is not None, "goodbye")
    c.that(closed(caller, 10), "the caller's call ends")
    c.that(not any(rang(ph, 1) for ph in phones), "no phone rings afterwards")


@scenario("A phone transfers a caller to the lookup line: the caller looks up an ID there.")
def transfer_from_phone(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, agent = Line("carrier"), Line("301")
    caller.dial(callsim.DID_DIRECT)
    c.that(rang(agent, 15), "301 rings")
    agent.answer(); connected(caller, 5); pause(1)
    agent.transfer(p.number)
    c.that(wait_event(since, "entry", timeout=15) is not None, "the caller reaches the lookup")
    enter(since, caller, "10445#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit") is not None, "lookup hit")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="hit", id_entered="10445")


@scenario("Miss limit 2 per caller: after two misses, the caller's next call goes straight to the operator.")
def miss_limit(p, c):
    p.configure(miss_limit_caller=2)
    # Misses from the earlier scenarios (same caller number) would count too.
    sql("DELETE FROM m_StatusLookupCallLog")
    try:
        since, log_id = log_size(), p.last_log_id()
        caller = call_in(p)
        for n in (1, 2):
            enter(since, caller, "88888#", n)
            c.that(wait_event(since, "lookup", timeout=15, result="not_found", id="88888") is not None, f"miss {n}: not found")
            pause(2)
        caller.hangup(); pause(2)
        p.logged(log_id)
        since, log_id = log_size(), p.last_log_id()
        caller, op = call_in(p), Line(OPERATOR)
        enter(since, caller, "10445#")
        c.that(wait_event(since, "lookup", timeout=15, result="rate_limited") is not None, "the valid ID is refused: rate limited")
        c.that(rang(op, 15), "the operator rings")
        caller.hangup(); pause(2)
        check_row(c, p, log_id, outcome="rate_limited", target=OPERATOR)
    finally:
        p.configure()


@scenario("The page's test call: the worker rings 302; answered, it runs the line, logged as a test call from 302.")
def test_call(p, c):
    import page_client
    since, log_id = log_size(), p.last_log_id()
    phone = Line("302")
    c.that(page_client.post(p.pbx, "testCall", {"extension": "302"}).get("success"), "the page's test call is accepted")
    c.that(rang(phone, 15), "302 rings")
    phone.answer()
    pause(3)
    phone.keys("10445#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit") is not None, "lookup hit")
    pause(2); phone.hangup(); pause(3)
    check_row(c, p, log_id, outcome="hit", is_test=1, caller="302")


@scenario("Not switched on: the line doesn't answer, so the route moves on; the call is still logged.")
def inactive(p, c):
    p.configure(active="0")
    try:
        since, log_id = log_size(), p.last_log_id()
        caller = Line("carrier")
        caller.dial(PROBE_DID)
        c.that(wait_event(since, "entry", timeout=10, active="0") is not None, "the call reaches the line")
        c.that(not connected(caller, 4), "the line doesn't answer")
        caller.hangup(); pause(2)
        check_row(c, p, log_id, outcome="inactive", target="")
    finally:
        p.configure()


@scenario("The lookup script fails (its AGI link is missing): a valid ID sends the caller to the fault destination, "
          "not to 'not found'.")
def lookup_script_fails(p, c):
    link = "/var/lib/asterisk/agi-bin/status_lookup.php"
    docker("exec", PBX_CONTAINER, "mv", link, link + ".off")
    try:
        since, log_id = log_size(), p.last_log_id()
        caller, fault = call_in(p), Line(FAULT)
        enter(since, caller, "10445#")
        c.that(wait_event(since, "lookup", timeout=15, result="fault") is not None, "lookup result: fault")
        c.that(rang(fault, 15), f"the fault destination {FAULT} rings")
        caller.hangup(); pause(2)
        check_row(c, p, log_id, outcome="fault", misses=0, target=FAULT)
    finally:
        docker("exec", PBX_CONTAINER, "mv", link + ".off", link)


@scenario("The lookup's 2-minute limit: a caller who never types anything is sent to the too-many-tries destination, "
          "not cut off (takes about 2 minutes).")
def time_limit(p, c):
    p.configure(max_tries=9, digit_timeout=30)
    try:
        since, log_id = log_size(), p.last_log_id()
        caller, q1 = call_in(p), Line("302")
        c.that(wait_event(since, "time_limit", timeout=130) is not None, "the time limit is reached")
        c.that(rang(q1, 15), "the too-many-tries queue rings")
        c.that(not closed(caller, 1), "the caller is still connected")
        caller.hangup(); pause(2)
        check_row(c, p, log_id, outcome="too_many", target=TOO_MANY, misses=0)
    finally:
        p.configure()


@scenario("A status that asks for the PIN: the right PIN plays the status; the PIN never reaches Asterisk's log.")
def pin_right(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "40001#")
    c.that(wait_event(since, "lookup", timeout=15, result="need_pin", id="40001") is not None, "the lookup asks for the PIN")
    pause(2)
    caller.keys(PIN + "#")
    hit = wait_event(since, "pin", timeout=15, result="hit")
    c.that(hit and hit.get("status") == "PRIVATE", f"right PIN: status PRIVATE ({hit})")
    c.that(wait_event(since, "goodbye", timeout=30) is not None, "goodbye (hang-up after-action)")
    # Asterisk logs each key pressed on its own line (a PBX setting); the PIN as a whole is in no variable or dialplan line.
    logged = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since + 1} {VERBOSE_LOG} | grep -c {PIN} || true", capture=True)
    c.that(logged.strip() == "0", "the PIN as a whole is in no variable or dialplan line")
    check_row(c, p, log_id, outcome="hit", status_code="PRIVATE", last_result="hit", id_entered="40001", misses=0)


@scenario("A wrong PIN: heard as not found, counted as a miss, and the caller can try again with another ID.")
def pin_wrong(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "40001#")
    c.that(wait_event(since, "lookup", timeout=15, result="need_pin") is not None, "the lookup asks for the PIN")
    pause(2)
    caller.keys("1111#")
    c.that(wait_event(since, "pin", timeout=15, result="pin_failed") is not None, "wrong PIN: pin_failed")
    pause(4)
    caller.keys("10445#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit", id="10445") is not None, "the next try works")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="hit", status_code="READY", tries=2, misses=1, pin_fails=1, pin_id="40001")


@scenario("A status that asks for a PIN, on a record without one: the caller is put through to the operator.")
def no_pin_stored(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "40002#")
    c.that(wait_event(since, "lookup", timeout=15, result="no_pin") is not None, "lookup result: no_pin")
    c.that(rang(op, 15), "the operator rings")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="operator", last_result="no_pin", target=OPERATOR, misses=1)


@scenario("A status only for the record's own phone, called from that phone: the status plays.")
def callerid_match(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "40003#")
    c.that(wait_event(since, "lookup", timeout=15, result="hit", status="MINE") is not None, "lookup hit MINE")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="hit", status_code="MINE")


@scenario("A status only for the record's own phone, called from another number: heard as not found.")
def callerid_mismatch(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller = call_in(p)
    enter(since, caller, "40004#")
    c.that(wait_event(since, "lookup", timeout=15, result="callerid_failed") is not None, "lookup result: callerid_failed")
    pause(2)
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="abandoned", last_result="callerid_failed", misses=1)


@scenario("0 at the PIN prompt reaches the operator.")
def zero_at_pin(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "40001#")
    c.that(wait_event(since, "lookup", timeout=15, result="need_pin") is not None, "the lookup asks for the PIN")
    pause(2)
    caller.keys("0#")
    c.that(rang(op, 15), "the operator rings")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="operator", target=OPERATOR, misses=1, pin_fails=0)


@scenario("An ID with 10 wrong PINs in the last day, from other callers: its PIN isn't asked for, the operator rings.")
def pin_locked(p, c):
    sql("INSERT INTO m_StatusLookupCallLog (linkedid, started_at, ended_at, caller, outcome, is_test, pin_fails, pin_id) "
        "VALUES ('probe-lock', strftime('%s','now') - 600, strftime('%s','now') - 590, '999', 'abandoned', '0', 10, '40001')")
    try:
        since, log_id = log_size(), p.last_log_id()
        caller, op = call_in(p), Line(OPERATOR)
        enter(since, caller, "40001#")
        c.that(wait_event(since, "lookup", timeout=15, result="pin_locked") is not None, "lookup result: pin_locked")
        c.that(rang(op, 15), "the operator rings")
        caller.hangup(); pause(2)
        found = rows(f"SELECT * FROM m_StatusLookupCallLog WHERE id > {log_id} AND linkedid != 'probe-lock' ORDER BY id")
        c.that(len(found) == 1 and found[0]["outcome"] == "operator" and found[0]["last_result"] == "pin_locked",
               f"logged as pin_locked, to the operator ({found[:1]})")
    finally:
        sql("DELETE FROM m_StatusLookupCallLog WHERE linkedid = 'probe-lock' OR pin_id = '40001'")


@scenario("A status only for the record's own phone, on a record without a phone: the operator rings.")
def no_phone_stored(p, c):
    since, log_id = log_size(), p.last_log_id()
    caller, op = call_in(p), Line(OPERATOR)
    enter(since, caller, "40005#")
    c.that(wait_event(since, "lookup", timeout=15, result="no_phone") is not None, "lookup result: no_phone")
    c.that(rang(op, 15), "the operator rings")
    caller.hangup(); pause(2)
    check_row(c, p, log_id, outcome="operator", last_result="no_phone", target=OPERATOR, misses=1)


@scenario("The carrier dials the line's number itself as a DID, with no route for it: the lookup isn't reached.")
def not_reachable_from_trunk(p, c):
    since = log_size()
    caller = Line("carrier")
    caller.dial(p.number)
    pause(8)
    c.that(wait_event(since, "entry", timeout=1) is None, "no visit to the lookup")
    caller.hangup(); pause(2)


@scenario("A caller put through to the operator talks for longer than the lookup's 2-minute limit: the call isn't cut "
          "(takes about 2.5 minutes).")
def long_conversation_after_lookup(p, c):
    since = log_size()
    caller, op = call_in(p), Line(OPERATOR)
    t0 = time.time()
    enter(since, caller, "0#")
    c.that(rang(op, 15), "the operator rings")
    op.answer()
    pause(max(0, 135 - (time.time() - t0)))
    c.that(not closed(caller, 1) and not closed(op, 1), f"still talking after {time.time() - t0:.0f} s")
    op.hangup(); pause(2)


@scenario("A caller ID of 123;Hangup() with a name that is HTML: the lookup runs and the call log keeps only the digits.")
def hostile_caller_id(p, c):
    since, log_id = log_size(), p.last_log_id()
    script = ("require_once 'Globals.php'; $r = MikoPBX\\Core\\System\\Util::getAstManager('off')->Originate("
              f"'Local/{p.number}@internal', 's', 'sl-probe-dtmf', 1, null, null, null,"
              " '\"><img src=x onerror=alert(1)>\" <123;Hangup()>', null, null, true);"
              " echo $r['Response'] ?? '';")
    asterisk = lambda cmd: docker("exec", PBX_CONTAINER, "asterisk", "-rx", cmd, capture=True)
    asterisk("dialplan remove context sl-probe-dtmf")
    for n, app in enumerate(["Wait(1.5)", "SendDTMF(10445#,120)", "Wait(8)", "Hangup()"], start=1):
        asterisk(f"dialplan add extension s,{n},{app} into sl-probe-dtmf")
    try:
        out = docker("exec", PBX_CONTAINER, "php", "-r", script, capture=True)
        c.that("Success" in out, f"the call is placed ({out.strip()})")
        c.that(wait_event(since, "lookup", timeout=20, result="hit") is not None, "the lookup runs")
        found = p.logged(log_id, timeout=30)
        c.that(len(found) == 1 and found[0]["caller"] == "123", f"logged with caller 123 ({[r['caller'] for r in found]})")
    finally:
        asterisk("dialplan remove context sl-probe-dtmf")


@scenario("Values that would break out of the dialplan (a newline, a bracket, ${...}) are refused through the page, "
          "and the generated dialplan doesn't change.")
def dialplan_injection_refused(p, c):
    import page_client
    before = docker("exec", PBX_CONTAINER, "md5sum", "/etc/asterisk/extensions.conf", capture=True).split()[0]
    bad = ["301)\nexten => s,1,System(id)", "301${SHELL(id)}", "3 01", "301,1"]
    for value in bad:
        res = page_client.post(p.pbx, "saveCallFlow", {"settings": json.dumps({"operator_number": value})})
        c.that(not res.get("success") and res.get("message") == "destination", f"operator {value!r} refused ({res.get('message')})")
    for field, value in [("transfer_number", "305)\nsame => n,Hangup"), ("code", "X)\n"), ("name", "a\nb${SHELL(id)}")]:
        status = {"name": "Injection", "code": "INJECT", "script": "", "prompt_id": str(PROMPT), "spoken_extra": "none",
                  "after_action": "transfer", "transfer_number": "305", field: value}
        res = page_client.post(p.pbx, "saveStatus", {"status": json.dumps(status)})
        stored = rows("SELECT name FROM m_StatusLookupStatuses WHERE code = 'INJECT'")
        if field == "name" and res.get("success"):
            # Names are for staff and never reach the dialplan; control characters are dropped.
            c.that(stored and "\n" not in stored[0]["name"], f"a name with a newline is stored without it ({stored})")
        else:
            c.that(not res.get("success"), f"status {field} {value!r} refused ({res.get('message')})")
        sql("DELETE FROM m_StatusLookupStatuses WHERE code = 'INJECT'")
    reload_dialplan()
    time.sleep(5)
    after = docker("exec", PBX_CONTAINER, "md5sum", "/etc/asterisk/extensions.conf", capture=True).split()[0]
    c.that(before == after, "extensions.conf is unchanged")


CF_DB = "/storage/usbdisk1/mikopbx/custom_modules/ModuleCallFeedback/db/module.db"


def cf_sql(statement):
    return docker("exec", PBX_CONTAINER, "sqlite3", "-cmd", ".timeout 10000", CF_DB, statement, capture=True).strip()


@scenario("With the Call Feedback module surveying the probe's route: a caller who only used the lookup isn't surveyed; "
          "one the lookup put through to the operator, who answered, is. (Skipped without an active Call Feedback survey.)")
def with_call_feedback(p, c):
    active = docker("exec", PBX_CONTAINER, "sh", "-c",
                    f"sqlite3 /cf/conf/mikopbx.db \"SELECT disabled FROM m_PbxExtensionModules WHERE uniqid = 'ModuleCallFeedback'\" 2>/dev/null; "
                    f"[ -f {CF_DB} ] && sqlite3 {CF_DB} \"SELECT family_id FROM m_Surveys WHERE status = 'active' LIMIT 1\"", capture=True).split()
    if len(active) != 2 or active[0] != "0":
        c.that(True, "skipped: Call Feedback isn't installed and enabled with an active survey")
        return
    family = int(active[1])
    target = cf_sql(f"INSERT INTO m_SurveyTargets (family_id, direction, route_id) VALUES ({family}, 'in', {p.route_id}); SELECT last_insert_rowid()")
    try:
        reload_dialplan()
        deadline = time.time() + 30
        while PROBE_DID not in docker("exec", PBX_CONTAINER, "asterisk", "-rx", "dialplan show call-feedback-in-match", check=False, capture=True):
            if time.time() > deadline:
                raise RuntimeError("Call Feedback's dialplan never covered the probe's route")
            time.sleep(1)

        # Only the lookup: status, goodbye, the line hangs up.
        since = log_size()
        caller = call_in(p)
        enter(since, caller, "00451#")
        c.that(wait_event(since, "goodbye", timeout=30) is not None, "lookup only: goodbye")
        closed(caller, 10)
        skip = None
        deadline = time.time() + 10
        while skip is None and time.time() < deadline:
            out = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since + 1} {VERBOSE_LOG} | grep 'CFPROBE event=' || true", capture=True)
            if "event=skip" in out or "event=in_talk" in out:
                skip = out
            time.sleep(0.5)
        c.that(skip is not None and "reason=no_agent" in skip and "event=in_talk" not in skip,
               f"lookup only: Call Feedback skips it, no agent answered ({(skip or '').strip()[-120:]})")

        # Put through to the operator, who answers and talks.
        since = log_size()
        caller, op = call_in(p), Line(OPERATOR)
        enter(since, caller, "0#")
        c.that(rang(op, 15), "0: the operator rings")
        op.answer()
        pause(6)
        op.hangup()
        talk = None
        deadline = time.time() + 15
        while talk is None and time.time() < deadline:
            out = docker("exec", PBX_CONTAINER, "sh", "-c", f"tail -c +{since + 1} {VERBOSE_LOG} | grep 'CFPROBE event=in_talk' || true", capture=True)
            talk = re.search(r"cf_talk=(\d+)", out)
            time.sleep(0.5)
        # Call Feedback 1.0 counts talk time from when the caller's channel was answered, which for any IVR that
        # hands callers to an extension (the core's menus too) includes the time in the IVR; so only "at least".
        c.that(talk is not None and int(talk.group(1)) >= 5,
               f"through the operator: offered the survey ({talk.group(1) if talk else None} s of talk counted)")
        caller.hangup()
    finally:
        cf_sql(f"DELETE FROM m_SurveyTargets WHERE id = {int(target)}")
        reload_dialplan()


# --------------------------------------------------------------------------- running

def hang_up_all():
    for name in [*callsim.PHONES, "carrier"]:
        try:
            Line(name).hangup()
        except Exception:  # noqa: BLE001 - best effort between scenarios
            pass


def run(names):
    if not callsim.STATE_FILE.exists():
        sys.exit("Run `callsim.py setup` first.")
    unknown = set(names) - {s["name"] for s in SCENARIOS}
    if unknown:
        sys.exit(f"Unknown scenario(s): {', '.join(sorted(unknown))}")
    chosen = [s for s in SCENARIOS if not names or s["name"] in names]
    failed = []
    with callsim_routes_in_hours():
        probe = Probe()
        for s in chosen:
            print(f"{s['name']}: {s['description']}")
            checks = Checks()
            try:
                s["run"](probe, checks)
            except Exception as e:  # noqa: BLE001 - report and carry on with the next scenario
                checks.that(False, f"error: {e}")
            hang_up_all()
            pause(3)
            for ok, what in checks.items:
                print(f"  {'PASS' if ok else 'FAIL'}  {what}")
            if not checks.ok:
                failed.append(s["name"])
    print(f"\n{len(chosen) - len(failed)} of {len(chosen)} scenarios passed.")
    if failed:
        print("Failed: " + ", ".join(failed))
        sys.exit(1)


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "run":
        run(sys.argv[2:])
    elif cmd == "list":
        for s in SCENARIOS:
            print(f"{s['name']:24} {s['description']}")
    else:
        print(__doc__)


if __name__ == "__main__":
    main()

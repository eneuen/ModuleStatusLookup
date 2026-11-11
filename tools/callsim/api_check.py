#!/usr/bin/env python3
"""Status Lookup REST API check: the module's REST resources on a test PBX.

Creates two API keys (one that may read records, one that may write them
but not replace them all), and checks what each may do, and what an admin
login and a script on the PBX itself (localhost) may do. The keys are
deleted again at the end.

  api_check.py

Needs `callsim.py setup` (one request comes from CallSim's phone
container), the same environment variables as callsim.py, and the module
installed and enabled. It adds, changes and deletes records with IDs
starting 7700, and a status APICHECK, so run it on a test PBX only.
"""
import json
import ssl
import sys
import urllib.error
import urllib.request

from callsim import PBX_CONTAINER, PBX_URL, Pbx, docker, load_state

BASE = "/pbxcore/api/v3/module-status-lookup"
MODULE_DB = "/storage/usbdisk1/mikopbx/custom_modules/ModuleStatusLookup/db/module.db"
TLS = ssl.create_default_context()
TLS.check_hostname = False
TLS.verify_mode = ssl.CERT_NONE


def call(method, path, data=None, token=None):
    """HTTP status and decoded body, from outside the PBX."""
    headers = {"Accept": "application/json"}
    body = None
    if data is not None:
        body = json.dumps(data).encode()
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = f"Bearer {token}"
    req = urllib.request.Request(PBX_URL + BASE + path, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, context=TLS, timeout=150) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw or b"{}")
        except ValueError:
            return e.code, {"raw": raw[:200].decode(errors="replace")}


def local(method, path, data=None):
    """HTTP status and body for a request made on the PBX itself (localhost, no key)."""
    args = ["exec", PBX_CONTAINER, "curl", "-s", "-o", "/dev/stdout", "-w", "\n%{http_code}", "-X", method]
    if data is not None:
        args += ["-H", "Content-Type: application/json", "-d", json.dumps(data)]
    out = docker(*args, "http://127.0.0.1" + BASE + path, capture=True)
    body, _, status = out.rpartition("\n")
    return int(status), json.loads(body or "{}")


def remote_status(path):
    """HTTP status of a request without a key from CallSim's phone container: another machine on the network.
    (A test PBX reached through a local port forward may see the host's own requests as localhost.)"""
    script = ("import ssl,sys,urllib.request,urllib.error\n"
              "try:\n print(urllib.request.urlopen(sys.argv[1],context=ssl._create_unverified_context(),timeout=20).status)\n"
              "except urllib.error.HTTPError as e:\n print(e.code)\n")
    url = f"https://{load_state()['pbx_ip']}{BASE}{path}"
    return int(docker("exec", "callsim-phones", "python3", "-c", script, url, capture=True).strip())


def sql(statement):
    return docker("exec", PBX_CONTAINER, "sqlite3", MODULE_DB, statement, capture=True).strip()


class Checks:
    def __init__(self):
        self.failed = 0

    def that(self, ok, what):
        print(f"  {'PASS' if ok else 'FAIL'}  {what}")
        self.failed += 0 if ok else 1


def make_key(pbx, description, paths):
    key = pbx.post("/api-keys:generateKey", {})["data"]["key"]
    res = pbx.post("/api-keys", {"description": description, "key": key, "full_permissions": False,
                                 "networkfilterid": "none", "allowed_paths": paths})
    return int(res["data"]["id"]), key


def main():
    pbx = Pbx()
    c = Checks()
    sql("DELETE FROM m_StatusLookupRecords WHERE lookup_id LIKE '7700%'; "
        "INSERT OR IGNORE INTO m_StatusLookupStatuses (code, name) VALUES ('APICHECK', 'API check')")
    keys = []
    try:
        read_id, read_key = make_key(pbx, "Status Lookup check: read", {"/api/v3/module-status-lookup/records": "read"})
        keys.append(read_id)
        write_id, write_key = make_key(pbx, "Status Lookup check: write", {
            "/api/v3/module-status-lookup/records": "write", "/api/v3/module-status-lookup/statuses": "read"})
        keys.append(write_id)

        print("Write key")
        status, body = call("PUT", "/records/770001", {"status_code": "APICHECK", "note": "Update the account; select from menu", "pin": "4821"}, write_key)
        c.that(status == 201, f"PUT a new record: 201 ({status} {body.get('messages')})")
        rec = body.get("data", {})
        c.that(rec.get("updated_by") == "API key: Status Lookup check: write" and rec.get("source") == "api", f"marked as the key's change ({rec.get('updated_by')})")
        c.that(rec.get("note") == "Update the account; select from menu", "a note that looks like SQL is stored as it is")
        c.that(rec.get("has_pin") is True and "pin" not in rec and "pin_hash" not in rec, "the PIN is set and never sent back")
        status, body = call("PUT", "/records/770001", {"note": ""}, write_key)
        c.that(status == 200 and body["data"]["status_code"] == "APICHECK" and body["data"]["note"] == "", f"PUT again: 200, only the note changed ({status})")
        status, body = call("PUT", "/records/007700", {"status_code": "APICHECK"}, write_key)
        c.that(status == 201 and body["data"]["lookup_id"] == "007700", "leading zeros are kept")
        status, body = call("PUT", "/records/770002", {"status_code": "APICHECK", "colour": "red"}, write_key)
        c.that(status == 400 and body.get("data", {}).get("error") == "unknown_fields", f"an unknown field is refused ({status} {body.get('data')})")
        status, body = call("PUT", "/records/770002", {"note": "x"}, write_key)
        c.that(status == 400 and body.get("data", {}).get("error") == "status_required", "a new record needs a status")
        status, body = call("GET", "/statuses", token=write_key)
        c.that(status == 200 and "APICHECK" in [s["code"] for s in body["data"]["statuses"]], "statuses are listed")
        status, body = call("POST", "/records:import", {"csv": "lookup_id,status_code\r\n770003,APICHECK\r\n770004,NOPE\r\n", "dryRun": True}, write_key)
        c.that(status == 200 and body["data"]["added"] == 1 and body["data"]["rejected"] == 1 and sql("SELECT COUNT(*) FROM m_StatusLookupRecords WHERE lookup_id = '770003'") == "0",
               f"import dry run changes nothing ({status} {body.get('data', {}).get('added')})")
        status, body = call("POST", "/records:import", {"csv": "lookup_id,status_code\r\n770003,APICHECK\r\n"}, write_key)
        c.that(status == 200 and body["data"]["added"] == 1, "import adds the record")
        status, _ = call("POST", "/records-replace:import", {"csv": "lookup_id,status_code\r\n770003,APICHECK\r\n", "dryRun": True}, write_key)
        c.that(status == 401, f"replace all is refused without its own grant ({status})")
        status, _ = call("GET", "/stats?from=2026-11-01&to=2026-11-12", token=write_key)
        c.that(status == 401, f"stats aren't granted to this key ({status})")
        status, _ = call("POST", "/records", {"status_code": "APICHECK"}, write_key)
        c.that(status in (404, 405), f"POST /records isn't offered ({status})")

        print("Read key")
        status, body = call("GET", "/records/770001", token=read_key)
        c.that(status == 200 and body["data"]["status_code"] == "APICHECK", f"GET one record ({status})")
        status, body = call("GET", "/records?search=7700", token=read_key)
        # 770001 and 770003; 007700 doesn't start with 7700.
        c.that(status == 200 and body["data"]["pagination"]["total"] == 2, f"search finds IDs by their start ({body.get('data', {}).get('pagination')})")
        status, _ = call("GET", "/records/779999", token=read_key)
        c.that(status == 404, f"GET a missing record: 404 ({status})")
        status, _ = call("PUT", "/records/770001", {"status_code": "APICHECK"}, read_key)
        c.that(status == 401, f"PUT with a read key is refused ({status})")
        status, _ = call("DELETE", "/records/770001", token=read_key)
        c.that(status == 401, f"DELETE with a read key is refused ({status})")

        print("No key, admin login, the PBX itself")
        status = remote_status("/records")
        c.that(status == 401, f"no key, from another machine: 401 ({status})")
        status, _ = call("GET", "/records", token="not-a-key-" + "x" * 40)
        c.that(status == 401, f"a wrong key: 401 ({status})")
        status, body = call("PUT", "/records/770005", {"status_code": "APICHECK"}, pbx.token)
        c.that(status == 201 and body["data"]["updated_by"] == "admin", f"an admin login may write, as admin ({body.get('data', {}).get('updated_by')})")
        status, body = local("GET", "/stats?from=2026-11-01&to=2026-11-12")
        c.that(status == 200 and "totals" in body.get("data", {}) and "notFound" not in body["data"], f"localhost reads the totals, without the IDs callers typed ({status})")
        status, body = local("PUT", "/records/770006", {"status_code": "APICHECK"})
        c.that(status == 201 and body["data"]["updated_by"] == "PBX", f"localhost writes, as PBX ({status})")
        status, body = local("POST", "/records-replace:import", {"csv": "lookup_id,status_code\r\n770001,APICHECK\r\n", "dryRun": True})
        c.that(status == 200 and body["data"]["deleted"] >= 4 and body["data"]["dry_run"] is True, f"replace all dry run counts the deletions ({body.get('data', {}).get('deleted')})")

        print("Clean-up")
        status, _ = call("DELETE", "/records/770001", token=write_key)
        c.that(status == 200, f"DELETE with the write key ({status})")
        status, _ = call("DELETE", "/records/770001", token=write_key)
        c.that(status == 404, f"DELETE again: 404 ({status})")
    finally:
        for key_id in keys:
            try:
                pbx.delete(f"/api-keys/{key_id}")
            except RuntimeError as e:
                print(f"  could not delete API key {key_id}: {e}")
        sql("DELETE FROM m_StatusLookupRecords WHERE lookup_id LIKE '7700%' OR lookup_id = '007700'; "
            "DELETE FROM m_StatusLookupStatuses WHERE code = 'APICHECK'; DELETE FROM m_StatusLookupImports WHERE file_name = 'API'")
    print("\nAll checks passed." if not c.failed else f"\n{c.failed} check(s) failed.")
    sys.exit(1 if c.failed else 0)


if __name__ == "__main__":
    main()

# Load test

`run.py` checks that the lookup line keeps answering while large imports run: it keeps a Replace All import of 100,000 records running through the REST API, one after another, and places 20 calls to the line at once, each typing an ID that is in every import. It passes when every import succeeds, every call hears its status within a second of typing the ID, and every call is logged once.

```bash
python3 tools/callsim/callsim.py setup      # once: the probe's PBX objects (see tools/callsim)
python3 tools/loadtest/run.py               # --calls 20 --rows 100000 by default
```

The calls are placed inside the PBX through the Asterisk manager interface, with a temporary dialplan helper that sends the ID's keys, so no phones are needed. The script replaces the module's records and statuses with the probe's, so run it on a test PBX only.

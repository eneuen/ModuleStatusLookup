# CallSim: real-call checks for Status Lookup

CallSim places real SIP calls through a disposable MikoPBX test instance, so the lookup line is checked the way callers meet it: what they hear, what happens when they press keys, which phone rings next, and what the module logs. `probe.py` runs the scenarios.

> Use a throwaway test PBX only. CallSim creates extensions 301-305, a queue, an IVR menu, a conference room, a SIP provider and routes, all named "CallSim ...". The probe adds the inbound DID 5551091 to the lookup line, and replaces the module's call flow, statuses and records with its own test set on every run.

## How it works

- **Phones:** a container on a private Docker network runs one [baresip](https://github.com/baresip/baresip) softphone per extension (301-305). The softphones register with the PBX and are driven over baresip's `ctrl_tcp` control port.
- **Carrier:** a second container, on its own IP address, plays the phone company. The PBX knows it as a SIP provider without registration, matched by that IP. It places inbound calls to any DID and sends keypad digits as RFC 2833 DTMF.
- **Driver:** `callsim.py` creates the PBX objects through the REST API and starts the containers. `probe.py` writes the probe's call flow, statuses and records into the module's database inside the PBX container, asks the PBX to reload its dialplan, places the calls, and checks the `SLPROBE` lines the lookup's dialplan writes to Asterisk's verbose log at every decision, and the call log the module's worker writes.

Test layout on the PBX:

| Object | Number | Behaviour |
| --- | --- | --- |
| Extensions | 301-305 | Plain extensions; in the probe, 301 is the operator, 304 the fault destination and 305 a status's own destination |
| Queue | 3100 | Rings 302 and 303 together; the probe's too-many-tries destination |
| DID 5551001 | from the carrier | Goes straight to 301 |
| DID 5551091 | from the carrier | The probe's own route, to the lookup line |
| Sound file 2 | the PBX's default greeting | Every probe status plays it: a 16-second sentence, long enough to press keys during it |

**Out-of-hours rules.** MikoPBX sends inbound calls to its out-of-hours handling outside working hours, for every inbound route unless a rule is restricted to some routes. So that `run` works at any time, the probe restricts each rule to the PBX's *other* inbound routes for the length of the run and puts the rules back afterwards. The originals are saved in the state file first, and `teardown` restores them if a run was interrupted.

## Requirements

- Docker, and a MikoPBX test container with host networking. CallSim creates a Docker network called `callsim`, lets Docker choose its addresses, and reaches the PBX at that network's gateway. If your PBX is reachable at another address from that network, set `CALLSIM_PBX_SIP_HOST` to it.
- The module installed and enabled on that PBX.
- Python 3.10 or later on the machine that runs the scripts (standard library only).

## Usage

```bash
export MIKOPBX_ADMIN_PASSWORD='<test PBX admin password>'
export MIKOPBX_URL=https://localhost        # default
export MIKOPBX_CONTAINER=mikopbx-dev       # default

python3 tools/callsim/callsim.py setup      # objects, network, phones; waits for registration
python3 tools/callsim/probe.py list         # scenarios
python3 tools/callsim/probe.py run          # all scenarios, or name some
python3 tools/callsim/callsim.py teardown   # remove everything CallSim created
```

`setup` generates new SIP passwords each time and keeps them in `.callsim-state.json` next to the script. That file is local and never committed.

A run prints PASS or FAIL for every check and ends with a summary; it exits with status 1 if any scenario failed. Run all scenarios before a release.

If the phones don't register during `setup`, they may have tried before the PBX loaded their new passwords and been banned by the PBX's fail2ban. Unban the phones' address (the PBX's firewall page, or `firewall:unbanIp` in its REST API) and they register on their next attempt. `setup` deletes and recreates CallSim's objects; if the PBX refuses to delete one because a module still uses it, disable that module for the length of `setup`.

## Scenarios

| Scenario | Checks |
| --- | --- |
| `valid_id` | A valid ID with #: the status plays, then the repeat menu; no key, so goodbye and hang-up |
| `leading_zeros_no_hash` | An ID with leading zeros, typed without #, is found after the digit timeout; its date is spoken as weekday, month and day |
| `not_found_three_times` | Three unknown IDs, then the too-many-tries destination rings |
| `zero_first` | 0 at the first prompt reaches the operator without a lookup |
| `zero_during_status` | 0 in the middle of the status sentence cuts it short and reaches the operator |
| `expired_record` | An expired record is heard as not found; 0 then reaches the operator |
| `transfer_status` | A status with a transfer after-action sends the caller straight to its destination |
| `deleted_destination` | A status destination that no longer exists sends the caller to the fault destination |
| `hang_up_in_prompt` | A caller who hangs up during the prompt is still logged, as abandoned |
| `short_route_timeout` | With a 15 s route timeout, the caller is still in the lookup after 20 s |
| `no_fall_through` | After goodbye the call ends: no fall-through to the route's default action, no phone rings |
| `transfer_from_phone` | A phone blind-transfers a caller to the lookup line, where they look up an ID |
| `miss_limit` | After two misses (limit 2), the caller's next call goes straight to the operator |
| `inactive` | A line that isn't switched on apologises and hangs up |

Every scenario that completes a call also checks the one call-log row the worker wrote for it.

## Files

| File | Purpose |
| --- | --- |
| `callsim.py` | Driver: setup and teardown of the phones, carrier and PBX objects; call and call-record helpers |
| `probe.py` | The scenarios |
| `ctl.py` | Small client for baresip's netstring/JSON control protocol |
| `phone.py` | Container entry point: starts one baresip per account |
| `Dockerfile` | Debian image with baresip and Python |

## Notes

- baresip's built-in tone generator only produces 48 kHz stereo, so the audio settings in `phone.py` use that and baresip resamples to the 8 kHz call codec.
- Each baresip binds TLS on its SIP port + 1, so the phones' SIP ports are 10 apart.
- The checks look at signalling and the PBX's logs (what was played, which phone rang), not at audio.
- A key pressed while the ID is being read back is lost: Asterisk's `SayDigits` doesn't listen for keys.

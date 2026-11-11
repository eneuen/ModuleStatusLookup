"""The module page's write actions, called as the page calls them: AJAX POST with the admin's Bearer token."""
import json
import urllib.error
import urllib.parse
import urllib.request

from callsim import PBX_URL

MODULE = "module-status-lookup"


def post(pbx, action, data):
    """Posts form fields to a page action; returns the JSON body (also for a refusal)."""
    req = urllib.request.Request(f"{PBX_URL}/admin-cabinet/{MODULE}/{action}", data=urllib.parse.urlencode(data).encode(), method="POST",
                                 headers={"Authorization": f"Bearer {pbx.token}", "X-Requested-With": "XMLHttpRequest",
                                          "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded"})
    try:
        with urllib.request.urlopen(req, context=pbx.tls, timeout=60) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        return json.loads(e.read() or b"{}")

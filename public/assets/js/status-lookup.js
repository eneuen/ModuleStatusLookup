/* Status Lookup for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */
(() => {
  // public/assets/js/src/status-lookup.js
  var BASE = `${globalRootUrl}module-status-lookup/`;
  var t = (key, fallback) => globalTranslate && key in globalTranslate ? globalTranslate[key] : fallback;
  var $id = (id) => document.getElementById(id);
  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === void 0 || v === null || v === false) return;
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
      else if (v === true) node.setAttribute(k, "");
      else node.setAttribute(k, v);
    });
    children.flat().forEach((c) => {
      if (c !== null && c !== void 0 && c !== false) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    });
    return node;
  }
  var fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c !== null && c !== void 0 && c !== false));
  var LOCALE = document.documentElement.lang || void 0;
  var count = (n) => Number(n).toLocaleString(LOCALE);
  var sub = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.split(`{${k}}`).join(String(v)), text);
  function plural(key, n, fallbacks, values = {}) {
    const form = new Intl.PluralRules(LOCALE).select(n);
    const text = globalTranslate && (globalTranslate[`${key}_${form}`] || globalTranslate[`${key}_other`]) || fallbacks[form] || fallbacks.other;
    return sub(text, { n: count(n), ...values });
  }
  var state = { tab: null, options: null, callFlow: null, dirty: false };
  var FIELDS = {
    welcome_prompt_id: ["sl_PromptWelcome", "Welcome"],
    enter_id_prompt_id: ["sl_PromptEnterId", "Ask for the ID"],
    invalid_prompt_id: ["sl_PromptInvalid", "Invalid Entry"],
    not_found_prompt_id: ["sl_PromptNotFound", "Not Found"],
    repeat_prompt_id: ["sl_PromptRepeat", "Repeat Menu"],
    goodbye_prompt_id: ["sl_PromptGoodbye", "Goodbye"],
    transfer_prompt_id: ["sl_PromptTransfer", "Putting Through"],
    fault_prompt_id: ["sl_PromptFault", "Apology"],
    id_min_length: ["sl_IdMinLength", "Shortest ID"],
    id_max_length: ["sl_IdMaxLength", "Longest ID"],
    digit_timeout: ["sl_DigitTimeout", "Wait for Keys"],
    max_tries: ["sl_MaxTries", "Tries"],
    miss_limit_caller: ["sl_MissLimitCaller", "Per Caller Number"],
    miss_limit_total: ["sl_MissLimitTotal", "All Callers Together"],
    operator_number: ["sl_Operator", "Operator"],
    too_many_tries_number: ["sl_TooManyTries", "After the Last Try"],
    fault_number: ["sl_FaultDestination", "When a Lookup Fails"],
    status_date: ["sl_ColDate", "Date"],
    expires_on: ["sl_ColLastDay", "Last Day"]
  };
  var fieldName = (f) => FIELDS[f] ? t(FIELDS[f][0], FIELDS[f][1]) : f;
  var PROBLEMS = {
    no_number: ["sl_Problem_no_number", "It has no internal number."],
    not_registered: ["sl_Problem_not_registered", "Its number belongs to something else in the PBX. Choose another number."],
    no_operator: ["sl_Problem_no_operator", "Choose where 0 sends callers (the operator)."],
    gone_too_many_tries_number: ["sl_Problem_gone_too_many", "The destination after the last try no longer exists."],
    gone_fault_number: ["sl_Problem_gone_fault", "The destination for failed lookups no longer exists."]
  };
  function problemText(code) {
    if (PROBLEMS[code]) return t(PROBLEMS[code][0], PROBLEMS[code][1]);
    const prompt = code.startsWith("prompt_") ? `${code.slice(7)}_prompt_id` : null;
    if (prompt && FIELDS[prompt]) {
      return sub(t("sl_Problem_prompt", `The prompt "{prompt}" can't be played. Choose another one.`), { prompt: fieldName(prompt) });
    }
    return code;
  }
  var ERRORS = {
    bad_request: ["sl_Err_bad_request", "The page sent a request the server couldn't read. Reload the page and try again."],
    failed: ["sl_Err_failed", "Something went wrong. Try again; if it keeps happening, check the PBX's system log."],
    refused: ["sl_Err_refused", "The PBX refused this change. Its system log says why."],
    prompt: ["sl_Err_prompt", `The prompt for "{field}" can't be played. Choose another one.`],
    range: ["sl_Err_range", 'Enter a whole number from {min} to {max} for "{field}".'],
    whole_number: ["sl_Err_whole_number", 'Enter a whole number for "{field}".'],
    destination: ["sl_Err_destination", 'Choose "{field}" from the list.'],
    lengths: ["sl_Err_lengths", "The shortest ID can't be longer than the longest."],
    not_ready: ["sl_Err_not_ready", "The line can't be switched on yet: {problems}"],
    number_format: ["sl_Err_number_format", "Enter 2 to 8 digits, not starting with 0."],
    number_taken: ["sl_Err_number_taken", "{number} is already used in the PBX. Choose another number."],
    lookup_id: ["sl_Err_lookup_id", "Enter the ID as {min} to {max} digits, as callers type it (the lengths are set on the Call Flow tab)."],
    lookup_id_taken: ["sl_Err_lookup_id_taken", "There is already a record with the ID {id}."],
    record_status: ["sl_Err_record_status", "Choose a status from the list."],
    record_gone: ["sl_Err_record_gone", "This record no longer exists. Reload the page."],
    note: ["sl_Err_note", "Keep the note to {max} characters."],
    date: ["sl_Err_date", 'Enter a valid date for "{field}".'],
    bulk: ["sl_Err_bulk", "Choose 1 to {max} records."],
    status_gone: ["sl_Err_status_gone", "This status no longer exists. Reload the page."],
    code_format: ["sl_Err_code_format", "Use 1 to 32 capital letters, digits and _ for the code, such as READY."],
    code_taken: ["sl_Err_code_taken", "Another status already has the code {code}."],
    name: ["sl_Err_name", "Enter a name of up to {max} characters."],
    status_prompt: ["sl_Err_status_prompt", "Choose the sound file with the sentence the caller hears."],
    status_destination: ["sl_Err_status_destination", "Choose where to put the caller through to."],
    too_many_statuses: ["sl_Err_too_many_statuses", "Up to {max} statuses."],
    move_to: ["sl_Err_move_to", "Choose another status to move the records to."],
    status_in_use: ["sl_Err_status_in_use", "Records still have this status. Choose a status to move them to."],
    number_in_use: ["sl_Err_number_in_use", 'Callers are still sent to {number} (see "Where Callers Come From"). Point those elsewhere first, then change the number.']
  };
  function failText(xhr, fallback) {
    const body = xhr && xhr.responseJSON;
    const code = body && typeof body.message === "string" ? body.message : "";
    if (!ERRORS[code]) return fallback;
    const params = { ...body.params || {} };
    if (params.field) params.field = fieldName(params.field);
    if (params.problems) params.problems = params.problems.split(",").map(problemText).join(" ");
    return sub(t(ERRORS[code][0], ERRORS[code][1]), params);
  }
  function showError(text) {
    const box = $id("sl-error");
    box.textContent = text;
    box.classList.remove("sl-hidden");
    box.scrollIntoView({ block: "nearest" });
  }
  var clearError = () => $id("sl-error").classList.add("sl-hidden");
  var saveFailed = (xhr) => failText(xhr, t("sl_SaveFailed", "The change could not be saved."));
  function get(action, data = {}) {
    return $.ajax({ url: BASE + action, method: "GET", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })).fail((xhr) => showError(failText(xhr, t("sl_LoadFailed", "Status Lookup could not load this data."))));
  }
  function post(action, data) {
    clearError();
    return $.ajax({ url: BASE + action, method: "POST", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r }));
  }
  function select(id, options, value, attrs = {}) {
    const option = ([v, text, extra = {}]) => el("option", { value: String(v), selected: String(v) === String(value != null ? value : ""), ...extra }, text);
    return el("select", { id, ...attrs }, options.map((o) => Array.isArray(o) ? option(o) : el("optgroup", { label: o.group }, o.options.map(option))));
  }
  function field(labelText, control, hint, width) {
    const target = control.classList.contains("sl-prompt-control") ? control.querySelector("select") : control;
    return el(
      "div",
      { class: `${width ? `${width} wide ` : ""}field` },
      el("label", { for: target.id, text: labelText }),
      control,
      hint ? el("div", { class: "sl-note", text: hint }) : null
    );
  }
  function numberInput(id, value, min, max) {
    return el("input", { type: "number", id, value: String(value), min: String(min), max: String(max), step: "1" });
  }
  var section = (title, hint, ...content) => el(
    "div",
    { class: "sl-settings-section" },
    el("h4", { class: "ui header", text: title }),
    hint ? el("div", { class: "sl-note", text: hint }) : null,
    ...content
  );
  function confirmDialog(title, text, okText, extra = null) {
    return new Promise((resolve) => {
      $id("sl-confirm-title").textContent = title;
      $id("sl-confirm-text").textContent = text;
      fill($id("sl-confirm-extra"), extra);
      $id("sl-confirm-ok").textContent = okText;
      let answer = false;
      $("#sl-confirm-modal").modal({
        closable: true,
        onHidden: () => resolve(answer)
      }).modal("show");
      $id("sl-confirm-ok").onclick = () => {
        answer = true;
        $("#sl-confirm-modal").modal("hide");
      };
      $id("sl-confirm-cancel").onclick = () => $("#sl-confirm-modal").modal("hide");
    });
  }
  var preview = { audio: null, url: null, button: null, request: 0 };
  function setPlaying(button, playing) {
    const icon = button.querySelector("i");
    if (icon) icon.className = `${playing ? "stop" : "play"} icon`;
    const text = playing ? t("sl_StopPreview", "Stop") : t("sl_Listen", "Listen");
    button.title = text;
    button.setAttribute("aria-label", text);
    button.classList.toggle("sl-playing", playing);
  }
  function stopPreview() {
    preview.request += 1;
    if (preview.audio) preview.audio.pause();
    if (preview.url) URL.revokeObjectURL(preview.url);
    if (preview.button) setPlaying(preview.button, false);
    Object.assign(preview, { audio: null, url: null, button: null });
  }
  async function fetchAudio(path, retry = true) {
    const headers = { "X-Requested-With": "XMLHttpRequest" };
    const tokens = typeof TokenManager === "undefined" ? null : TokenManager;
    if (tokens && tokens.accessToken) headers.Authorization = `Bearer ${tokens.accessToken}`;
    const response = await fetch(`${window.location.origin}/pbxcore/api/v3/sound-files:playback?view=${encodeURIComponent(path)}`, { headers });
    if (response.status === 401 && retry && tokens && tokens.silentRefresh) {
      await tokens.silentRefresh();
      return fetchAudio(path, false);
    }
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.blob();
  }
  function togglePreview(path, button) {
    const again = preview.button === button;
    stopPreview();
    if (again) return;
    if (!path) {
      showError(t("sl_PreviewNothing", "Choose a prompt to listen to it."));
      return;
    }
    clearError();
    const request = preview.request;
    preview.button = button;
    setPlaying(button, true);
    fetchAudio(path).then((blob) => {
      if (request !== preview.request) return null;
      preview.url = URL.createObjectURL(blob);
      preview.audio = new Audio(preview.url);
      preview.audio.addEventListener("ended", () => {
        if (request === preview.request) stopPreview();
      });
      return preview.audio.play();
    }).catch(() => {
      if (request !== preview.request) return;
      stopPreview();
      showError(t("sl_PreviewFailed", "This prompt can't be played. Its file may be missing, or still being converted after the module was enabled."));
    });
  }
  var BUILT_IN_NAMES = {
    welcome: ["sl_BuiltIn_welcome", "Built-In: Thank You for Calling"],
    "enter-id": ["sl_BuiltIn_enter-id", "Built-In: Enter Your Reference Number"],
    "enter-pin": ["sl_BuiltIn_enter-pin", "Built-In: Enter Your PIN"],
    invalid: ["sl_BuiltIn_invalid", "Built-In: Not a Valid Number"],
    "not-found": ["sl_BuiltIn_not-found", "Built-In: We Could Not Find That Number"],
    repeat: ["sl_BuiltIn_repeat", "Built-In: 1 to Hear Again, 0 for Someone"],
    goodbye: ["sl_BuiltIn_goodbye", "Built-In: Goodbye"],
    transfer: ["sl_BuiltIn_transfer", "Built-In: Putting You Through"],
    fault: ["sl_BuiltIn_fault", "Built-In: We Can't Check Right Now"]
  };
  function promptFile(id) {
    const n = Number(id);
    if (!id || Number.isNaN(n)) return null;
    if (n < 0) {
      const builtIn = state.options.builtInPrompts.find((p) => p.id === n);
      return builtIn && builtIn.available ? builtIn.preview[state.options.language] || null : null;
    }
    const file = state.options.soundFiles.find((f) => f.id === n);
    return file && file.path ? file.path : null;
  }
  function promptOptions(withNone, builtInKeys) {
    const builtIn = state.options.builtInPrompts.filter((p) => builtInKeys.includes(p.key) && p.available).map((p) => [p.id, t(BUILT_IN_NAMES[p.key][0], BUILT_IN_NAMES[p.key][1])]);
    const files = state.options.soundFiles.map((f) => [f.id, f.name]);
    return [
      ...withNone ? [["0", t("sl_NoPrompt", "None")]] : [],
      ...builtIn.length ? [{ group: t("sl_BuiltInPrompts", "Built-In Prompts"), options: builtIn }] : [],
      ...files.length ? [{ group: t("sl_SoundFiles", "Sound Files"), options: files }] : []
    ];
  }
  function promptControl(id, value, withNone, builtInKeys) {
    const menu = select(id, promptOptions(withNone, builtInKeys), value, { class: "ui search dropdown sl-prompt-select" });
    const listen = el("button", {
      class: "ui basic icon button sl-listen",
      type: "button",
      onclick: () => togglePreview(promptFile(menu.value), listen)
    }, el("i", { class: "play icon" }));
    setPlaying(listen, false);
    return el("div", { class: "sl-prompt-control" }, menu, listen);
  }
  function initPromptMenus(root) {
    root.querySelectorAll("select.sl-prompt-select:not(.sl-ready)").forEach((menu) => {
      menu.classList.add("sl-ready");
      $(menu).dropdown({ fullTextSearch: true, placeholder: false, match: "text" });
      const dropdown = menu.closest(".ui.dropdown");
      if (!dropdown) return;
      dropdown.querySelectorAll(".menu .item").forEach((item) => {
        const value = item.dataset.value;
        if (!value || value === "0") return;
        const play = el("span", { class: "sl-item-play", role: "button", tabindex: "-1" }, el("i", { class: "play icon" }));
        setPlaying(play, false);
        ["mousedown", "mouseup", "touchstart"].forEach((type) => play.addEventListener(type, (e) => e.stopPropagation()));
        play.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          togglePreview(promptFile(value), play);
        });
        item.append(play);
      });
    });
  }
  function destinationOptions(emptyText) {
    return [["", emptyText], ...state.options.destinations.map((d) => [d.value, d.name])];
  }
  var PROMPTS = [
    ["welcome_prompt_id", ["welcome"], true, ["sl_PromptWelcomeHint", "Played once, as the call is answered. Callers can start typing during it."]],
    ["enter_id_prompt_id", ["enter-id"], false, ["sl_PromptEnterIdHint", "Asks for the ID and says that 0 reaches someone."]],
    ["invalid_prompt_id", ["invalid"], false, ["sl_PromptInvalidHint", "After an entry that is too short, too long or empty."]],
    ["not_found_prompt_id", ["not-found"], false, ["sl_PromptNotFoundHint", "After an ID that isn't found or has expired."]],
    ["repeat_prompt_id", ["repeat"], false, ["sl_PromptRepeatHint", "After a status: 1 to hear it again, 0 for someone."]],
    ["goodbye_prompt_id", ["goodbye"], false, null],
    ["transfer_prompt_id", ["transfer"], false, ["sl_PromptTransferHint", "Before a caller is put through to a person."]],
    ["fault_prompt_id", ["fault"], false, ["sl_PromptFaultHint", "When a lookup can't be done; the caller is then put through."]]
  ];
  var NUMBER_FIELDS = [
    ["id_min_length", 2, 20, null],
    ["id_max_length", 2, 20, null],
    ["digit_timeout", 1, 30, ["sl_DigitTimeoutHint", "Seconds to wait for the first key and between keys. Callers end the ID with #, or wait this long."]],
    ["max_tries", 1, 9, ["sl_MaxTriesHint", "Wrong or empty entries a caller gets before they are put through."]]
  ];
  var USER_KINDS = {
    route: ["sl_UsedByRoute", 'Incoming route "{name}"'],
    ivr: ["sl_UsedByIvr", 'IVR menu "{name}"'],
    offwork: ["sl_UsedByOffWork", 'Off-work time rule "{name}"']
  };
  function lineState(data) {
    const s = data.settings;
    const number = s.internal_number;
    const on = data.answering;
    const switchedOn = s.active === "1";
    const title = on ? sub(t("sl_StateOn", "Answering on {number}"), { number }) : t("sl_StateOff", "Not answering");
    const lines = [];
    if (!on) {
      lines.push(el("div", { text: switchedOn ? t("sl_StateOffProblems", "The line is switched on, but can't answer until this is fixed:") : t("sl_StateOffSwitched", "The line is switched off. Calls to it aren't answered; an incoming route moves on to its next rule or default action.") }));
      if (data.problems.length) lines.push(el("ul", {}, data.problems.map((p) => el("li", { text: problemText(p) }))));
    }
    const toggle = el("button", {
      class: `ui ${switchedOn ? "basic" : "positive"} button`,
      type: "button",
      id: "sl-toggle",
      disabled: !switchedOn && data.problems.length > 0,
      onclick: () => toggleLine(!switchedOn)
    }, switchedOn ? t("sl_SwitchOff", "Switch Off") : t("sl_SwitchOn", "Switch On"));
    return el(
      "div",
      { class: `sl-state ${on ? "sl-on" : "sl-off"}` },
      el("div", {}, el("div", { class: "sl-state-title", text: title }), lines),
      toggle
    );
  }
  function usedBy(data) {
    const number = data.settings.internal_number;
    if (!number) return null;
    if (!data.usedBy.length) {
      return el("div", { class: "sl-note", text: sub(t("sl_UsedByNone", 'Nothing sends callers to {number} yet. Choose it as the destination of an incoming route or an IVR menu option ("Module: Status Lookup ({number})"). Staff can also dial it, or transfer callers to it.'), { number }) });
    }
    return el(
      "div",
      {},
      el("div", { class: "sl-note", text: t("sl_UsedByIntro", "Callers reach the line from:") }),
      el("ul", { class: "sl-users" }, data.usedBy.map((u) => el("li", {
        text: sub(t(USER_KINDS[u.kind][0], USER_KINDS[u.kind][1]), { name: u.name || "\u2014" }) + (u.detail ? ` (${u.detail})` : "")
      })))
    );
  }
  function renderCallFlow(data) {
    state.callFlow = data;
    state.dirty = false;
    const s = data.settings;
    const box = $id("sl-callflow");
    const numberForm = el("div", { class: "ui form" }, el(
      "div",
      { class: "sl-inline" },
      field(t("sl_Number", "Internal Number"), el("input", { type: "text", id: "sl-number", value: s.internal_number, inputmode: "numeric", maxlength: "8", size: "10" })),
      el("button", { class: "ui button", type: "button", onclick: changeNumber }, t("sl_ChangeNumber", "Change Number"))
    ));
    const form = el(
      "form",
      { class: "ui form", id: "sl-callflow-form", onsubmit: (e) => {
        e.preventDefault();
        saveCallFlow();
      } },
      section(
        t("sl_SectionPrompts", "What Callers Hear"),
        t("sl_SectionPromptsHint", "The built-in prompts work for any business. To use your own wording, upload a recording on the PBX's Sound files page and choose it here."),
        PROMPTS.map(([f, keys, withNone, hint]) => field(fieldName(f), promptControl(`sl-${f}`, s[f], withNone, keys), hint ? t(hint[0], hint[1]) : null))
      ),
      section(
        t("sl_SectionInput", "How Callers Type"),
        null,
        el("div", { class: "two fields" }, NUMBER_FIELDS.slice(0, 2).map(([f, min, max]) => field(fieldName(f), numberInput(`sl-${f}`, s[f], min, max)))),
        el("div", { class: "sl-note", text: t("sl_IdLengthHint", "IDs are 2 to 20 digits. Leading zeros count: 00451 and 451 are different IDs.") }),
        el("div", { class: "two fields" }, NUMBER_FIELDS.slice(2).map(([f, min, max, hint]) => field(fieldName(f), numberInput(`sl-${f}`, s[f], min, max), t(hint[0], hint[1]))))
      ),
      section(
        t("sl_SectionDestinations", "Where Callers Go"),
        t("sl_SectionDestinationsHint", "Every way out of the lookup leads to a person. A status can also send callers somewhere of its own."),
        field(
          fieldName("operator_number"),
          select("sl-operator_number", destinationOptions(t("sl_ChooseDestination", "Choose\u2026")), s.operator_number, { class: "ui search dropdown" }),
          t("sl_OperatorHint", "Where 0 sends callers, at any prompt that listens for keys.")
        ),
        field(fieldName("too_many_tries_number"), select("sl-too_many_tries_number", destinationOptions(t("sl_SameAsOperator", "Same as the Operator")), s.too_many_tries_number, { class: "ui search dropdown" })),
        field(
          fieldName("fault_number"),
          select("sl-fault_number", destinationOptions(t("sl_SameAsOperator", "Same as the Operator")), s.fault_number, { class: "ui search dropdown" }),
          t("sl_FaultHint", "When a status can't be played or its destination is gone. Callers first hear the apology.")
        )
      ),
      section(
        t("sl_SectionGuessing", "Guessing Protection"),
        t("sl_SectionGuessingHint", "IDs typed that aren't found count as misses. A caller number with too many misses in the last hour is put straight through to the operator; so is everyone, when all callers together miss too often (callers who hide their number, or change it with every call). 0 turns a limit off."),
        el(
          "div",
          { class: "two fields" },
          field(fieldName("miss_limit_caller"), numberInput("sl-miss_limit_caller", s.miss_limit_caller, 0, 1e3)),
          field(fieldName("miss_limit_total"), numberInput("sl-miss_limit_total", s.miss_limit_total, 0, 1e5))
        )
      ),
      el(
        "div",
        { class: "sl-settings-save" },
        el("button", { class: "ui primary button", type: "submit", id: "sl-save" }, t("sl_SaveChanges", "Save Changes")),
        el("span", { class: "sl-actions-hint", id: "sl-save-hint" })
      )
    );
    fill(
      box,
      section(t("sl_SectionLine", "Lookup Line"), null, lineState(data)),
      section(t("sl_SectionNumber", "Where Callers Come From"), null, numberForm, usedBy(data)),
      form
    );
    initPromptMenus(box);
    $(box).find("select.ui.dropdown:not(.sl-prompt-select)").dropdown({ fullTextSearch: true, placeholder: false });
    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);
  }
  function markDirty() {
    state.dirty = true;
    $id("sl-save-hint").textContent = t("sl_UnsavedChanges", "Unsaved changes");
  }
  function readCallFlow() {
    const values = {};
    PROMPTS.forEach(([f]) => {
      values[f] = $id(`sl-${f}`).value;
    });
    NUMBER_FIELDS.forEach(([f]) => {
      values[f] = $id(`sl-${f}`).value;
    });
    ["operator_number", "too_many_tries_number", "fault_number", "miss_limit_caller", "miss_limit_total"].forEach((f) => {
      values[f] = $id(`sl-${f}`).value;
    });
    return values;
  }
  function saveCallFlow() {
    const button = $id("sl-save");
    button.classList.add("loading");
    post("saveCallFlow", { settings: JSON.stringify(readCallFlow()) }).then((data) => {
      renderCallFlow(data);
      $id("sl-save-hint").textContent = t("sl_Saved", "Saved.");
    }).fail((xhr) => showError(saveFailed(xhr))).always(() => button.classList.remove("loading"));
  }
  function toggleLine(on) {
    const go = () => post("saveCallFlow", { settings: JSON.stringify({ active: on }) }).then(renderCallFlow).fail((xhr) => showError(saveFailed(xhr)));
    if (state.dirty) {
      showError(t("sl_SaveFirst", "Save or undo your changes first."));
      return;
    }
    if (on) {
      go();
      return;
    }
    confirmDialog(
      t("sl_SwitchOffTitle", "Switch Off the Lookup Line?"),
      t("sl_SwitchOffText", "Callers to the line won't be answered; incoming routes that send callers here move on to their next rule or default action."),
      t("sl_SwitchOff", "Switch Off")
    ).then((ok) => {
      if (ok) go();
    });
  }
  function changeNumber() {
    const number = $id("sl-number").value.trim();
    if (number === state.callFlow.settings.internal_number) return;
    confirmDialog(
      t("sl_ChangeNumberTitle", "Change the Number?"),
      sub(t("sl_ChangeNumberText", "The line will answer on {number} instead of {old}. Staff who dial the old number will no longer reach it."), { number, old: state.callFlow.settings.internal_number || "\u2014" }),
      t("sl_ChangeNumber", "Change Number")
    ).then((ok) => {
      if (!ok) return;
      post("changeNumber", { number }).then((data) => {
        renderCallFlow(data);
        get("options").then((options) => {
          state.options = options;
        });
      }).fail((xhr) => showError(saveFailed(xhr)));
    });
  }
  function loadCallFlow() {
    fill($id("sl-callflow"), el("div", { class: "ui active centered inline loader" }));
    get("callFlow").then(renderCallFlow);
  }
  var EXTRAS = {
    none: ["sl_ExtraNone", "Nothing"],
    id: ["sl_ExtraId", "The ID, Read Back"],
    date: ["sl_ExtraDate", "The Record's Date"]
  };
  var ACTIONS = {
    repeat: ["sl_ActionRepeat", "Offer 1 to Repeat, 0 for a Person"],
    hangup: ["sl_ActionHangup", "Say Goodbye"],
    transfer: ["sl_ActionTransfer", "Put Straight Through"]
  };
  var label = (pair) => t(pair[0], pair[1]);
  function iconButton(icon, text, onclick, attrs = {}) {
    return el("button", { class: "ui basic icon button", type: "button", title: text, "aria-label": text, onclick, ...attrs }, el("i", { class: `${icon} icon` }));
  }
  var soundName = (id) => {
    const file = state.options.soundFiles.find((f) => f.id === id);
    return file ? file.name : null;
  };
  var destinationName = (number) => {
    const d = state.options.destinations.find((x) => x.value === number);
    return d ? d.name : number;
  };
  function afterText(s) {
    return s.after_action === "transfer" ? `${label(ACTIONS.transfer)}: ${destinationName(s.transfer_number)}` : label(ACTIONS[s.after_action] || ACTIONS.repeat);
  }
  function statusRow(s, index, all) {
    const name = soundName(s.prompt_id);
    const listen = iconButton("play", t("sl_Listen", "Listen"), (e) => togglePreview(promptFile(s.prompt_id), e.currentTarget));
    return el(
      "tr",
      {},
      el(
        "td",
        { class: "sl-order-cell" },
        iconButton("arrow up", t("sl_MoveUp", "Move Up"), () => moveStatus(s.id, -1), { disabled: index === 0 }),
        iconButton("arrow down", t("sl_MoveDown", "Move Down"), () => moveStatus(s.id, 1), { disabled: index === all.length - 1 })
      ),
      el("td", {}, el("div", { text: s.name }), el("div", { class: "sl-sub-line sl-code", text: s.code })),
      el(
        "td",
        {},
        el(
          "div",
          { class: "sl-prompt-cell" },
          name ? listen : null,
          el("span", { class: name ? "" : "sl-warn-text", text: name || t("sl_PromptGone", "Sound file missing: choose another") })
        ),
        s.spoken_extra !== "none" ? el("div", { class: "sl-sub-line", text: `+ ${label(EXTRAS[s.spoken_extra])}` }) : null
      ),
      s.after_action === "transfer" && !state.options.destinations.some((d) => d.value === s.transfer_number) ? el("td", { class: "sl-warn-text", text: sub(t("sl_TransferGone", "Put through to {number}, which no longer exists: choose another"), { number: s.transfer_number }) }) : el("td", { text: afterText(s) }),
      el("td", { class: "sl-num", text: count(s.records) }),
      el(
        "td",
        { class: "sl-actions-cell" },
        el("button", { class: "ui basic button", type: "button", onclick: () => openStatus(s) }, t("sl_Edit", "Edit")),
        iconButton("trash alternate outline", t("sl_DeleteStatus", "Delete Status"), () => deleteStatus(s))
      )
    );
  }
  function renderStatuses(statuses) {
    state.statuses = statuses;
    const box = $id("sl-statuses");
    const head = el(
      "div",
      { class: "sl-section-head" },
      el("h4", { class: "ui header", text: t("sl_StatusesTitle", "Statuses") }),
      el(
        "div",
        { class: "sl-section-actions" },
        el("button", { class: "ui primary button", type: "button", onclick: () => openStatus(null) }, el("i", { class: "plus icon" }), t("sl_AddStatus", "Add Status\u2026"))
      )
    );
    const intro = el("div", { class: "sl-note sl-narrow", text: t("sl_StatusesHint", "Each status is one recorded sentence the caller hears for their ID, and what happens next. The code is what CSV files and other software use.") });
    if (!statuses.length) {
      fill(box, head, intro, el("div", { class: "ui message" }, el("p", { text: t("sl_NoStatuses", 'No statuses yet. Add the ones your business uses, such as "Ready for pickup", with a recording of each sentence.') })));
      return;
    }
    const table = el(
      "table",
      { class: "ui very basic table sl-table" },
      el("thead", {}, el(
        "tr",
        {},
        el("th", { class: "sl-order-cell" }, el("span", { class: "sl-visually-hidden", text: t("sl_Order", "Order") })),
        el("th", { text: t("sl_ColStatus", "Status") }),
        el("th", { text: t("sl_ColHears", "Caller Hears") }),
        el("th", { text: t("sl_ColThen", "Then") }),
        el("th", { class: "sl-num", text: t("sl_ColRecords", "Records") }),
        el("th", {})
      )),
      el("tbody", {}, statuses.map(statusRow))
    );
    fill(box, head, intro, table);
  }
  function loadStatuses() {
    fill($id("sl-statuses"), el("div", { class: "ui active centered inline loader" }));
    return get("statuses").then((d) => renderStatuses(d.statuses));
  }
  function moveStatus(id, step) {
    post("moveStatus", { id, step }).then((d) => renderStatuses(d.statuses)).fail((xhr) => showError(saveFailed(xhr)));
  }
  function openStatus(s) {
    const v = s || { code: "", name: "", prompt_id: "", spoken_extra: "none", after_action: "repeat", transfer_number: "" };
    const files = state.options.soundFiles.map((f) => [f.id, f.name]);
    const transferField = field(
      t("sl_TransferTo", "Put Through To"),
      select("sl-status-transfer", destinationOptions(t("sl_ChooseDestination", "Choose\u2026")), v.transfer_number, { class: "ui search dropdown" })
    );
    const form = $id("sl-status-form");
    fill(
      form,
      el(
        "div",
        { class: "two fields" },
        field(t("sl_StatusName", "Name"), el("input", { type: "text", id: "sl-status-name", value: v.name, maxlength: "60" }), t("sl_StatusNameHint", "Shown to staff only.")),
        field(
          t("sl_StatusCode", "Code"),
          el("input", { type: "text", id: "sl-status-code", value: v.code, maxlength: "32", class: "sl-code-input" }),
          s && s.records ? plural("sl_CodeRenameHint", s.records, { one: "Changing it changes it on its {n} record too.", other: "Changing it changes it on its {n} records too." }) : t("sl_StatusCodeHint", "A-Z, 0-9 and _, such as READY.")
        )
      ),
      files.length ? field(
        t("sl_StatusPrompt", "Sentence the Caller Hears"),
        promptControl("sl-status-prompt", v.prompt_id || "", false, []),
        t("sl_StatusPromptHint", "Upload a recording of the sentence on the PBX's Sound files page, then choose it here. Keep it short: one sentence.")
      ) : el("div", { class: "ui warning message" }, el("p", { text: t("sl_NoSoundFiles", "The PBX has no sound files yet. Upload a recording of the sentence on its Sound files page first.") })),
      field(
        t("sl_StatusExtra", "Then Also Say"),
        select("sl-status-extra", Object.entries(EXTRAS).map(([k, pair]) => [k, label(pair)]), v.spoken_extra, { class: "ui dropdown" }),
        t("sl_StatusExtraHint", "The date is the one stored with each record, such as a shipping date.")
      ),
      field(t("sl_StatusAfter", "After That"), select("sl-status-after", Object.entries(ACTIONS).map(([k, pair]) => [k, label(pair)]), v.after_action, { class: "ui dropdown" })),
      transferField
    );
    const after = $id("sl-status-after");
    const showTransfer = () => transferField.classList.toggle("sl-hidden", after.value !== "transfer");
    after.addEventListener("change", showTransfer);
    showTransfer();
    initPromptMenus(form);
    $(form).find("select.ui.dropdown:not(.sl-prompt-select)").dropdown({ fullTextSearch: true, placeholder: false });
    $id("sl-status-title").textContent = s ? t("sl_EditStatus", "Edit Status") : t("sl_AddStatusTitle", "Add Status");
    $id("sl-status-save").onclick = () => saveStatus(s ? s.id : null);
    $id("sl-status-cancel").onclick = () => $("#sl-status-modal").modal("hide");
    form.onsubmit = (e) => {
      e.preventDefault();
      saveStatus(s ? s.id : null);
    };
    $("#sl-status-modal").modal({ autofocus: false, onHidden: stopPreview }).modal("show");
  }
  function saveStatus(id) {
    const prompt = $id("sl-status-prompt");
    const status = {
      ...id ? { id } : {},
      name: $id("sl-status-name").value,
      code: $id("sl-status-code").value,
      prompt_id: prompt ? prompt.value : "",
      spoken_extra: $id("sl-status-extra").value,
      after_action: $id("sl-status-after").value,
      transfer_number: $id("sl-status-transfer").value
    };
    const button = $id("sl-status-save");
    button.classList.add("loading");
    post("saveStatus", { status: JSON.stringify(status) }).then((d) => {
      $("#sl-status-modal").modal("hide");
      renderStatuses(d.statuses);
    }).fail((xhr) => showModalError("sl-status-form", saveFailed(xhr))).always(() => button.classList.remove("loading"));
  }
  function showModalError(formId, text) {
    const form = $id(formId);
    const old = form.querySelector(".sl-modal-error");
    if (old) old.remove();
    form.prepend(el("div", { class: "ui negative message sl-modal-error", role: "alert" }, el("p", { text })));
  }
  function deleteStatus(s) {
    const others = state.statuses.filter((x) => x.id !== s.id);
    let moveTo = null;
    let extra = null;
    if (s.records > 0) {
      if (!others.length) {
        showError(t("sl_LastStatusInUse", "Records still have this status, and there is no other status to move them to. Add another status first, or delete the records."));
        return;
      }
      moveTo = select("sl-move-to", others.map((x) => [x.id, x.name]), others[0].id, { class: "ui dropdown" });
      extra = field(t("sl_MoveRecordsTo", "Move Its Records To"), moveTo);
    }
    const text = s.records > 0 ? plural("sl_DeleteStatusUsed", s.records, { one: '"{name}" is the status of {n} record. It moves to the status you choose.', other: '"{name}" is the status of {n} records. They move to the status you choose.' }, { name: s.name }) : sub(t("sl_DeleteStatusText", '"{name}" will be deleted.'), { name: s.name });
    confirmDialog(t("sl_DeleteStatusTitle", "Delete Status?"), text, t("sl_DeleteStatus", "Delete Status"), extra).then((ok) => {
      if (!ok) return;
      post("deleteStatus", { id: s.id, move_to: moveTo ? moveTo.value : "" }).then((d) => renderStatuses(d.statuses)).fail((xhr) => showError(saveFailed(xhr)));
    });
    if (moveTo) $(moveTo).dropdown();
  }
  var STATUS_COLOURS = ["blue", "teal", "green", "orange", "purple", "brown", "olive", "pink", "violet", "yellow", "grey"];
  var SOURCES = { form: ["sl_Source_form", "on this page"], csv: ["sl_Source_csv", "CSV import"], api: ["sl_Source_api", "API"] };
  var records = { query: { search: "", status: "", stale: "0", expired: "", page: 1 }, selected: /* @__PURE__ */ new Set(), data: null, timer: null };
  var shortDateTime = (epoch) => epoch ? new Date(epoch * 1e3).toLocaleString(LOCALE, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "\u2014";
  var isoText = (iso) => iso ? (/* @__PURE__ */ new Date(`${iso}T00:00:00`)).toLocaleDateString(LOCALE, { day: "numeric", month: "short", year: "numeric" }) : "";
  function statusLabel(code) {
    const index = (state.statuses || []).findIndex((s) => s.code === code);
    const status = index >= 0 ? state.statuses[index] : null;
    return el(
      "span",
      { class: `ui small ${index >= 0 ? STATUS_COLOURS[index % STATUS_COLOURS.length] : "basic"} label`, title: code },
      status ? status.name : code
    );
  }
  var statusChoices = (allText) => [...allText ? [["", allText]] : [], ...(state.statuses || []).map((s) => [s.code, s.name])];
  function recordsToolbar() {
    const search = el("input", { type: "search", id: "sl-rec-search", value: records.query.search, placeholder: t("sl_SearchRecords", "ID or note"), "aria-label": t("sl_SearchRecords", "ID or note") });
    search.addEventListener("input", () => {
      clearTimeout(records.timer);
      records.timer = setTimeout(() => {
        records.query.search = search.value.trim();
        records.query.page = 1;
        loadRecords();
      }, 300);
    });
    const status = select("sl-rec-status", statusChoices(t("sl_AllStatuses", "All Statuses")), records.query.status, { class: "ui dropdown", "aria-label": t("sl_ColStatus", "Status") });
    const stale = select(
      "sl-rec-stale",
      [["0", t("sl_AnyUpdate", "Any Last Change")], ...[7, 30, 90].map((d) => [String(d), plural("sl_NotUpdatedIn", d, { one: "Not Changed in {n} Day", other: "Not Changed in {n} Days" })])],
      records.query.stale,
      { class: "ui dropdown", "aria-label": t("sl_LastChange", "Last change") }
    );
    const expired = el(
      "div",
      { class: "ui checkbox" },
      el("input", { type: "checkbox", id: "sl-rec-expired", checked: records.query.expired === "1" }),
      el("label", { for: "sl-rec-expired", text: t("sl_ExpiredOnly", "Expired Only") })
    );
    [status, stale].forEach((node) => node.addEventListener("change", () => {
      records.query.status = status.value;
      records.query.stale = stale.value;
      records.query.page = 1;
      loadRecords();
    }));
    expired.querySelector("input").addEventListener("change", (e) => {
      records.query.expired = e.target.checked ? "1" : "";
      records.query.page = 1;
      loadRecords();
    });
    return el(
      "div",
      { class: "ui form sl-toolbar" },
      el("div", { class: "ui left icon input sl-search" }, search, el("i", { class: "search icon" })),
      status,
      stale,
      expired,
      el(
        "div",
        { class: "sl-toolbar-end" },
        el("button", { class: "ui basic button", type: "button", onclick: exportRecords }, el("i", { class: "download icon" }), t("sl_ExportCsv", "Export CSV")),
        el(
          "button",
          { class: "ui primary button", type: "button", onclick: () => openRecord(null), disabled: !(state.statuses || []).length },
          el("i", { class: "plus icon" }),
          t("sl_AddRecord", "Add Record\u2026")
        )
      )
    );
  }
  function bulkBar() {
    const n = records.selected.size;
    if (!n) return null;
    const to = select("sl-bulk-status", statusChoices(null), "", { class: "ui dropdown", "aria-label": t("sl_NewStatus", "New status") });
    return el(
      "div",
      { class: "sl-bulk ui form" },
      el("strong", { text: plural("sl_Selected", n, { one: "{n} record selected", other: "{n} records selected" }) }),
      el(
        "div",
        { class: "sl-inline" },
        to,
        el("button", { class: "ui button", type: "button", onclick: () => bulkStatus(to.value) }, t("sl_ChangeStatus", "Change Status")),
        el("button", { class: "ui basic red button", type: "button", onclick: bulkDelete }, t("sl_DeleteSelected", "Delete Selected")),
        el("button", { class: "ui basic button", type: "button", onclick: () => {
          records.selected.clear();
          renderRecords();
        } }, t("sl_ClearSelection", "Clear Selection"))
      )
    );
  }
  function recordRow(r, today) {
    const expired = r.expires_on && r.expires_on < today;
    const box = el("input", { type: "checkbox", checked: records.selected.has(r.id), "aria-label": sub(t("sl_SelectRecord", "Select {id}"), { id: r.lookup_id }) });
    box.addEventListener("change", () => {
      if (box.checked) records.selected.add(r.id);
      else records.selected.delete(r.id);
      renderRecords();
    });
    return el(
      "tr",
      { class: expired ? "sl-expired" : "" },
      el("td", { class: "sl-check-cell" }, box),
      el("td", { class: "sl-code", text: r.lookup_id }),
      el("td", {}, statusLabel(r.status_code)),
      el("td", { class: "sl-nowrap", text: isoText(r.status_date) }),
      el("td", { class: "sl-nowrap" }, expired ? el("span", { class: "sl-warn-text", text: sub(t("sl_ExpiredOn", "Expired {date}"), { date: isoText(r.expires_on) }) }) : isoText(r.expires_on)),
      el("td", { class: "sl-note-cell", text: r.note }),
      el(
        "td",
        { class: "sl-nowrap" },
        el("div", { text: shortDateTime(r.updated_at) }),
        el("div", { class: "sl-sub-line", text: [r.updated_by, SOURCES[r.source] ? label(SOURCES[r.source]) : ""].filter(Boolean).join(" \xB7 ") })
      ),
      el(
        "td",
        { class: "sl-actions-cell" },
        el("button", { class: "ui basic button", type: "button", onclick: () => openRecord(r) }, t("sl_Edit", "Edit")),
        iconButton("trash alternate outline", t("sl_DeleteRecord", "Delete Record"), () => deleteRecords([r.id], r.lookup_id))
      )
    );
  }
  function renderRecords() {
    const d = records.data;
    const box = $id("sl-records");
    if (!(state.statuses || []).length) {
      fill(box, el("div", { class: "ui message" }, el("p", { text: t("sl_RecordsNeedStatuses", "Add statuses first: every record has one. Open the Statuses tab.") })));
      return;
    }
    const rows = d ? d.rows : [];
    const all = rows.length > 0 && rows.every((r) => records.selected.has(r.id));
    const allBox = el("input", { type: "checkbox", checked: all, "aria-label": t("sl_SelectPage", "Select all on this page") });
    allBox.addEventListener("change", () => {
      rows.forEach((r) => allBox.checked ? records.selected.add(r.id) : records.selected.delete(r.id));
      renderRecords();
    });
    const filtered = records.query.search || records.query.status || records.query.stale !== "0" || records.query.expired;
    const table = rows.length ? el(
      "table",
      { class: "ui very basic table sl-table" },
      el("thead", {}, el(
        "tr",
        {},
        el("th", { class: "sl-check-cell" }, allBox),
        el("th", { text: t("sl_ColId", "ID") }),
        el("th", { text: t("sl_ColStatus", "Status") }),
        el("th", { text: t("sl_ColDate", "Date") }),
        el("th", { text: t("sl_ColLastDay", "Last Day") }),
        el("th", { text: t("sl_ColNote", "Note") }),
        el("th", { text: t("sl_ColChanged", "Last Change") }),
        el("th", {})
      )),
      el("tbody", {}, rows.map((r) => recordRow(r, d.today)))
    ) : el("div", { class: "ui message" }, el("p", { text: filtered ? t("sl_NoMatches", "No records match these filters.") : t("sl_NoRecords", "No records yet. Add them one by one, or import a CSV file from your shop software or spreadsheet.") }));
    const pager = d && d.pages > 1 ? el(
      "div",
      { class: "sl-pager" },
      el("button", { class: "ui basic button", type: "button", disabled: d.page <= 1, onclick: () => {
        records.query.page = d.page - 1;
        loadRecords();
      } }, t("sl_Previous", "Previous")),
      el("span", { text: sub(t("sl_PageOf", "Page {page} of {pages}"), { page: count(d.page), pages: count(d.pages) }) }),
      el("button", { class: "ui basic button", type: "button", disabled: d.page >= d.pages, onclick: () => {
        records.query.page = d.page + 1;
        loadRecords();
      } }, t("sl_Next", "Next"))
    ) : null;
    const focused = document.activeElement && document.activeElement.id === "sl-rec-search";
    fill(
      box,
      recordsToolbar(),
      d ? el("div", { class: "sl-note sl-count", text: plural("sl_RecordCount", d.total, { one: "{n} record", other: "{n} records" }) }) : null,
      bulkBar(),
      table,
      pager
    );
    $(box).find("select.ui.dropdown").dropdown({ placeholder: false });
    $(box).find(".ui.checkbox").checkbox();
    if (focused) {
      const search = $id("sl-rec-search");
      search.focus();
      search.setSelectionRange(search.value.length, search.value.length);
    }
  }
  function loadRecords() {
    return get("records", records.query).then((d) => {
      records.data = d;
      records.query.page = d.page;
      renderRecords();
    });
  }
  function loadRecordsTab() {
    fill($id("sl-records"), el("div", { class: "ui active centered inline loader" }));
    get("statuses").then((d) => {
      state.statuses = d.statuses;
      return loadRecords();
    });
  }
  function openRecord(r) {
    const v = r || { lookup_id: "", status_code: state.statuses[0].code, note: "", status_date: "", expires_on: "" };
    const form = $id("sl-record-form");
    fill(
      form,
      el(
        "div",
        { class: "two fields" },
        field(
          t("sl_ColId", "ID"),
          el("input", { type: "text", id: "sl-record-id", value: v.lookup_id, inputmode: "numeric", maxlength: "20", class: "sl-code-input" }),
          t("sl_RecordIdHint", "The digits the caller types. Leading zeros count.")
        ),
        field(t("sl_ColStatus", "Status"), select("sl-record-status", statusChoices(null), v.status_code, { class: "ui dropdown" }))
      ),
      field(t("sl_ColNote", "Note"), el("input", { type: "text", id: "sl-record-note", value: v.note, maxlength: "200" }), t("sl_RecordNoteHint", "For staff only; never read out to callers.")),
      el(
        "div",
        { class: "two fields" },
        field(t("sl_ColDate", "Date"), el("input", { type: "date", id: "sl-record-date", value: v.status_date }), t("sl_RecordDateHint", "Spoken after the status, if the status says so.")),
        field(t("sl_ColLastDay", "Last Day"), el("input", { type: "date", id: "sl-record-expires", value: v.expires_on }), t("sl_RecordExpiresHint", 'After this day, callers hear "not found". Empty: no end.'))
      )
    );
    $(form).find("select.ui.dropdown").dropdown({ placeholder: false });
    $id("sl-record-title").textContent = r ? t("sl_EditRecord", "Edit Record") : t("sl_AddRecordTitle", "Add Record");
    const save = () => saveRecord(r ? r.id : null);
    $id("sl-record-save").onclick = save;
    $id("sl-record-cancel").onclick = () => $("#sl-record-modal").modal("hide");
    form.onsubmit = (e) => {
      e.preventDefault();
      save();
    };
    $("#sl-record-modal").modal({ autofocus: false }).modal("show");
    if (!r) $id("sl-record-id").focus();
  }
  function saveRecord(id) {
    const record = {
      ...id ? { id } : {},
      lookup_id: $id("sl-record-id").value,
      status_code: $id("sl-record-status").value,
      note: $id("sl-record-note").value,
      status_date: $id("sl-record-date").value,
      expires_on: $id("sl-record-expires").value
    };
    const button = $id("sl-record-save");
    button.classList.add("loading");
    post("saveRecord", { record: JSON.stringify(record) }).then(() => {
      $("#sl-record-modal").modal("hide");
      return loadRecords();
    }).fail((xhr) => showModalError("sl-record-form", saveFailed(xhr))).always(() => button.classList.remove("loading"));
  }
  function bulkStatus(code) {
    const ids = [...records.selected];
    post("changeRecordStatus", { ids: JSON.stringify(ids), status: code }).then(() => {
      records.selected.clear();
      return loadRecords();
    }).fail((xhr) => showError(saveFailed(xhr)));
  }
  function deleteRecords(ids, single) {
    const text = single ? sub(t("sl_DeleteRecordText", 'Record {id} will be deleted. Callers who type it will hear "not found".'), { id: single }) : plural("sl_DeleteRecordsText", ids.length, { one: "{n} record will be deleted.", other: "{n} records will be deleted." });
    const ok = single ? t("sl_DeleteRecord", "Delete Record") : plural("sl_DeleteRecordsButton", ids.length, { one: "Delete {n} Record", other: "Delete {n} Records" });
    confirmDialog(t("sl_DeleteRecordsTitle", "Delete Records?"), text, ok).then((yes) => {
      if (!yes) return;
      post("deleteRecords", { ids: JSON.stringify(ids) }).then(() => {
        ids.forEach((id) => records.selected.delete(id));
        return loadRecords();
      }).fail((xhr) => showError(saveFailed(xhr)));
    });
  }
  var bulkDelete = () => deleteRecords([...records.selected], null);
  function exportRecords() {
    const { page, ...query } = records.query;
    window.location.href = `${BASE}exportRecords?${$.param(query)}`;
  }
  function updateTabsOverflow() {
    const tabs = $id("sl-tabs");
    const overflow = tabs.scrollWidth > tabs.clientWidth + 1;
    tabs.classList.toggle("sl-overflow", overflow);
    tabs.classList.toggle("sl-at-end", overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1);
  }
  var TABS = { records: loadRecordsTab, statuses: loadStatuses, callflow: loadCallFlow };
  function selectTab(tab) {
    if (state.tab === "callflow" && tab !== "callflow" && state.dirty) {
      confirmDialog(t("sl_LeaveTitle", "Leave Without Saving?"), t("sl_LeaveText", "Your changes to the call flow will be lost."), t("sl_Leave", "Leave")).then((ok) => {
        if (ok) {
          state.dirty = false;
          selectTab(tab);
        }
      });
      return;
    }
    stopPreview();
    state.tab = tab;
    document.querySelectorAll("#status-lookup [data-tab]").forEach((n) => n.classList.toggle("active", n.dataset.tab === tab));
    clearError();
    TABS[tab]();
  }
  function init() {
    document.querySelectorAll("#sl-tabs .item").forEach((a) => a.addEventListener("click", () => selectTab(a.dataset.tab)));
    $id("sl-tabs").addEventListener("scroll", updateTabsOverflow);
    window.addEventListener("resize", updateTabsOverflow);
    window.addEventListener("beforeunload", (e) => {
      if (state.dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
    updateTabsOverflow();
    get("options").then((options) => {
      state.options = options;
      const asked = new URLSearchParams(window.location.search).get("tab");
      selectTab(TABS[asked] ? asked : "records");
    });
  }
  $(document).ready(init);
})();

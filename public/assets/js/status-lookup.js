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
      if (c !== null && c !== void 0 && c !== false && c !== "") node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    });
    return node;
  }
  var fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c !== null && c !== void 0 && c !== false));
  var seconds = (n) => new Intl.NumberFormat(LOCALE, { style: "unit", unit: "second", unitDisplay: "short" }).format(n);
  function labelCells(root) {
    root.querySelectorAll("table.sl-table").forEach((table) => {
      const names = [...table.querySelectorAll("thead th")].map((th) => th.textContent.trim());
      table.querySelectorAll("tbody tr").forEach((tr) => [...tr.children].forEach((td, i) => {
        if (names[i]) td.dataset.label = names[i];
      }));
    });
  }
  new MutationObserver((changes) => changes.forEach((c) => c.addedNodes.forEach((n) => {
    if (n.nodeType === 1) labelCells(n.matches && n.matches("table") ? n.parentNode || n : n);
  }))).observe(document.documentElement, { childList: true, subtree: true });
  var LOCALE = (() => {
    try {
      return Intl.getCanonicalLocales((document.documentElement.lang || "").replace(/_/g, "-"))[0] || void 0;
    } catch (e) {
      return void 0;
    }
  })();
  var count = (n) => Number(n).toLocaleString(LOCALE);
  var sub = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.split(`{${k}}`).join(String(v)), text);
  function plural(key, n, fallbacks, values = {}) {
    const form = new Intl.PluralRules(LOCALE).select(n);
    const has = (k) => globalTranslate && k in globalTranslate;
    const text = has(`${key}_${form}`) ? globalTranslate[`${key}_${form}`] : has(`${key}_other`) ? globalTranslate[`${key}_other`] : fallbacks[form] || fallbacks.other;
    return sub(text, { n: count(n), ...values });
  }
  var state = { tab: null, options: null, callFlow: null, dirty: false };
  var FIELDS = {
    welcome_prompt_id: ["sl_PromptWelcome", "Welcome"],
    enter_id_prompt_id: ["sl_PromptEnterId", "Ask for the ID"],
    enter_pin_prompt_id: ["sl_PromptEnterPin", "Ask for the PIN"],
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
    log_retention_days: ["sl_LogRetention", "Keep the Call Log For"],
    operator_number: ["sl_Operator", "Operator"],
    too_many_tries_number: ["sl_TooManyTries", "After the Last Try"],
    fault_number: ["sl_FaultDestination", "When a Lookup Fails"],
    status_date: ["sl_ColDate", "Date"],
    expires_on: ["sl_ColLastDay", "Last Day"]
  };
  var fieldName = (f) => FIELDS[f] ? t(FIELDS[f][0], FIELDS[f][1]) : f;
  var PROBLEMS = {
    no_number: ["sl_Problem_no_number", "The line has no internal number. Enter one under Where Callers Come From."],
    not_registered: ["sl_Problem_not_registered", "Its number belongs to something else in the PBX. Choose another number."],
    no_operator: ["sl_Problem_no_operator", "Choose where 0 sends callers (the operator)."],
    gone_too_many_tries_number: ["sl_Problem_gone_too_many", "The destination after the last try no longer exists."],
    gone_fault_number: ["sl_Problem_gone_fault", "The destination for failed lookups no longer exists."]
  };
  function problemField(code) {
    if (code === "no_operator") return "operator_number";
    if (code === "no_number" || code === "not_registered") return "number";
    if (code.startsWith("gone_")) return code.slice(5);
    if (code.startsWith("prompt_")) return `${code.slice(7)}_prompt_id`;
    return null;
  }
  function showField(name, message = "") {
    const control = $id(`sl-${name}`);
    const box = control && control.closest(".field");
    if (!box) return;
    box.classList.add("error");
    box.querySelectorAll(".sl-field-error").forEach((n) => n.remove());
    if (message) box.append(el("div", { class: "ui basic red pointing prompt label sl-field-error", role: "alert", text: message }));
    window.scrollTo({ top: box.getBoundingClientRect().top + window.scrollY - 120, behavior: "smooth" });
    const focusable = box.querySelector(".ui.dropdown") || control;
    if (focusable && focusable.focus) focusable.focus({ preventScroll: true });
  }
  function problemText(code) {
    if (PROBLEMS[code]) return t(PROBLEMS[code][0], PROBLEMS[code][1]);
    const prompt = code.startsWith("prompt_") ? `${code.slice(7)}_prompt_id` : null;
    if (prompt && FIELDS[prompt]) {
      return sub(t("sl_Problem_prompt", `The "{prompt}" prompt can't be played. Choose another sound file.`), { prompt: fieldName(prompt) });
    }
    return code;
  }
  var ERRORS = {
    bad_request: ["sl_Err_bad_request", "The page sent a request the server couldn't read. Reload the page and try again."],
    failed: ["sl_Err_failed", "Something went wrong. Try again; if it keeps happening, check the PBX's system log."],
    refused: ["sl_Err_refused", "The PBX refused this change. Reload the page and try again; if it keeps happening, the PBX's system log says why."],
    prompt: ["sl_Err_prompt", `The "{field}" prompt can't be played. Choose another sound file.`],
    range: ["sl_Err_range", '"{field}": enter a whole number from {min} to {max}.'],
    whole_number: ["sl_Err_whole_number", '"{field}": enter a whole number.'],
    destination: ["sl_Err_destination", 'Choose a destination for "{field}" from the list.'],
    lengths_exclude: ["sl_Err_lengths_exclude", "Some records have IDs outside these lengths. Change the lengths, or those records' IDs."],
    lengths: ["sl_Err_lengths", "The shortest ID can't be longer than the longest."],
    not_ready: ["sl_Err_not_ready", "The line can't be switched on yet: {problems}"],
    number_format: ["sl_Err_number_format", "Enter 2 to 8 digits, not starting with 0."],
    number_taken: ["sl_Err_number_taken", "{number} is already used in the PBX. Choose another number."],
    file_size: ["sl_Err_file_size", "The file is larger than {max} MB. Split it, or import only the records that changed."],
    file_missing: ["sl_Err_file_missing", "The file didn't arrive. Choose it again."],
    file_encoding: ["sl_Err_file_encoding", `The file isn't UTF-8 text. In your spreadsheet, save it as "CSV UTF-8".`],
    file_empty: ["sl_Err_file_empty", "The file is empty. Choose another file."],
    file_header: ["sl_Err_file_header", "The first row must name the columns, with at least lookup_id and status_code."],
    file_rows: ["sl_Err_file_rows", "The file has more than {max} rows. Split it and import each part with Add and Update."],
    statuses_changed: ["sl_Err_statuses_changed", "The statuses changed while the file was being checked. Check the file again."],
    replace_empty: ["sl_Err_replace_empty", "No row in the file can be imported, so Replace All would delete every record. Nothing was changed."],
    lookup_id: ["sl_Err_lookup_id", "Enter the ID as {min} to {max} digits, as callers type it (the lengths are set on the Call Flow tab)."],
    lookup_id_taken: ["sl_Err_lookup_id_taken", "There is already a record with the ID {id}."],
    record_status: ["sl_Err_record_status", "Choose a status from the list."],
    record_gone: ["sl_Err_record_gone", "This record no longer exists. Reload the page."],
    note: ["sl_Err_note", "Keep the note to {max} characters."],
    date: ["sl_Err_date", 'Enter a valid date for "{field}".'],
    bulk: ["sl_Err_bulk", "Select up to {max} records at a time."],
    template: ["sl_Err_template", "This template can't be used. Reload the page."],
    script: ["sl_Err_script", "Keep the wording to {max} characters."],
    status_gone: ["sl_Err_status_gone", "This status no longer exists. Reload the page."],
    code_format: ["sl_Err_code_format", "Use up to 32 letters A\u2013Z, digits and _ for the code, such as READY."],
    code_taken: ["sl_Err_code_taken", "Another status already has the code {code}."],
    name: ["sl_Err_name", "Enter a name of up to {max} characters."],
    status_prompt: ["sl_Err_status_prompt", "Choose the sound file with the sentence the caller hears."],
    status_destination: ["sl_Err_status_destination", "Choose where to put the caller through to."],
    too_many_statuses: ["sl_Err_too_many_statuses", "You can have up to {max} statuses. Delete one you no longer use first."],
    move_to: ["sl_Err_move_to", "Choose another status to move the records to."],
    status_in_use: ["sl_Err_status_in_use", "Records still have this status. Choose a status to move them to."],
    test_extension: ["sl_Err_test_extension", "Choose an extension from the list."],
    range_dates: ["sl_Err_range_dates", "Choose a start date on or before the end date."],
    range_long: ["sl_Err_range_long", "Choose a period of up to {max} days."],
    forget_id: ["sl_Err_forget_id", "Enter the ID, as callers type it."],
    forget_phone: ["sl_Err_forget_phone", "Enter the number with at least {min} digits."],
    pin: ["sl_Err_pin", "Enter the PIN as 4 to 8 digits."],
    phone: ["sl_Err_phone", "Enter the phone number with 7 to 20 digits, such as 4915112345678."],
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
  var saveFailed = (xhr) => failText(xhr, t("sl_SaveFailed", "The change couldn't be saved. Try again."));
  function get(action, data = {}) {
    return $.ajax({ url: BASE + action, method: "GET", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })).fail((xhr) => showError(failText(xhr, t("sl_LoadFailed", "Status Lookup couldn't load this data. Reload the page to try again."))));
  }
  function post(action, data) {
    clearError();
    return $.ajax({ url: BASE + action, method: "POST", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r }));
  }
  function busy(button, run) {
    if (!button || button.classList.contains("loading")) return $.Deferred();
    button.classList.add("loading");
    return run().always(() => button.classList.remove("loading"));
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
  function checkboxField(id, checked, labelText, hint) {
    return el(
      "div",
      { class: "field" },
      el("div", { class: "ui checkbox" }, el("input", { type: "checkbox", id, checked: !!checked }), el("label", { for: id, text: labelText })),
      hint ? el("div", { class: "sl-note sl-check-hint", text: hint }) : null
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
  function confirmDialog(title, text, okText, extra = null, info = false) {
    return new Promise((resolve) => {
      $id("sl-confirm-title").textContent = title;
      $id("sl-confirm-text").textContent = text;
      $id("sl-confirm-text").classList.toggle("sl-hidden", text === "");
      $id("sl-confirm-ok").className = `ui ${info ? "primary" : "red"} button`;
      $id("sl-confirm-cancel").classList.toggle("sl-hidden", info);
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
    welcome: ["sl_BuiltIn_welcome", "Built-in: Thank You for Calling"],
    "enter-id": ["sl_BuiltIn_enter-id", "Built-in: Enter Your Reference Number"],
    "enter-pin": ["sl_BuiltIn_enter-pin", "Built-in: Enter Your PIN"],
    invalid: ["sl_BuiltIn_invalid", "Built-in: Not a Valid Number"],
    "not-found": ["sl_BuiltIn_not-found", "Built-in: We Couldn't Find That Number"],
    repeat: ["sl_BuiltIn_repeat", "Built-in: 1 to Hear Again, 0 for a Person"],
    goodbye: ["sl_BuiltIn_goodbye", "Built-in: Goodbye"],
    transfer: ["sl_BuiltIn_transfer", "Built-in: Putting You Through"],
    fault: ["sl_BuiltIn_fault", "Built-in: We Can't Check Right Now"]
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
  function promptOptions(withNone, builtInKeys, value, placeholder) {
    const builtIn = state.options.builtInPrompts.filter((p) => builtInKeys.includes(p.key) && p.available).map((p) => [p.id, t(BUILT_IN_NAMES[p.key][0], BUILT_IN_NAMES[p.key][1])]);
    const files = state.options.soundFiles.map((f) => [f.id, f.name]);
    const known = [...builtIn, ...files].some(([id]) => String(id) === String(value));
    const missing = value !== "" && value !== null && value !== void 0 && String(value) !== "0" && !known;
    return [
      ...placeholder ? [["", placeholder]] : [],
      ...missing ? [[value, t("sl_MissingPrompt", "Missing Sound File")]] : [],
      ...withNone ? [["0", t("sl_NoPrompt", "None")]] : [],
      ...builtIn.length ? [{ group: t("sl_BuiltInPrompts", "Built-in Prompts"), options: builtIn }] : [],
      ...files.length ? [{ group: t("sl_SoundFiles", "Sound Files"), options: files }] : []
    ];
  }
  function promptControl(id, value, withNone, builtInKeys, placeholder = null) {
    const menu = select(id, promptOptions(withNone, builtInKeys, value, placeholder), value != null ? value : "", { class: "ui search dropdown sl-prompt-select" });
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
        if (!value || value === "0" || !promptFile(value)) return;
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
  function destinationOptions(emptyText, value = "") {
    const known = state.options.destinations.some((d) => d.value === value);
    return [
      ["", emptyText],
      ...value && !known ? [[value, sub(t("sl_GoneDestination", "{number} (No Longer Exists)"), { number: value })]] : [],
      ...state.options.destinations.map((d) => [d.value, d.name])
    ];
  }
  var PROMPTS = [
    ["welcome_prompt_id", ["welcome"], true, ["sl_PromptWelcomeHint", "Played once, as the call is answered. Callers can start typing during it."]],
    ["enter_id_prompt_id", ["enter-id"], false, ["sl_PromptEnterIdHint", "Asks for the ID and says that 0 reaches a person."]],
    ["enter_pin_prompt_id", ["enter-pin"], false, ["sl_PromptEnterPinHint", "Only for statuses that ask for the record's PIN."]],
    ["invalid_prompt_id", ["invalid"], false, ["sl_PromptInvalidHint", "After an entry that is too short, too long or empty."]],
    ["not_found_prompt_id", ["not-found"], false, ["sl_PromptNotFoundHint", "After an ID that isn't found or has expired, a wrong PIN, or a call from the wrong number."]],
    ["repeat_prompt_id", ["repeat"], false, ["sl_PromptRepeatHint", "After a status: 1 to hear it again, 0 for a person."]],
    ["goodbye_prompt_id", ["goodbye"], false, null],
    ["transfer_prompt_id", ["transfer"], false, ["sl_PromptTransferHint", "Before a caller is put through to a person."]],
    ["fault_prompt_id", ["fault"], false, ["sl_PromptFaultHint", "When a lookup fails, before the caller is put through."]]
  ];
  var NUMBER_FIELDS = [
    ["id_min_length", 2, 20, null],
    ["id_max_length", 2, 20, null],
    ["digit_timeout", 1, 30, ["sl_DigitTimeoutHint", "Seconds to wait for the first key and between keys. Callers end the ID with #, or wait this long."]],
    ["max_tries", 1, 9, ["sl_MaxTriesHint", "How many wrong or empty entries a caller can make before being put through."]]
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
      if (data.problems.length) {
        lines.push(el("ul", {}, data.problems.map((p) => {
          const name = problemField(p);
          return el("li", {}, name ? el("a", { href: "#", text: problemText(p), onclick: (e) => {
            e.preventDefault();
            showField(name);
          } }) : problemText(p));
        })));
      }
    }
    const toggle = el("button", {
      // Blocked: a plain disabled button, not a pale green one that looks broken.
      class: `ui ${switchedOn ? "basic" : data.problems.length ? "" : "positive"} button`,
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
      el("button", { class: "ui basic button", type: "button", onclick: changeNumber }, t("sl_ChangeNumber", "Change Number"))
    ));
    const form = el(
      "form",
      { class: "ui form", id: "sl-callflow-form", novalidate: true, onsubmit: (e) => {
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
          select("sl-operator_number", destinationOptions(t("sl_ChooseDestination", "Choose\u2026"), s.operator_number), s.operator_number, { class: "ui search dropdown" }),
          t("sl_OperatorHint", "Where 0 sends callers, at any prompt that listens for keys.")
        ),
        field(fieldName("too_many_tries_number"), select("sl-too_many_tries_number", destinationOptions(t("sl_SameAsOperator", "Same as the Operator"), s.too_many_tries_number), s.too_many_tries_number, { class: "ui search dropdown" })),
        field(
          fieldName("fault_number"),
          select("sl-fault_number", destinationOptions(t("sl_SameAsOperator", "Same as the Operator"), s.fault_number), s.fault_number, { class: "ui search dropdown" }),
          t("sl_FaultHint", "When a status can't be played, its destination is gone, or anything else fails. Callers hear the apology first.")
        )
      ),
      section(
        t("sl_SectionGuessing", "Guessing Protection"),
        t("sl_SectionGuessingHint", "IDs that aren't found, wrong PINs and calls from the wrong number count as misses. When one caller number, or all callers together, reach their limit within an hour, the next ID they type goes straight to the operator. 0 turns a limit off."),
        el(
          "div",
          { class: "two fields" },
          field(fieldName("miss_limit_caller"), numberInput("sl-miss_limit_caller", s.miss_limit_caller, 0, 1e3)),
          field(fieldName("miss_limit_total"), numberInput("sl-miss_limit_total", s.miss_limit_total, 0, 1e5))
        )
      ),
      section(
        t("sl_SectionPrivacy", "Privacy"),
        t("sl_SectionPrivacyHint", "The call log holds callers' numbers and the IDs they typed. Old calls are deleted every night; the daily totals on the Activity tab stay, without numbers."),
        field(
          fieldName("log_retention_days"),
          el(
            "div",
            { class: "ui right labeled input sl-days-input" },
            numberInput("sl-log_retention_days", s.log_retention_days, 0, 3650),
            el("div", { class: "ui basic label", text: t("sl_Days", "days") })
          ),
          retentionHint()
        ),
        checkboxField(
          "sl-purge_expired",
          s.purge_expired === "1",
          t("sl_PurgeExpired", "Delete expired records every night"),
          t("sl_PurgeExpiredHint", 'Records are deleted the day after their last day. Off: they stay, and callers hear "not found".')
        ),
        forgetPart()
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
      section(t("sl_SectionLine", "Lookup Line"), null, lineState(data), testCallRow(data)),
      section(t("sl_SectionNumber", "Where Callers Come From"), null, numberForm, usedBy(data)),
      form
    );
    initPromptMenus(box);
    $(form).find(".ui.checkbox").checkbox();
    data.problems.map(problemField).filter(Boolean).forEach((name) => {
      const control = $id(`sl-${name}`);
      if (control && control.closest(".field")) control.closest(".field").classList.add("warning");
    });
    $(box).find("select.ui.dropdown:not(.sl-prompt-select)").dropdown({ fullTextSearch: true, placeholder: false });
    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);
  }
  function testCallRow(data) {
    const extensions = state.options.extensions || [];
    if (!extensions.length || !data.settings.internal_number) return null;
    const ext = select(
      "sl-test-ext",
      extensions.map((x) => [x.number, x.name ? `${x.name} (${x.number})` : x.number]),
      extensions[0].number,
      { class: "ui search dropdown", "aria-label": t("sl_TestExtension", "Extension") }
    );
    const button = el("button", { class: "ui basic button", type: "button", id: "sl-test-call", onclick: () => {
      busy(button, () => post("testCall", { extension: ext.value }).then(() => {
        $id("sl-test-note").textContent = sub(t("sl_TestCalling", "Calling {ext}: answer it to hear the line as a caller would."), { ext: ext.value });
      }).fail((xhr) => showError(saveFailed(xhr))));
    } }, el("i", { class: "phone icon" }), t("sl_TestCall", "Test Call"));
    return el(
      "div",
      { class: "ui form sl-test" },
      el("div", { class: "sl-inline" }, field(t("sl_TestTheLine", "Test the Line"), ext), button),
      el("div", { class: "sl-note", id: "sl-test-note", text: data.answering ? t("sl_TestHint", "The extension rings; answering puts you through the line. Test calls are marked in the call log and left out of Activity.") : t("sl_TestOffHint", "The line isn't answering yet, so a test call ends at once.") })
    );
  }
  function markDirty(e) {
    if (e && e.target && e.target.matches && e.target.matches(".ui.dropdown input.search")) return;
    if (e && e.target && e.target.closest && e.target.closest(".sl-forget")) return;
    if (e && e.target && e.target.closest && e.target.closest(".field")) {
      const box = e.target.closest(".field");
      box.classList.remove("error", "warning");
      box.querySelectorAll(".sl-field-error").forEach((n) => n.remove());
    }
    state.dirty = true;
    $id("sl-save-hint").textContent = t("sl_UnsavedChanges", "Unsaved changes");
  }
  function retentionHint() {
    const cdr = state.options.cdrDays || 0;
    return cdr >= 30 ? plural("sl_RetentionCdr", cdr, { one: "0: as long as the PBX keeps its call history (now {n} day).", other: "0: as long as the PBX keeps its call history (now {n} days)." }) : t("sl_RetentionForever", "0: forever, because the PBX keeps its call history forever.");
  }
  function forgetPart() {
    return el(
      "div",
      { class: "sl-forget" },
      el("h5", { class: "ui header", text: t("sl_SectionForget", "Forget Someone's Data") }),
      el("div", { class: "sl-note", text: t("sl_SectionForgetHint", "For a customer who asks to have their data deleted. This can't be undone; the PBX's system log notes who did it, without the ID or number.") }),
      el("button", { class: "ui basic button sl-forget-open", type: "button", onclick: openForget }, t("sl_ForgetOpen", "Forget Someone's Data\u2026")),
      el("div", { class: "sl-note", id: "sl-forget-note", role: "status" })
    );
  }
  function openForget() {
    const kinds = {
      id: [
        t("sl_ForgetId", "An ID"),
        "forgetId",
        "id",
        t("sl_ForgetIdText", "The record {value} and every logged call where it was typed are deleted."),
        (d) => `${d.records ? t("sl_ForgotRecord", "The record was deleted.") : t("sl_ForgotNoRecord", "There was no record with this ID.")} ${plural("sl_ForgotCalls", d.calls, { one: "{n} call was deleted from the call log.", other: "{n} calls were deleted from the call log." })}`
      ],
      phone: [
        t("sl_ForgetPhone", "A caller number"),
        "forgetCaller",
        "phone",
        t("sl_ForgetPhoneText", "Every call from {value} is deleted, and the number is removed from records. Numbers match on their last digits, as for the caller-ID check."),
        (d) => `${plural("sl_ForgotCalls", d.calls, { one: "{n} call was deleted from the call log.", other: "{n} calls were deleted from the call log." })} ${plural("sl_ForgotPhones", d.records, { one: "The number was removed from {n} record.", other: "The number was removed from {n} records." })}`
      ]
    };
    const kind = select("sl-forget-kind", Object.entries(kinds).map(([k, v]) => [k, v[0]]), "id", { class: "ui dropdown" });
    const input = el("input", { type: "text", id: "sl-forget-value", inputmode: "numeric", maxlength: "40", autocomplete: "off" });
    const what = el("div", { class: "sl-note" });
    const update = () => {
      what.textContent = input.value.trim() ? sub(kinds[kind.value][3], { value: input.value.trim() }) : "";
    };
    kind.addEventListener("change", update);
    input.addEventListener("input", update);
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        $id("sl-confirm-ok").click();
      }
    });
    const form = el("div", { class: "ui form" }, el(
      "div",
      { class: "two fields" },
      field(t("sl_ForgetWhat", "What to Forget"), kind),
      field(t("sl_ForgetValue", "ID or Caller Number"), input)
    ), what);
    const ask = confirmDialog(
      t("sl_SectionForget", "Forget Someone's Data"),
      t("sl_ForgetDialogText", "This deletes the data at once. It can't be undone."),
      t("sl_Forget", "Forget"),
      form
    );
    $(kind).dropdown({ placeholder: false });
    setTimeout(() => input.focus(), 300);
    ask.then((ok) => {
      const value = input.value.trim();
      if (!ok || !value) return;
      const [, action, param, , done] = kinds[kind.value];
      busy(document.querySelector(".sl-forget-open"), () => post(action, { [param]: value }).then((d) => {
        $id("sl-forget-note").textContent = done(d);
      }).fail((xhr) => showError(saveFailed(xhr))));
    });
  }
  function readCallFlow() {
    const values = {};
    PROMPTS.forEach(([f]) => {
      values[f] = $id(`sl-${f}`).value;
    });
    NUMBER_FIELDS.forEach(([f]) => {
      values[f] = $id(`sl-${f}`).value;
    });
    ["operator_number", "too_many_tries_number", "fault_number", "miss_limit_caller", "miss_limit_total", "log_retention_days"].forEach((f) => {
      values[f] = $id(`sl-${f}`).value;
    });
    values.purge_expired = $id("sl-purge_expired").checked;
    return values;
  }
  function saveCallFlow(confirmLengths = false) {
    busy($id("sl-save"), () => post("saveCallFlow", { settings: JSON.stringify({ ...readCallFlow(), ...confirmLengths ? { confirm_lengths: true } : {} }) }).then((data) => {
      renderCallFlow(data);
      $id("sl-save-hint").textContent = t("sl_Saved", "Saved.");
    }).fail((xhr) => {
      const body = xhr && xhr.responseJSON;
      if (body && body.message === "lengths_exclude") {
        const p = body.params || {};
        confirmDialog(
          t("sl_LengthsTitle", "Leave Records Out of Reach?"),
          plural("sl_LengthsText", p.n, {
            one: "{n} record has an ID that isn't {min} to {max} digits long. Callers won't be able to reach it until its ID or these lengths change.",
            other: "{n} records have IDs that aren't {min} to {max} digits long. Callers won't be able to reach them until their IDs or these lengths change."
          }, { min: p.min, max: p.max }),
          t("sl_SaveAnyway", "Save Anyway")
        ).then((ok) => {
          if (ok) saveCallFlow(true);
        });
        return;
      }
      showError(saveFailed(xhr));
      if (body && body.params && body.params.field) showField(body.params.field, saveFailed(xhr));
    }));
  }
  function toggleLine(on) {
    const go = () => busy($id("sl-toggle"), () => post("saveCallFlow", { settings: JSON.stringify({ active: on }) }).then(renderCallFlow).fail((xhr) => showError(saveFailed(xhr))));
    if (state.dirty) {
      showError(t("sl_SaveFirst", "Save your changes first, or reload the page to undo them."));
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
    if (state.dirty) {
      showError(t("sl_SaveFirst", "Save your changes first, or reload the page to undo them."));
      return;
    }
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
    const name = s.prompt_id ? soundName(s.prompt_id) : null;
    const missing = s.prompt_id ? el("span", { class: "sl-warn-text", text: t("sl_PromptGone", "Sound file missing: choose another") }) : el("span", { class: "sl-warn-text", text: t("sl_NotRecorded", "Not recorded yet") });
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
        el("div", { class: "sl-prompt-cell" }, name ? listen : null, name ? el("span", { text: name }) : missing),
        !name && s.script ? el("div", { class: "sl-sub-line sl-script", text: `\u201C${s.script}\u201D` }) : null,
        s.spoken_extra !== "none" ? el("div", { class: "sl-sub-line", text: `+ ${label(EXTRAS[s.spoken_extra])}` }) : null,
        s.requires_pin ? el("div", { class: "sl-sub-line" }, el("i", { class: "lock icon" }), t("sl_AsksPin", "Asks for the record's PIN")) : null,
        s.requires_callerid ? el("div", { class: "sl-sub-line" }, el("i", { class: "phone icon" }), t("sl_OwnPhoneOnly", "Only for the record's phone number")) : null
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
  function templatePicker(firstRun) {
    const templates = state.templates.filter((tpl) => firstRun || tpl.statuses.length);
    return el(
      "div",
      { class: "sl-templates" },
      el(
        "div",
        { class: "sl-section-head" },
        el("h4", { class: "ui header", text: firstRun ? t("sl_FirstRunTitle", "What Kind of Business Is This?") : t("sl_AddFromTemplateTitle", "Add Statuses from a Template") }),
        firstRun ? null : el(
          "button",
          { class: "ui basic button", type: "button", onclick: () => {
            state.showTemplates = false;
            renderStatuses(state.statuses);
          } },
          t("sl_Close", "Close")
        )
      ),
      el("div", { class: "sl-note sl-narrow", text: firstRun ? t("sl_FirstRunHint", "A template adds the statuses that business usually needs, with the wording to record for each, and sets how long IDs are. Everything can be changed afterwards.") : t("sl_AddFromTemplateHint", "Only statuses you don't have yet (by code) are added; nothing is changed or deleted.") }),
      el("div", { class: "sl-template-cards" }, templates.map((tpl) => el(
        "div",
        { class: "sl-template-card" },
        el("div", { class: "sl-template-name", text: tpl.name }),
        el("div", { class: "sl-sub-line", text: tpl.description }),
        el("div", { class: "sl-sub-line", text: tpl.id_length[0] === tpl.id_length[1] ? plural("sl_TemplateIdExact", tpl.id_length[0], { one: "IDs of {n} digit", other: "IDs of {n} digits" }) : sub(t("sl_TemplateIdLength", "IDs of {min}\u2013{max} digits"), { min: tpl.id_length[0], max: tpl.id_length[1] }) }),
        tpl.statuses.length ? el("ul", { class: "sl-template-statuses" }, tpl.statuses.map((x) => el("li", { text: x.name }))) : null,
        el(
          "button",
          { class: "ui basic button", type: "button", onclick: (e) => applyTemplate(tpl, e.currentTarget) },
          tpl.statuses.length ? t("sl_UseTemplate", "Use This Template") : t("sl_StartEmpty", "Start from Scratch")
        )
      )))
    );
  }
  function applyTemplate(tpl, button) {
    busy(button, () => post("applyTemplate", { key: tpl.key }).then((d) => {
      state.showTemplates = false;
      state.template = state.template || tpl.key;
      renderStatuses(d.statuses);
      if (d.added.length) {
        $id("sl-statuses").prepend(el("div", { class: "ui positive message" }, el("p", {
          text: plural("sl_TemplateAdded", d.added.length, { one: "{n} status added. Record its sentence and choose it with Edit.", other: "{n} statuses added. Record each sentence and choose it with Edit; Download Wording lists what to record." })
        })));
      }
    }).fail((xhr) => showError(saveFailed(xhr))));
  }
  function downloadWording() {
    const lines = state.statuses.filter((x) => x.script).map((x) => `${x.name} (${x.code})\r
${x.script}\r
`);
    download("status-lookup-wording.txt", new Blob([lines.join("\r\n")], { type: "text/plain" }));
  }
  function renderStatuses(statuses) {
    state.statuses = statuses;
    const box = $id("sl-statuses");
    const firstRun = !statuses.length && !state.template;
    const head = el(
      "div",
      { class: "sl-section-head" },
      el("h4", { class: "ui header", text: t("sl_StatusesTitle", "Statuses") }),
      el(
        "div",
        { class: "sl-section-actions" },
        statuses.some((x) => x.script && !x.prompt_id) ? el(
          "button",
          { class: "ui basic button", type: "button", onclick: downloadWording },
          el("i", { class: "download icon" }),
          t("sl_DownloadWording", "Download Wording")
        ) : null,
        !firstRun ? el(
          "button",
          { class: "ui basic button", type: "button", onclick: () => {
            state.showTemplates = !state.showTemplates;
            renderStatuses(state.statuses);
          } },
          t("sl_AddFromTemplate", "Add from Template\u2026")
        ) : null,
        el("button", { class: "ui primary button", type: "button", onclick: () => openStatus(null) }, el("i", { class: "plus icon" }), t("sl_AddStatus", "Add Status\u2026"))
      )
    );
    const intro = el("div", { class: "sl-note sl-narrow", text: t("sl_StatusesHint", "Each status is one recorded sentence the caller hears for their ID, and what happens next. The code is what CSV files and other software use.") });
    if (firstRun) {
      fill(box, head, intro, templatePicker(true));
      return;
    }
    const picker = state.showTemplates ? templatePicker(false) : null;
    if (!statuses.length) {
      fill(box, head, intro, picker, el("div", { class: "ui message" }, el("p", { text: t("sl_NoStatuses", 'No statuses yet. Add the ones your business uses, such as "Ready for pickup", with a recording of each sentence.') })));
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
    fill(box, head, intro, picker, table);
  }
  function loadStatuses() {
    fill($id("sl-statuses"), el("div", { class: "ui active centered inline loader" }));
    return get("statuses").then((d) => {
      state.templates = d.templates;
      state.template = d.template;
      renderStatuses(d.statuses);
    });
  }
  function moveStatus(id, step) {
    post("moveStatus", { id, step }).then((d) => renderStatuses(d.statuses)).fail((xhr) => showError(saveFailed(xhr)));
  }
  function openStatus(s) {
    const v = s || { code: "", name: "", script: "", prompt_id: "", spoken_extra: "none", after_action: "repeat", transfer_number: "", requires_pin: false, requires_callerid: false };
    const files = state.options.soundFiles.map((f) => [f.id, f.name]);
    const transferField = field(
      t("sl_TransferTo", "Put Through To"),
      select("sl-status-transfer", destinationOptions(t("sl_ChooseDestination", "Choose\u2026"), v.transfer_number), v.transfer_number, { class: "ui search dropdown" })
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
      field(
        t("sl_StatusScript", "Wording"),
        el("input", { type: "text", id: "sl-status-script", value: v.script || "", maxlength: "300" }),
        t("sl_StatusScriptHint", "What the sentence says, for whoever records it. Not played to callers.")
      ),
      files.length ? field(
        t("sl_StatusPrompt", "Sentence the Caller Hears"),
        promptControl("sl-status-prompt", v.prompt_id || "", false, [], t("sl_ChooseSoundFile", "Choose a Sound File\u2026")),
        t("sl_StatusPromptHint", "Upload a recording of the sentence on the PBX's Sound files page, then choose it here. Keep it short: one sentence.")
      ) : el("div", { class: "ui warning message" }, el("p", { text: t("sl_NoSoundFiles", "The PBX has no sound files yet. Upload a recording of the sentence on its Sound files page first.") })),
      field(
        t("sl_StatusExtra", "Then Also Say"),
        select("sl-status-extra", Object.entries(EXTRAS).map(([k, pair]) => [k, label(pair)]), v.spoken_extra, { class: "ui dropdown" }),
        t("sl_StatusExtraHint", "The date is the one stored with each record, such as a shipping date.")
      ),
      field(t("sl_StatusAfter", "After That"), select("sl-status-after", Object.entries(ACTIONS).map(([k, pair]) => [k, label(pair)]), v.after_action, { class: "ui dropdown" })),
      transferField,
      el(
        "div",
        { class: "grouped fields" },
        el("label", { text: t("sl_StatusPrivacy", "Who Hears It") }),
        checkboxField(
          "sl-status-pin",
          v.requires_pin,
          t("sl_StatusPin", "Ask for the record's PIN first"),
          t("sl_StatusPinHint", "For private statuses. Callers whose record has no PIN are put through to the operator.")
        ),
        checkboxField(
          "sl-status-callerid",
          v.requires_callerid,
          t("sl_StatusCallerId", "Only when calling from the record's phone number"),
          t("sl_StatusCallerIdHint", 'Other callers hear "not found"; callers whose record has no phone number are put through to the operator.')
        )
      )
    );
    $(form).find(".ui.checkbox").checkbox();
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
      script: $id("sl-status-script").value,
      prompt_id: prompt ? prompt.value : "",
      spoken_extra: $id("sl-status-extra").value,
      after_action: $id("sl-status-after").value,
      transfer_number: $id("sl-status-transfer").value,
      requires_pin: $id("sl-status-pin").checked,
      requires_callerid: $id("sl-status-callerid").checked
    };
    busy($id("sl-status-save"), () => post("saveStatus", { status: JSON.stringify(status) }).then((d) => {
      $("#sl-status-modal").modal("hide");
      renderStatuses(d.statuses);
    }).fail((xhr) => showModalError("sl-status-form", saveFailed(xhr))));
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
  var pbxZone = () => state.options && state.options.timezone || void 0;
  var shortDateTime = (epoch) => epoch ? new Date(epoch * 1e3).toLocaleString(LOCALE, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: pbxZone() }) : "\u2014";
  var isoText = (iso) => iso ? (/* @__PURE__ */ new Date(`${iso}T00:00:00`)).toLocaleDateString(LOCALE, { day: "numeric", month: "short", year: "numeric" }) : "";
  function statusLabel(code) {
    const index = (state.statuses || []).findIndex((s) => s.code === code);
    const status = index >= 0 ? state.statuses[index] : null;
    const label2 = el(
      "span",
      { class: `ui small ${index >= 0 ? STATUS_COLOURS[index % STATUS_COLOURS.length] : "basic"} label`, title: code },
      status ? status.name : code
    );
    if (!status || status.prompt_id) return label2;
    const text = t("sl_StatusNotRecorded", "Not recorded yet: callers are put through to a person");
    return el("span", { class: "sl-nowrap" }, label2, el("i", { class: "exclamation triangle icon sl-warn-text", title: text, "aria-label": text, role: "img" }));
  }
  var statusChoices = (allText) => [...allText ? [["", allText]] : [], ...(state.statuses || []).map((s) => [s.code, s.name])];
  function recordsToolbar() {
    const search = el("input", { type: "search", id: "sl-rec-search", value: records.query.search, placeholder: t("sl_SearchRecords", "ID or note"), "aria-label": t("sl_SearchRecords", "ID or note") });
    search.addEventListener("input", () => {
      clearTimeout(records.timer);
      records.timer = setTimeout(() => {
        records.query.search = search.value;
        records.query.page = 1;
        records.selected.clear();
        loadRecords();
      }, 300);
    });
    const status = select("sl-rec-status", statusChoices(t("sl_AllStatuses", "All Statuses")), records.query.status, { class: "ui dropdown", "aria-label": t("sl_ColStatus", "Status") });
    const stale = select(
      "sl-rec-stale",
      [["0", t("sl_AnyUpdate", "Changed Any Time")], ...[7, 30, 90].map((d) => [String(d), plural("sl_NotUpdatedIn", d, { one: "Not Changed in {n} Day", other: "Not Changed in {n} Days" })])],
      records.query.stale,
      { class: "ui dropdown", "aria-label": t("sl_LastChange", "Last Change") }
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
      records.selected.clear();
      loadRecords();
    }));
    expired.querySelector("input").addEventListener("change", (e) => {
      records.query.expired = e.target.checked ? "1" : "";
      records.query.page = 1;
      records.selected.clear();
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
    const to = select("sl-bulk-status", statusChoices(null), "", { class: "ui dropdown", "aria-label": t("sl_NewStatus", "New Status") });
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
  function changedBy(r) {
    const how = SOURCES[r.source] && !(r.source === "api" && /^API key/.test(r.updated_by)) ? label(SOURCES[r.source]) : "";
    const text = [r.updated_by, how].filter(Boolean).join(" \xB7 ");
    return el("div", { class: "sl-sub-line sl-ellipsis", text, title: text });
  }
  function recordRow(r, today, withDate) {
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
      el(
        "td",
        {},
        el(
          "div",
          { class: "sl-code" },
          r.lookup_id,
          r.has_pin ? el("i", { class: "lock icon sl-pin-mark", title: t("sl_HasPin", "Has a PIN"), "aria-label": t("sl_HasPin", "Has a PIN") }) : null
        ),
        r.phone ? el("div", { class: "sl-sub-line", text: r.phone }) : null
      ),
      el("td", {}, statusLabel(r.status_code)),
      withDate ? el("td", { class: "sl-nowrap", text: isoText(r.status_date) }) : null,
      el("td", { class: "sl-nowrap" }, expired ? el("span", { class: "sl-warn-text", text: sub(t("sl_ExpiredOn", "Expired {date}"), { date: isoText(r.expires_on) }) }) : isoText(r.expires_on)),
      el("td", { class: "sl-note-cell", text: r.note }),
      // Who changed it may be a long API key name: it wraps, the date doesn't.
      el("td", { class: "sl-changed-cell" }, el("div", { class: "sl-nowrap", text: shortDateTime(r.updated_at) }), changedBy(r)),
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
      fill(box, el(
        "div",
        { class: "ui message" },
        el("p", { text: t("sl_RecordsNeedStatuses", "Add statuses first: every record has one.") }),
        el("button", { class: "ui primary button", type: "button", onclick: () => selectTab("statuses") }, t("sl_SetUpStatuses", "Set Up Statuses"))
      ));
      return;
    }
    const rows = d ? d.rows : [];
    const all = rows.length > 0 && rows.every((r) => records.selected.has(r.id));
    const allBox = el("input", { type: "checkbox", checked: all, "aria-label": t("sl_SelectPage", "Select all on this page") });
    allBox.addEventListener("change", () => {
      rows.forEach((r) => allBox.checked ? records.selected.add(r.id) : records.selected.delete(r.id));
      renderRecords();
    });
    const withDate = rows.some((r) => r.status_date);
    const filtered = records.query.search || records.query.status || records.query.stale !== "0" || records.query.expired;
    const table = rows.length ? el("div", { class: "sl-table-scroll" }, el(
      "table",
      { class: "ui very basic table sl-table" },
      el("thead", {}, el(
        "tr",
        {},
        el("th", { class: "sl-check-cell" }, allBox),
        el("th", { text: t("sl_ColId", "ID") }),
        el("th", { text: t("sl_ColStatus", "Status") }),
        withDate ? el("th", { text: t("sl_ColDate", "Date") }) : null,
        el("th", { text: t("sl_ColLastDay", "Last Day") }),
        el("th", { text: t("sl_ColNote", "Note") }),
        el("th", { text: t("sl_ColChanged", "Last Change") }),
        el("th", {})
      )),
      el("tbody", {}, rows.map((r) => recordRow(r, d.today, withDate)))
    )) : el("div", { class: "ui message" }, el("p", { text: filtered ? t("sl_NoMatches", "No records match these filters.") : t("sl_NoRecords", "No records yet. Add them one by one, or import a CSV file from your shop software or spreadsheet.") }));
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
    if (!$id("sl-rec-toolbar")) {
      fill(box, el("div", { id: "sl-rec-toolbar" }, recordsToolbar()), el("div", { id: "sl-rec-body" }));
      $("#sl-rec-toolbar select.ui.dropdown").dropdown({ placeholder: false });
      $("#sl-rec-toolbar .ui.checkbox").checkbox();
    }
    const body = $id("sl-rec-body");
    fill(
      body,
      d ? el("div", { class: "sl-note sl-count", text: plural("sl_RecordCount", d.total, { one: "{n} record", other: "{n} records" }) }) : null,
      bulkBar(),
      table,
      pager
    );
    $(body).find("select.ui.dropdown").dropdown({ placeholder: false });
  }
  function loadRecords() {
    const seq = records.seq = (records.seq || 0) + 1;
    return get("records", records.query).then((d) => {
      if (seq !== records.seq) return;
      records.data = d;
      records.query.page = d.page;
      renderRecords();
    });
  }
  function loadRecordsTab() {
    fill($id("sl-records"), el("div", { class: "ui active centered inline loader" }));
    get("statuses").then((d) => {
      state.statuses = d.statuses;
      if (records.query.status && !d.statuses.some((x) => x.code === records.query.status)) records.query.status = "";
      return loadRecords();
    });
  }
  function openRecord(r) {
    const v = r || { lookup_id: "", status_code: state.statuses[0].code, note: "", status_date: "", expires_on: "", phone: "", has_pin: false };
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
      ),
      el(
        "div",
        { class: "two fields" },
        field(
          t("sl_ColPhone", "Phone"),
          el("input", { type: "tel", id: "sl-record-phone", value: v.phone, inputmode: "numeric", maxlength: "24", autocomplete: "off" }),
          t("sl_RecordPhoneHint", "The customer's phone number, for statuses only callers from this number may hear.")
        ),
        field(
          t("sl_ColPin", "PIN"),
          el("input", {
            type: "password",
            id: "sl-record-pin",
            value: "",
            inputmode: "numeric",
            maxlength: "8",
            autocomplete: "new-password",
            placeholder: v.has_pin ? t("sl_PinKept", "Unchanged") : ""
          }),
          v.has_pin ? t("sl_RecordPinSetHint", "This record has a PIN; it can't be shown. Type a new one to replace it.") : t("sl_RecordPinHint", "4 to 8 digits, for statuses that ask for one. Stored scrambled; it can't be shown later.")
        )
      ),
      v.has_pin ? checkboxField("sl-record-remove-pin", false, t("sl_RemovePin", "Remove the PIN")) : null
    );
    $(form).find("select.ui.dropdown").dropdown({ placeholder: false });
    $(form).find(".ui.checkbox").checkbox();
    const remove = $id("sl-record-remove-pin");
    if (remove) {
      remove.addEventListener("change", () => {
        const pin = $id("sl-record-pin");
        pin.disabled = remove.checked;
        if (remove.checked) pin.value = "";
      });
    }
    $id("sl-record-title").textContent = r ? t("sl_EditRecord", "Edit Record") : t("sl_AddRecordTitle", "Add Record");
    const save = () => saveRecord(r ? r.id : null);
    $id("sl-record-save").onclick = save;
    $id("sl-record-cancel").onclick = () => $("#sl-record-modal").modal("hide");
    form.onsubmit = (e) => {
      e.preventDefault();
      save();
    };
    $("#sl-record-modal").modal({ autofocus: false, onVisible: () => {
      if (!r) $id("sl-record-id").focus();
    } }).modal("show");
  }
  function saveRecord(id) {
    const record = {
      ...id ? { id } : {},
      lookup_id: $id("sl-record-id").value,
      status_code: $id("sl-record-status").value,
      note: $id("sl-record-note").value,
      status_date: $id("sl-record-date").value,
      expires_on: $id("sl-record-expires").value,
      phone: $id("sl-record-phone").value,
      pin: $id("sl-record-pin").value,
      remove_pin: !!($id("sl-record-remove-pin") && $id("sl-record-remove-pin").checked)
    };
    busy($id("sl-record-save"), () => post("saveRecord", { record: JSON.stringify(record) }).then(() => {
      $("#sl-record-modal").modal("hide");
      return loadRecords();
    }).fail((xhr) => showModalError("sl-record-form", saveFailed(xhr))));
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
    confirmDialog(single ? t("sl_DeleteRecordTitle", "Delete Record?") : t("sl_DeleteRecordsTitle", "Delete Records?"), text, ok).then((yes) => {
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
  var REJECT_REASONS = {
    no_id: ["sl_Reject_no_id", "No ID"],
    id_length: ["sl_Reject_id_length", "ID too short or too long"],
    duplicate: ["sl_Reject_duplicate", "Same ID as an earlier row (its row number is in parentheses)"],
    status: ["sl_Reject_status", "Unknown status code"],
    note: ["sl_Reject_note", "Note longer than 200 characters"],
    pin: ["sl_Reject_pin", "PIN isn't 4 to 8 digits"],
    phone: ["sl_Reject_phone", "Phone number isn't 7 to 20 digits"],
    expires: ["sl_Reject_expires", "Last day isn't a date (YYYY-MM-DD)"],
    date: ["sl_Reject_date", "Date isn't a date (YYYY-MM-DD)"]
  };
  var MODES = {
    update: ["sl_ModeUpdate", "Add and Update", ["sl_ModeUpdateHint", "New IDs are added and existing ones changed. Records not in the file stay as they are."]],
    replace: ["sl_ModeReplace", "Replace All", ["sl_ModeReplaceHint", "The file becomes the full list: records not in it are deleted."]]
  };
  var importState = { file: null, preview: null, mode: "update" };
  var EXAMPLE_CSV = "lookup_id,status_code,note,pin,expires,date,phone\r\n10445,READY,Toyota Vios,,2026-11-30,,\r\n10446,PARTS,iPhone 13 screen,4821,,,4915112345678\r\n00451,SHIPPED,,,,2026-11-12,\r\n";
  function download(filename, blob) {
    const url = URL.createObjectURL(blob);
    const a = el("a", { href: url, download: filename });
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1e3);
  }
  function upload(action) {
    clearError();
    const data = new FormData();
    data.append("file", importState.file);
    data.append("mode", importState.mode);
    return $.ajax({ url: BASE + action, method: "POST", data, processData: false, contentType: false, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r }));
  }
  function rejectTable(report, total) {
    if (!total) return null;
    return el(
      "div",
      {},
      el(
        "table",
        { class: "ui very basic compact table sl-table sl-reject-table" },
        el("thead", {}, el(
          "tr",
          {},
          el("th", { class: "sl-num", text: t("sl_ColRow", "Row") }),
          el("th", { text: t("sl_ColId", "ID") }),
          el("th", { text: t("sl_ColReason", "Why It Was Left Out") })
        )),
        el("tbody", {}, report.map((r) => el(
          "tr",
          {},
          el("td", { class: "sl-num", text: count(r.row) }),
          el("td", { class: "sl-code", text: r.id || "\u2014" }),
          el("td", { text: REJECT_REASONS[r.reason] ? label(REJECT_REASONS[r.reason]) : r.reason })
        )))
      ),
      total > report.length ? el("div", { class: "sl-note", text: sub(t("sl_ReportCapped", "The first {shown} of {n} rows left out are listed."), { shown: count(report.length), n: count(total) }) }) : null
    );
  }
  function countTiles(p) {
    const tile2 = (n, text, kind) => el("div", { class: `sl-tile ${kind || ""}` }, el("div", { class: "sl-tile-value", text: count(n) }), el("div", { class: "sl-tile-label", text }));
    return el(
      "div",
      { class: "sl-tiles" },
      tile2(p.added, t("sl_WillAdd", "New")),
      tile2(p.updated, t("sl_WillUpdate", "Changed")),
      tile2(p.unchanged, t("sl_WillKeep", "Unchanged")),
      tile2(p.deleted, t("sl_WillDelete", "Deleted"), p.deleted ? "sl-tile-bad" : ""),
      tile2(p.rejected, t("sl_WillReject", "Left Out"), p.rejected ? "sl-tile-warn" : "")
    );
  }
  function renderPreview() {
    const p = importState.preview;
    const box = $id("sl-import-preview");
    const check = $id("sl-import-check");
    if (check) check.className = `ui ${importState.file && !p ? "primary" : "basic"} button`;
    if (!p) {
      fill(box);
      return;
    }
    const changes = p.added + p.updated + p.deleted;
    fill(
      box,
      el("h4", { class: "ui header", text: sub(t("sl_PreviewTitle", 'What Importing "{file}" Will Do'), { file: importState.file.name }) }),
      countTiles(p),
      p.kept ? el("div", { class: "sl-note", text: plural("sl_KeptNote", p.kept, { one: "{n} record not in the file is kept, because its row was left out. Fix the row and import again to change it.", other: "{n} records not in the file are kept, because their rows were left out. Fix the rows and import again to change them." }) }) : null,
      rejectTable(p.report, p.rejected),
      el(
        "div",
        { class: "sl-inline sl-import-actions" },
        el(
          "button",
          { class: "ui primary button", type: "button", id: "sl-import-apply", disabled: changes === 0, onclick: applyImport },
          t("sl_ImportNow", "Import")
        ),
        el("button", { class: "ui basic button", type: "button", onclick: () => {
          importState.preview = null;
          renderPreview();
        } }, t("sl_Cancel", "Cancel")),
        changes === 0 ? el("span", { class: "sl-muted", text: t("sl_NothingToChange", "Nothing would change.") }) : null
      )
    );
  }
  function checkFile() {
    if (!importState.file) {
      showError(t("sl_ChooseFile", "Choose a CSV file first."));
      return;
    }
    busy($id("sl-import-check"), () => upload("importPreview").then((p) => {
      importState.preview = p;
      renderPreview();
    }).fail((xhr) => showError(saveFailed(xhr))));
  }
  function applyImport() {
    const p = importState.preview;
    const go = () => busy($id("sl-import-apply"), () => upload("importApply").then((r) => {
      importState.preview = null;
      importState.file = null;
      renderImport(r);
      loadImports();
    }).fail((xhr) => showError(saveFailed(xhr))));
    if (!p.deleted) {
      go();
      return;
    }
    confirmDialog(
      t("sl_ReplaceTitle", "Delete Records Not in the File?"),
      plural("sl_ReplaceText", p.deleted, { one: `{n} record isn't in the file and will be deleted. Callers who type its ID will hear "not found".`, other: `{n} records aren't in the file and will be deleted. Callers who type their IDs will hear "not found".` }),
      plural("sl_ReplaceButton", p.deleted, { one: "Import and Delete {n} Record", other: "Import and Delete {n} Records" })
    ).then((ok) => {
      if (ok) go();
    });
  }
  function chooseFile(file) {
    importState.file = file || null;
    importState.preview = null;
    $id("sl-import-name").textContent = file ? file.name : t("sl_NoFileChosen", "No file chosen");
    renderPreview();
  }
  function renderImport(done) {
    const input = el("input", { type: "file", id: "sl-import-file", accept: ".csv,text/csv", class: "sl-visually-hidden" });
    input.addEventListener("change", () => {
      chooseFile(input.files[0]);
      input.value = "";
    });
    const drop = el(
      "label",
      { class: "sl-drop", for: "sl-import-file" },
      el("i", { class: "big file alternate outline icon" }),
      el("div", { class: "sl-drop-text", text: t("sl_DropFile", "Drop a CSV file here, or click to choose one") }),
      el("div", { class: "sl-drop-name", id: "sl-import-name", text: importState.file ? importState.file.name : t("sl_NoFileChosen", "No file chosen") }),
      input
    );
    ["dragenter", "dragover"].forEach((type) => drop.addEventListener(type, (e) => {
      e.preventDefault();
      drop.classList.add("sl-drag");
    }));
    ["dragleave", "drop"].forEach((type) => drop.addEventListener(type, () => drop.classList.remove("sl-drag")));
    drop.addEventListener("drop", (e) => {
      e.preventDefault();
      chooseFile(e.dataTransfer.files[0]);
    });
    const mode = select("sl-import-mode", Object.entries(MODES).map(([k, m]) => [k, label(m)]), importState.mode, { class: "ui dropdown" });
    const modeHint = el("div", { class: "sl-note", id: "sl-import-mode-hint" });
    mode.addEventListener("change", () => {
      importState.mode = mode.value;
      modeHint.textContent = label(MODES[mode.value][2]);
      importState.preview = null;
      renderPreview();
    });
    fill(
      $id("sl-import"),
      done ? el("div", { class: "ui positive message" }, el("p", { text: sub(
        t("sl_ImportDone", "Imported: {added} new, {updated} changed, {deleted} deleted, {rejected} left out."),
        { added: count(done.added), updated: count(done.updated), deleted: count(done.deleted), rejected: count(done.rejected) }
      ) })) : null,
      el(
        "div",
        { class: "sl-section-head" },
        el("h4", { class: "ui header", text: t("sl_ImportTitle", "Import Records") }),
        el(
          "div",
          { class: "sl-section-actions" },
          el(
            "button",
            { class: "ui basic button", type: "button", onclick: () => download("status-lookup-example.csv", new Blob([EXAMPLE_CSV], { type: "text/csv" })) },
            el("i", { class: "download icon" }),
            t("sl_DownloadExample", "Download Example")
          )
        )
      ),
      el("div", { class: "sl-note sl-narrow", text: t("sl_ImportHint", "A UTF-8 CSV file with a header row. Needed: lookup_id, status_code. Optional: note, date and expires (YYYY-MM-DD; expires is the last day), pin (4 to 8 digits), phone. An empty pin keeps the PIN; an empty phone removes it. Keep PINs as text in your spreadsheet so leading zeros stay. Nothing changes until you check the file and press Import.") }),
      el(
        "div",
        { class: "ui form sl-import-form" },
        drop,
        el(
          "div",
          { class: "sl-inline" },
          field(t("sl_ImportMode", "Mode"), mode),
          el("button", { class: "ui button", type: "button", id: "sl-import-check", onclick: checkFile }, t("sl_CheckFile", "Check File"))
        ),
        modeHint
      ),
      el("div", { id: "sl-import-preview" }),
      el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_ImportHistory", "Past Imports") })),
      el("div", { id: "sl-import-history" })
    );
    $(mode).dropdown({ placeholder: false });
    modeHint.textContent = label(MODES[importState.mode][2]);
    renderPreview();
  }
  function renderHistory(imports) {
    const box = $id("sl-import-history");
    if (!imports.length) {
      fill(box, el("div", { class: "sl-muted", text: t("sl_NoImports", "No imports yet.") }));
      return;
    }
    fill(box, el(
      "table",
      { class: "ui very basic table sl-table" },
      el("thead", {}, el(
        "tr",
        {},
        el("th", { text: t("sl_ColWhen", "When") }),
        el("th", { text: t("sl_ColFile", "File") }),
        el("th", { text: t("sl_ImportMode", "Mode") }),
        el("th", { class: "sl-num", text: t("sl_WillAdd", "New") }),
        el("th", { class: "sl-num", text: t("sl_WillUpdate", "Changed") }),
        el("th", { class: "sl-num", text: t("sl_WillDelete", "Deleted") }),
        el("th", { class: "sl-num", text: t("sl_WillReject", "Left Out") })
      )),
      el("tbody", {}, imports.map((i) => el(
        "tr",
        {},
        el("td", {}, el("div", { text: shortDateTime(i.imported_at) }), el("div", { class: "sl-sub-line", text: i.imported_by })),
        el("td", { class: "sl-note-cell", text: i.file_name }),
        el("td", { text: MODES[i.mode] ? label(MODES[i.mode]) : i.mode }),
        el("td", { class: "sl-num", text: count(i.added) }),
        el("td", { class: "sl-num", text: count(i.updated) }),
        el("td", { class: "sl-num", text: count(i.deleted) }),
        el("td", { class: "sl-num" }, i.rejected ? el("a", { href: "#", text: count(i.rejected), onclick: (e) => {
          e.preventDefault();
          showRejected(i);
        } }) : count(i.rejected))
      )))
    ));
  }
  function showRejected(i) {
    confirmDialog(sub(t("sl_RejectedTitle", 'Rows Left Out of "{file}"'), { file: i.file_name }), "", t("sl_Close", "Close"), rejectTable(i.report, i.rejected), true);
  }
  function loadImports() {
    get("imports").then((d) => renderHistory(d.imports));
  }
  function loadImportTab() {
    renderImport(null);
    loadImports();
  }
  var SHADES = {
    grey: ["#f5f7fa", "#e4e7eb", "#cbd2d9", "#9aa5b1", "#7b8794", "#616e7c", "#52606d", "#3e4c59", "#323f4b", "#1f2933"],
    blue: ["#ebf4fc", "#cfe5f7", "#a5cdef", "#6eafe3", "#3d93d8", "#2185d0", "#1a6fb0", "#155a8f", "#10456e", "#0b3250"],
    green: ["#e3f9e5", "#c1eac5", "#a3d9a5", "#7bc47f", "#57ae5b", "#3f9142", "#2f8132", "#207227", "#0e5814", "#05400a"]
  };
  var G = SHADES.grey;
  var PALETTE = { calls: G[2], hits: SHADES.blue[5], deflected: SHADES.green[5] };
  var themeReady = false;
  function registerTheme() {
    if (themeReady || typeof echarts === "undefined") return;
    themeReady = true;
    echarts.registerTheme("status-lookup", {
      color: [PALETTE.hits, PALETTE.deflected, G[3]],
      backgroundColor: "transparent",
      textStyle: { color: G[7] },
      legend: { textStyle: { color: G[7] }, inactiveColor: G[2] },
      tooltip: { backgroundColor: "#fff", borderColor: G[2], borderWidth: 1, textStyle: { color: G[8] }, extraCssText: "box-shadow: 0 2px 8px rgba(31, 41, 51, .12);" },
      categoryAxis: { axisLine: { lineStyle: { color: G[2] } }, axisTick: { show: false }, axisLabel: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } },
      valueAxis: { axisLine: { show: false }, axisLabel: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } }
    });
  }
  var OUTCOMES = {
    hit: ["sl_Outcome_hit", "Heard their status, no person needed"],
    hit_person: ["sl_Outcome_hit_person", "Heard their status, then put through"],
    operator: ["sl_Outcome_operator", "Pressed 0 for a person"],
    too_many: ["sl_Outcome_too_many", "Too many tries, put through"],
    rate_limited: ["sl_Outcome_rate_limited", "Too many misses, put through"],
    fault: ["sl_Outcome_fault", "Lookup failed, put through"],
    abandoned: ["sl_Outcome_abandoned", "Hung up in the lookup"],
    inactive: ["sl_Outcome_inactive", "Line was switched off"]
  };
  var outcomeText = (key) => OUTCOMES[key] ? label(OUTCOMES[key]) : key;
  var activity = { charts: {}, outcome: "", page: 1 };
  var esc = (v) => echarts.format.encodeHTML(String(v));
  var shortDate = (iso) => (/* @__PURE__ */ new Date(`${iso}T00:00:00`)).toLocaleDateString(LOCALE, { day: "numeric", month: "short" });
  var pct = (part, whole) => whole ? Math.round(part / whole * 100) : null;
  function chart(id) {
    registerTheme();
    if (activity.charts[id] && activity.charts[id].getDom() !== $id(id)) {
      activity.charts[id].dispose();
      delete activity.charts[id];
    }
    if (!activity.charts[id]) activity.charts[id] = echarts.init($id(id), "status-lookup", { renderer: "canvas" });
    return activity.charts[id];
  }
  var pbxToday = () => new Intl.DateTimeFormat("en-CA", { year: "numeric", month: "2-digit", day: "2-digit", timeZone: pbxZone() }).format(/* @__PURE__ */ new Date());
  function presetRange(days) {
    const to = pbxToday();
    const from = /* @__PURE__ */ new Date(`${to}T12:00:00Z`);
    from.setUTCDate(from.getUTCDate() - (days - 1));
    return [from.toISOString().slice(0, 10), to];
  }
  function activityToolbar() {
    const preset = select("sl-act-preset", [
      ["7", t("sl_Last7", "Last 7 Days")],
      ["30", t("sl_Last30", "Last 30 Days")],
      ["90", t("sl_Last90", "Last 90 Days")],
      ["365", t("sl_Last365", "Last 365 Days")],
      ["custom", t("sl_Custom", "Custom")]
    ], activity.preset || "30", { class: "ui dropdown", "aria-label": t("sl_Period", "Period") });
    const from = el("input", { type: "date", id: "sl-act-from", value: activity.from, "aria-label": t("sl_From", "From") });
    const to = el("input", { type: "date", id: "sl-act-to", value: activity.to, "aria-label": t("sl_To", "To") });
    preset.addEventListener("change", () => {
      activity.preset = preset.value;
      if (preset.value === "custom") return;
      [activity.from, activity.to] = presetRange(Number(preset.value));
      from.value = activity.from;
      to.value = activity.to;
      loadActivity();
    });
    [from, to].forEach((input) => input.addEventListener("change", () => {
      activity.preset = "custom";
      $(preset).dropdown("set selected", "custom");
      activity.from = from.value;
      activity.to = to.value;
      loadActivity();
    }));
    return el("div", { class: "ui form sl-toolbar" }, preset, el("div", { class: "sl-range" }, from, el("span", { class: "sl-muted", text: "\u2013" }), to));
  }
  function tile(labelText, value, sub2, change) {
    return el(
      "div",
      { class: "sl-tile" },
      el("div", { class: "sl-tile-label", text: labelText }),
      el("div", { class: `sl-tile-value${value === null ? " sl-empty" : ""}`, text: value === null ? "\u2014" : value }),
      sub2 ? el("div", { class: "sl-sub-line", text: sub2 }) : null,
      change
    );
  }
  function changeLine(cur, prev) {
    if (prev === null || cur === null || prev === void 0) return null;
    const diff = cur - prev;
    const text = diff === 0 ? t("sl_NoChange", "Same as the previous period") : sub(t("sl_ChangeVs", "{diff} from the previous period"), { diff: `${diff > 0 ? "+" : "\u2212"}${count(Math.abs(diff))}` });
    return el("div", { class: "sl-change", text });
  }
  function changePoints(cur, prev) {
    if (cur === null || prev === null) return null;
    const diff = cur - prev;
    const text = diff === 0 ? t("sl_NoChange", "Same as the previous period") : sub(t("sl_ChangePoints", "{diff} points from the previous period"), { diff: `${diff > 0 ? "+" : "\u2212"}${Math.abs(diff)}` });
    return el("div", { class: "sl-change", text });
  }
  function renderActivity(a) {
    const tot = a.totals;
    const prev = a.previous;
    const rate = pct(tot.deflected, tot.calls);
    const prevRate = pct(prev.deflected, prev.calls);
    const box = $id("sl-activity-body");
    if (!tot.calls) {
      Object.values(activity.charts).forEach((c) => c.dispose());
      activity.charts = {};
      fill(box, el("div", { class: "ui message" }, el("p", { text: t("sl_NoCalls", "No calls to the lookup line in this period.") })));
      renderCallLog();
      return;
    }
    fill(
      box,
      el(
        "div",
        { class: "sl-tiles sl-tiles-4" },
        // Comparisons only make sense against a period that had calls (not before the line was set up).
        tile(t("sl_TileCalls", "Calls"), count(tot.calls), null, prev.calls ? changeLine(tot.calls, prev.calls) : el("div", { class: "sl-change", text: t("sl_NoPreviousCalls", "No calls in the previous period") })),
        tile(t("sl_TileHeard", "Heard Their Status"), count(tot.hits), sub(t("sl_OfCalls", "{pct}% of calls"), { pct: pct(tot.hits, tot.calls) }), prev.calls ? changeLine(tot.hits, prev.hits) : null),
        tile(t("sl_TileDeflected", "Needed No Person"), rate === null ? null : `${rate}%`, plural("sl_CallsCount", tot.deflected, { one: "{n} call", other: "{n} calls" }), prev.calls ? changePoints(rate, prevRate) : null),
        tile(t("sl_TileToPerson", "Put Through to a Person"), count(tot.to_person), sub(t("sl_OfCalls", "{pct}% of calls"), { pct: pct(tot.to_person, tot.calls) }), prev.calls ? changeLine(tot.to_person, prev.to_person) : null)
      ),
      el("div", { class: "sl-note", text: t("sl_DeflectedNote", '"Needed No Person": the caller heard a status and the call ended without reaching anyone. Callers who hang up before hearing a status still count as calls.') }),
      el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_ByDay", "Calls by Day") })),
      el("div", { class: "sl-chart", id: "sl-chart-trend" }),
      el(
        "div",
        { class: "sl-two-col" },
        el(
          "div",
          {},
          el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_HowCallsEnded", "How Calls Ended") })),
          el("div", { class: "sl-chart sl-chart-short", id: "sl-chart-outcomes" })
        ),
        el(
          "div",
          {},
          el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_BusiestHours", "Busiest Hours") })),
          el("div", { class: "sl-chart sl-chart-short", id: "sl-chart-hours" })
        )
      ),
      notFoundTable(a.notFound)
    );
    renderTrend(a.trend);
    renderOutcomes(a.outcomes);
    renderHours(a.hours);
  }
  function renderTrend(points) {
    const c = chart("sl-chart-trend");
    const names = [t("sl_TileCalls", "Calls"), t("sl_TileHeard", "Heard Their Status"), t("sl_TileDeflected", "Needed No Person")];
    c.setOption({
      grid: { left: 40, right: 16, top: 36, bottom: 28 },
      legend: { data: names, top: 0, right: 0, icon: "roundRect", itemWidth: 12, itemHeight: 8 },
      tooltip: {
        trigger: "axis",
        formatter: (params) => {
          const p = points[params[0].dataIndex];
          return `${esc(shortDate(p.day))}<br>${esc(names[0])}: <b>${esc(count(p.calls))}</b><br>${esc(names[1])}: ${esc(count(p.hits))}<br>${esc(names[2])}: ${esc(count(p.deflected))}`;
        }
      },
      xAxis: { type: "category", data: points.map((p) => shortDate(p.day)) },
      yAxis: { type: "value", minInterval: 1 },
      series: [
        { name: names[0], type: "bar", barMaxWidth: 28, data: points.map((p) => p.calls), itemStyle: { color: PALETTE.calls, borderRadius: [2, 2, 0, 0] } },
        { name: names[1], type: "line", symbol: "circle", symbolSize: 6, data: points.map((p) => p.hits), lineStyle: { color: PALETTE.hits, width: 2 }, itemStyle: { color: PALETTE.hits } },
        { name: names[2], type: "line", symbol: "circle", symbolSize: 6, data: points.map((p) => p.deflected), lineStyle: { color: PALETTE.deflected, width: 2 }, itemStyle: { color: PALETTE.deflected } }
      ]
    }, true);
  }
  function renderOutcomes(outcomes) {
    const c = chart("sl-chart-outcomes");
    const rows = [...outcomes].reverse();
    const colour = (k) => k === "hit" ? PALETTE.deflected : k === "hit_person" ? PALETTE.hits : G[4];
    c.setOption({
      grid: { left: 8, right: 40, top: 8, bottom: 8, containLabel: true },
      tooltip: { trigger: "item", formatter: (p) => `${esc(outcomeText(rows[p.dataIndex].outcome))}: <b>${esc(count(p.value))}</b>` },
      xAxis: { type: "value", minInterval: 1, show: false },
      // Long labels (Russian, phones) wrap instead of being cut off.
      yAxis: { type: "category", data: rows.map((r) => outcomeText(r.outcome)), axisLabel: { width: Math.min(220, Math.round(c.getWidth() * 0.45)), overflow: "break" } },
      series: [{
        type: "bar",
        barMaxWidth: 18,
        data: rows.map((r) => ({ value: r.calls, itemStyle: { color: colour(r.outcome), borderRadius: [0, 2, 2, 0] } })),
        label: { show: true, position: "right", color: G[7], formatter: (p) => count(p.value) }
      }]
    }, true);
  }
  function renderHours(hours) {
    const c = chart("sl-chart-hours");
    c.setOption({
      grid: { left: 32, right: 8, top: 8, bottom: 24 },
      tooltip: { trigger: "axis", formatter: (params) => `${esc(`${params[0].dataIndex}:00\u2013${params[0].dataIndex}:59`)}: <b>${esc(count(params[0].value))}</b>` },
      xAxis: { type: "category", data: hours.map((_, h) => String(h)), axisLabel: { interval: 2 } },
      yAxis: { type: "value", minInterval: 1 },
      series: [{ type: "bar", data: hours, barMaxWidth: 14, itemStyle: { color: PALETTE.hits, borderRadius: [2, 2, 0, 0] } }]
    }, true);
  }
  function notFoundTable(ids) {
    return el(
      "div",
      {},
      el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_TopNotFound", "IDs Most Often Not Found") })),
      el("div", { class: "sl-note sl-narrow", text: t("sl_TopNotFoundHint", "Callers typed these, and they weren't found or had expired. A record that is missing or out of date is the usual reason.") }),
      ids.length ? el(
        "table",
        { class: "ui very basic compact table sl-table sl-narrow" },
        el("thead", {}, el("tr", {}, el("th", { text: t("sl_ColId", "ID") }), el("th", { class: "sl-num", text: t("sl_TileCalls", "Calls") }), el("th", {}))),
        el("tbody", {}, ids.map((x) => el(
          "tr",
          {},
          el("td", { class: "sl-code", text: x.id }),
          el("td", { class: "sl-num", text: count(x.calls) }),
          el("td", { class: "sl-actions-cell" }, el("button", { class: "ui basic button", type: "button", onclick: () => findRecord(x.id) }, t("sl_FindInRecords", "Find in Records")))
        )))
      ) : el("div", { class: "sl-muted", text: t("sl_NoneNotFound", "None in this period.") })
    );
  }
  function findRecord(id) {
    records.query = { search: id, status: "", stale: "0", expired: "", page: 1 };
    selectTab("records");
  }
  function renderCallLog() {
    const d = activity.log;
    const box = $id("sl-calllog");
    if (!d) return;
    const filter = select(
      "sl-log-outcome",
      [["", t("sl_AllOutcomes", "All Calls")], ...Object.keys(OUTCOMES).filter((k) => k !== "hit_person").map((k) => [k, outcomeText(k)])],
      activity.outcome,
      { class: "ui dropdown", "aria-label": t("sl_HowCallsEnded", "How Calls Ended") }
    );
    filter.addEventListener("change", () => {
      activity.outcome = filter.value;
      activity.page = 1;
      loadCallLog();
    });
    const rows = d.rows.map((r) => el(
      "tr",
      { class: r.is_test ? "sl-test-call" : "" },
      el("td", { class: "sl-nowrap", text: shortDateTime(r.started_at) }),
      el("td", { class: "sl-code", text: r.caller || t("sl_Withheld", "Withheld") }),
      el("td", { class: "sl-code", text: r.id_entered || "\u2014" }),
      el(
        "td",
        {},
        el("div", { text: outcomeText(r.outcome === "hit" && r.target ? "hit_person" : r.outcome) }),
        r.is_test ? el("div", { class: "sl-sub-line", text: t("sl_IsTestCall", "Test call") }) : null
      ),
      el("td", {}, r.status_code ? statusLabel(r.status_code) : "\u2014"),
      el("td", { text: r.target ? destinationName(r.target) : "\u2014" }),
      el("td", { class: "sl-num", text: plural("sl_Tries", r.tries, { one: "{n} try", other: "{n} tries" }) }),
      el("td", { class: "sl-num", text: seconds(r.seconds) })
    ));
    fill(
      box,
      el("div", { class: "sl-section-head" }, el("h4", { class: "ui header", text: t("sl_CallLog", "Call Log") }), el("div", { class: "sl-section-actions" }, filter)),
      d.rows.length ? el(
        "table",
        { class: "ui very basic table sl-table" },
        el("thead", {}, el(
          "tr",
          {},
          el("th", { text: t("sl_ColWhen", "When") }),
          el("th", { text: t("sl_ColCaller", "Caller") }),
          el("th", { text: t("sl_ColIdEntered", "ID Typed") }),
          el("th", { text: t("sl_ColResult", "How It Ended") }),
          el("th", { text: t("sl_ColStatus", "Status") }),
          el("th", { text: t("sl_ColSentTo", "Sent To") }),
          el("th", { class: "sl-num", text: t("sl_ColTries", "Tries") }),
          el("th", { class: "sl-num", text: t("sl_ColLength", "Length") })
        )),
        el("tbody", {}, rows)
      ) : el("div", { class: "sl-muted", text: t("sl_NoLogCalls", "No calls.") }),
      d.pages > 1 ? el(
        "div",
        { class: "sl-pager" },
        el("button", { class: "ui basic button", type: "button", disabled: d.page <= 1, onclick: () => {
          activity.page = d.page - 1;
          loadCallLog();
        } }, t("sl_Previous", "Previous")),
        el("span", { text: sub(t("sl_PageOf", "Page {page} of {pages}"), { page: count(d.page), pages: count(d.pages) }) }),
        el("button", { class: "ui basic button", type: "button", disabled: d.page >= d.pages, onclick: () => {
          activity.page = d.page + 1;
          loadCallLog();
        } }, t("sl_Next", "Next"))
      ) : null
    );
    $(filter).dropdown({ placeholder: false });
  }
  function loadCallLog() {
    const seq = activity.logSeq = (activity.logSeq || 0) + 1;
    return get("callLog", { from: activity.from, to: activity.to, outcome: activity.outcome, page: activity.page }).then((d) => {
      if (seq === activity.logSeq) {
        activity.log = d;
        renderCallLog();
      }
    });
  }
  function loadActivity() {
    clearError();
    activity.page = 1;
    const seq = activity.seq = (activity.seq || 0) + 1;
    get("activity", { from: activity.from, to: activity.to }).then((a) => {
      if (seq === activity.seq) renderActivity(a);
    });
    loadCallLog();
  }
  function loadActivityTab() {
    if (!activity.from) {
      activity.preset = "30";
      [activity.from, activity.to] = presetRange(30);
    }
    fill($id("sl-activity"), activityToolbar(), el("div", { id: "sl-activity-body" }, el("div", { class: "ui active centered inline loader" })), el("div", { id: "sl-calllog" }));
    $("#sl-act-preset").dropdown({ placeholder: false });
    const ready = state.statuses ? $.Deferred().resolve() : get("statuses").then((d) => {
      state.statuses = d.statuses;
    });
    ready.then(loadActivity);
  }
  function updateTabsOverflow() {
    const tabs = $id("sl-tabs");
    const overflow = tabs.scrollWidth > tabs.clientWidth + 1;
    tabs.classList.toggle("sl-overflow", overflow);
    tabs.classList.toggle("sl-at-end", overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1);
  }
  var TABS = { records: loadRecordsTab, import: loadImportTab, activity: loadActivityTab, statuses: loadStatuses, callflow: loadCallFlow };
  function selectTab(tab) {
    if (tab === state.tab && tab === "callflow" && state.dirty) return;
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
    window.addEventListener("resize", () => {
      updateTabsOverflow();
      Object.values(activity.charts).forEach((c) => c.resize());
    });
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

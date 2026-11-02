const state = { devices: [], people: [], statuses: new Map(), statusErrors: new Map(), timer: null, lastHistoryRefreshAt: 0, toastTimer: null };
const $ = (id) => document.getElementById(id);

async function api(path, options = {}) {
  const source = new URL(path, window.location.href);
  const route = source.pathname.replace(/^.*\/api\//, "");
  if (route === source.pathname) throw new Error("Invalid dashboard API route");
  const target = new URL("api.php", window.location.href);
  target.searchParams.set("route", route);
  source.searchParams.forEach((value, key) => target.searchParams.append(key, value));
  const response = await fetch(target, { cache: "no-store", ...options, headers: { "Content-Type": "application/json", ...(options.headers || {}) } });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.success === false) throw new Error(data.message || `Request failed (${response.status})`);
  return data;
}
function notify(message, error = false) {
  const toast = $("toast"); toast.textContent = message; toast.className = `toast show${error ? " error" : ""}`;
  clearTimeout(state.toastTimer); state.toastTimer = setTimeout(() => toast.className = "toast", 3400);
}
function setDatabaseStatus(status, message) {
  $("databaseStatus").textContent = status;
  $("databaseMessage").textContent = message;
}
function esc(value) { return String(value ?? "").replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" })[c]); }
function timeText(value) { if (!value) return "Never"; const d = new Date(value); return Number.isNaN(+d) ? value : d.toLocaleString(); }
function localDateKey() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; }
function roleText(role) { return ({access:"Access control", sensors:"Sensors / safety", camera:"Camera", other:"Other device"})[role] || "Other device"; }
function online(device) { return device.state === "online"; }
function telemetryView(status) {
  const yesNo = value => value === true ? "Detected" : value === false ? "Clear" : "No reading";
  const dhtValid = status.dhtOk !== false && status.temperatureC != null && status.humidityPct != null;
  const temp = dhtValid ? `${status.temperatureC} °C` : "No reading — check DHT22 wiring";
  const humidity = dhtValid ? `${status.humidityPct} %` : "No reading — check DHT22 wiring";
  const distance = status.ultrasonicOk === false || status.distanceCm == null || Number(status.distanceCm) < 0 ? "No valid echo" : `${status.distanceCm} cm`;
  // These keys match the JSON returned by esp32_sensor_safety_board.ino.
  // Keep the older aliases as fallbacks for earlier firmware builds.
  const motion = status.pir ?? status.pirMotion;
  const fire = status.fire ?? status.fireDetected;
  const light = status.lightRaw ?? status.ldrRaw;
  const dark = status.dark ?? status.isDark;
  const led = status.led ?? status.streetLedOn;
  const item = (label, value, alert = false) => `<div class="telemetry-item ${alert ? "alert" : ""}"><small>${label}</small><strong>${esc(value)}</strong></div>`;
  const obstacleText = status.ultrasonicOk === false ? "Sensor/no valid echo" : status.obstacle == null ? `Unknown · ${distance}` : `${status.obstacle ? "Detected" : "Clear"} · ${distance}`;
  const gasText = status.gasRaw == null ? "No reading" : `${status.gasRaw} raw · ${status.gasAlarm ? "ALARM" : "Normal"}`;
  const lightText = light == null ? "No reading" : `${light} raw · ${dark == null ? "Unknown" : dark ? "Dark" : "Bright"}`;
  const fireValue = fire == null ? "No reading" : `${yesNo(fire)} · DO=${status.fireLevel == null ? "?" : status.fireLevel === 0 ? "LOW (0)" : "HIGH (1)"}`;
  return `<div class="telemetry-grid">${item("Motion", yesNo(motion), motion === true)}${item("Obstacle", obstacleText, status.obstacle === true)}${item("Gas sensor", gasText, status.gasAlarm === true)}${item("Fire sensor", fireValue, fire === true)}${item("Temperature", temp)}${item("Humidity", humidity)}${item("Light level", lightText)}${item("Street LED", led == null ? "Unknown" : led ? "Blinking / ON" : "Off")}${item("Buzzer alarm", status.alarm == null ? "Unknown" : status.alarm ? `Active · ${status.alarmReason || "alert"}` : "Off", status.alarm === true)}</div>`;
}

async function loadDevices() {
  const data = await api("/api/devices"); state.devices = data.devices;
  renderDevices(); renderAccessPicker(); updateStats();
}
function updateStats() {
  $("statDevices").textContent = state.devices.length;
  $("statOnline").textContent = state.devices.filter(online).length;
}
function renderDevices() {
  const grid = $("deviceGrid");
  if (!state.devices.length) { grid.innerHTML = '<div class="empty-state">No ESP32 controllers registered yet. Add a board to get started.</div>'; return; }
  grid.innerHTML = state.devices.map(device => {
    const data = state.statuses.get(device.id);
    const raw = data ? JSON.stringify(data, null, 2) : "No live status received yet. Use Check status to read this controller.";
    const statusView = data && device.role === "sensors"
      ? `${telemetryView(data)}<details class="raw-status"><summary>Raw ESP32 status JSON</summary><pre class="device-data">${esc(raw)}</pre></details>`
      : data ? `<pre class="device-data">${esc(raw)}</pre>`
      : state.statusErrors.has(device.id) ? `<div class="status-error">Status request failed: ${esc(state.statusErrors.get(device.id))}<br>Click “Check status” to retry.</div>`
      : `<pre class="device-data">${esc(raw)}</pre>`;
    return `<article class="device-card ${online(device) ? "online" : ""}">
      <div class="device-head"><div class="device-title"><span class="device-symbol">ESP</span><div><h3>${esc(device.name)}</h3><div class="device-ip">${esc(device.ip)}</div></div></div><span class="device-role">${esc(roleText(device.role))}</span></div>
      <div class="device-state ${online(device) ? "online" : ""}"><i></i>${online(device) ? "Online" : "Offline"}${device.last_seen ? ` · Seen ${esc(timeText(device.last_seen))}` : " · Not checked yet"}</div>
      ${statusView}
      <div class="device-actions"><button class="button button-primary" data-connect="${device.id}">${online(device) ? "Reconnect" : "Connect"}</button><button class="button button-outline" data-status="${device.id}">Check status</button><button class="delete-link" data-delete="${device.id}">Remove</button></div>
    </article>`;
  }).join("");
  grid.querySelectorAll("[data-connect]").forEach(b => b.onclick = () => connectDevice(b.dataset.connect, b));
  grid.querySelectorAll("[data-status]").forEach(b => b.onclick = () => fetchStatus(b.dataset.status, true));
  grid.querySelectorAll("[data-delete]").forEach(b => b.onclick = () => removeDevice(b.dataset.delete));
}
function renderAccessPicker() {
  const picker = $("accessDevice"), selected = picker.value;
  const options = state.devices.filter(d => d.role === "access");
  picker.innerHTML = '<option value="">Select access controller</option>' + options.map(d => `<option value="${d.id}">${esc(d.name)} (${esc(d.ip)})</option>`).join("");
  if (options.some(d => String(d.id) === selected)) picker.value = selected;
  else if (options.length === 1) picker.value = String(options[0].id);
}
async function connectDevice(id, button) {
  button.disabled = true;
  try {
    const result = await api(`/api/devices/${id}/connect`, { method: "POST", body: "{}" });
    notify(result.success ? "ESP32 connected." : "ESP32 did not respond.", !result.success);
    await loadDevices(); if (result.success) await fetchStatus(id, false);
    await loadEvents();
  } catch (e) { notify(e.message, true); }
  finally { button.disabled = false; }
}
async function fetchStatus(id, showToast) {
  try {
    const result = await api(`/api/devices/${id}/status`);
    state.statuses.set(id, result.status); state.statusErrors.delete(id);
    const device = state.devices.find(item => item.id === id);
    if (device && result.device) Object.assign(device, result.device);
    renderDevices(); updateStats();
    if (showToast) notify("Device status refreshed.");
  } catch (e) {
    state.statusErrors.set(id, e.message);
    state.statuses.delete(id); const d = state.devices.find(x => x.id === id); if (d) d.state = "offline";
    renderDevices(); updateStats(); if (showToast) notify(`Status read failed: ${e.message}`, true);
  }
}
async function refreshAll() {
  await Promise.all(state.devices.map(d => fetchStatus(d.id, false)));
  if (Date.now() - state.lastHistoryRefreshAt >= 300000) {
    await Promise.all([loadEvents(), loadSensorReadings()]);
    state.lastHistoryRefreshAt = Date.now();
  }
}

function displayBool(value, yes, no) { return value == null ? "—" : value ? yes : no; }
async function loadSensorReadings() {
  const tbody = $("sensorReadings");
  try {
    const result = await api("/api/readings?limit=10");
    if (!result.readings.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="muted">No readings stored yet. Connect a device and wait for the next status poll.</td></tr>';
      return;
    }
    tbody.innerHTML = result.readings.map(r => `<tr>
      <td>${esc(timeText(r.sampled_at))}</td><td>${esc(r.device_name)}</td>
      <td>${esc(displayBool(r.pir_motion, "Motion", "Clear"))}</td>
      <td>${r.distance_cm == null ? "No echo" : `${esc(r.distance_cm)} cm`}</td>
      <td>${r.gas_raw == null ? "—" : esc(r.gas_raw)}</td>
      <td>${esc(displayBool(r.fire_detected, "Fire", "Clear"))}</td>
      <td>${r.temperature_c == null ? "—" : esc(r.temperature_c)}</td>
      <td>${r.humidity_pct == null ? "—" : esc(r.humidity_pct)}</td>
      <td>${r.light_raw == null ? "—" : esc(r.light_raw)}</td>
      <td>${r.alarm_active == null ? "—" : r.alarm_active ? `<span class="table-alarm">${esc(r.alarm_reason || "Active")}</span>` : "Off"}</td>
    </tr>`).join("");
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="10" class="muted">Could not load saved readings: ${esc(e.message)}. Check the Firebase credentials and Firestore configuration.</td></tr>`;
  }
}

async function loadAttendance() {
  const [peopleResult, attendanceResult] = await Promise.all([api("/api/people"), api(`/api/attendance?date=${localDateKey()}`)]);
  state.people = peopleResult.people || [];
  const attendanceByPerson = new Map((attendanceResult.attendance || []).map(record => [record.person_id, record]));
  const picker = $("attendancePerson"), selected = picker.value;
  picker.innerHTML = '<option value="">Select person</option>' + state.people.map(person => `<option value="${esc(person.id)}">${esc(person.name)}</option>`).join("");
  if (state.people.some(person => person.id === selected)) picker.value = selected;
  const rows = $("attendanceRows");
  rows.innerHTML = state.people.length ? state.people.map(person => {
    const record = attendanceByPerson.get(person.id) || {};
    return `<tr><td>${esc(person.name)}</td><td>${esc(person.address)}</td><td>${esc(person.fingerprint_owner_name || "—")}</td><td>${esc(person.fingerprint_id || "—")}</td><td>${esc(person.rfid_owner_name || "—")}</td><td>${esc(person.rfid_uid || "—")}</td><td>${esc(record.time_in ? timeText(record.time_in) : "—")}</td><td>${esc(record.time_out ? timeText(record.time_out) : "—")}</td></tr>`;
  }).join("") : '<tr><td colspan="8" class="muted">No people registered yet.</td></tr>';
}

async function addPerson(event) {
  event.preventDefault();
  const form = event.currentTarget, button = $("savePerson"), payload = {
    name: $("personName").value.trim(),
    address: $("personAddress").value.trim(),
    fingerprint_id: $("personFingerprintId").value.trim(),
    fingerprint_owner_name: $("fingerprintOwnerName").value.trim(),
    rfid_uid: $("personRfidUid").value.trim(),
    rfid_owner_name: $("rfidOwnerName").value.trim(),
  };
  button.disabled = true;
  try {
    await api("/api/people", { method: "POST", body: JSON.stringify(payload) });
    form.reset();
    await Promise.all([loadAttendance(), loadEvents()]);
    notify("Person registered.");
  } catch (error) { notify(error.message, true); }
  finally { button.disabled = false; }
}

async function recordAttendance(direction) {
  const personId = $("attendancePerson").value;
  const person = state.people.find(item => item.id === personId);
  if (!person) return notify("Select a registered person first.", true);
  try {
    await api("/api/attendance", { method: "POST", body: JSON.stringify({ person_id: personId, direction, work_date: localDateKey() }) });
    await Promise.all([loadAttendance(), loadEvents()]);
    notify(`Time ${direction} recorded for ${person.name}.`);
  } catch (error) { notify(error.message, true); }
}

function syncPersonCredentials() {
  const person = state.people.find(item => item.id === $("attendancePerson").value);
  if (!person) return;
  $("fingerId").value = person.fingerprint_id || "";
  $("fingerName").value = person.fingerprint_owner_name || person.name;
  $("rfidUid").value = person.rfid_uid || "";
  $("rfidName").value = person.rfid_owner_name || person.name;
}

function downloadReadings() {
  const url = new URL("api.php", window.location.href);
  url.searchParams.set("route", "readings.csv");
  window.location.href = url.toString();
}
function downloadAttendance() {
  const url = new URL("api.php", window.location.href);
  url.searchParams.set("route", "attendance.csv");
  url.searchParams.set("date", localDateKey());
  window.location.href = url.toString();
}
async function addDevice(event) {
  event.preventDefault();
  const form = event.currentTarget, formData = new FormData(form), button = $("saveDevice"); button.disabled = true;
  try {
    await api("/api/devices", { method: "POST", body: JSON.stringify(Object.fromEntries(formData.entries())) });
    form.reset(); $("deviceDialog").close(); await loadDevices(); await loadEvents(); notify("Device saved to the database.");
  } catch (e) { notify(e.message, true); }
  finally { button.disabled = false; }
}
async function removeDevice(id) {
  const device = state.devices.find(d => d.id === id); if (!device || !confirm(`Remove ${device.name} from this dashboard?`)) return;
  try { await api(`/api/devices/${id}`, { method: "DELETE" }); state.statuses.delete(id); await loadDevices(); await loadEvents(); notify("Device removed."); }
  catch (e) { notify(e.message, true); }
}
function selectedAccessDevice() {
  const id = $("accessDevice").value; const device = state.devices.find(d => String(d.id) === id);
  if (!device) throw new Error("Add and select an access-control ESP32 first.");
  return device;
}
async function deviceCommand(path, successMessage) {
  const device = selectedAccessDevice();
  const result = await api(`/api/devices/${device.id}/command?path=${encodeURIComponent(path)}`);
  await loadEvents();
  const payload = result.data || {};
  if (payload.success === false) throw new Error(payload.message || "The ESP32 rejected the command");
  if (successMessage) notify(payload.message || successMessage);
  return payload;
}
function labelStore() { try { const d = JSON.parse(localStorage.getItem("uaepFingerprintLabels") || "[]"); return Array.isArray(d) ? d : []; } catch { return []; } }
function saveLabel(id, name) { const d = labelStore().filter(x => Number(x.id) !== Number(id)); d.push({id:Number(id), name:name || `Fingerprint ${id}`}); d.sort((a,b)=>a.id-b.id); localStorage.setItem("uaepFingerprintLabels", JSON.stringify(d)); }
function renderFingerprintLabels() {
  const list = $("fingerprintList"), labels = labelStore();
  list.innerHTML = labels.length ? labels.map(x => `<div class="credential-row"><div><strong>${esc(x.name || `Fingerprint ${x.id}`)}</strong><small>Fingerprint ID ${esc(x.id)}</small></div><button data-finger-delete="${Number(x.id)}">Delete</button></div>`).join("") : '<div class="muted">No saved names in this browser. Register a fingerprint to add one.</div>';
  list.querySelectorAll("[data-finger-delete]").forEach(b => b.onclick = () => deleteFingerprint(Number(b.dataset.fingerDelete)));
}
async function registerFingerprint() {
  const id = Number($("fingerId").value), name = $("fingerName").value.trim();
  if (!Number.isInteger(id) || id < 1 || id > 127) return notify("Enter a fingerprint ID from 1 to 127.", true);
  try { const data = await deviceCommand(`/api/register?id=${encodeURIComponent(id)}`, "Registration finished. Follow the ESP32 LCD prompts."); if (data.success) { saveLabel(id, name); renderFingerprintLabels(); } }
  catch (e) { notify(e.message, true); }
}
async function checkFingerprint() {
  try { const data = await deviceCommand("/api/check", "Scan request sent. Place your finger on the sensor."); if (data.message) notify(data.message, data.access !== "granted"); }
  catch (e) { notify(e.message, true); }
}
async function deleteFingerprint(id) {
  if (!confirm(`Delete fingerprint ID ${id} from the ESP32?`)) return;
  try { const data = await deviceCommand(`/api/delete?id=${encodeURIComponent(id)}`, "Fingerprint deleted."); if (data.success) { localStorage.setItem("uaepFingerprintLabels", JSON.stringify(labelStore().filter(x => Number(x.id) !== id))); renderFingerprintLabels(); } }
  catch (e) { notify(e.message, true); }
}
async function addRfid() {
  const uid = $("rfidUid").value.trim().replace(/[\s:-]/g, "").toUpperCase(), name = $("rfidName").value.trim();
  if (!/^(?:[0-9A-F]{2}){4,10}$/.test(uid)) return notify("Enter a valid RFID UID in hexadecimal.", true);
  try { await deviceCommand(`/api/rfid/add?${new URLSearchParams({uid,name})}`, "RFID card added."); $("rfidUid").value = ""; $("rfidName").value = ""; await loadRfids(); }
  catch (e) { notify(e.message, true); }
}
async function loadRfids() {
  const list = $("rfidList");
  try {
    const data = await deviceCommand("/api/rfid/list"); const cards = data.cards || [];
    list.innerHTML = cards.length ? cards.map(card => `<div class="credential-row"><div><strong>${esc(card.name || "RFID card")}</strong><small>UID ${esc(card.uid)}</small></div><button data-rfid-delete="${esc(card.uid)}">Revoke</button></div>`).join("") : '<div class="muted">No RFID cards are registered on this ESP32.</div>';
    list.querySelectorAll("[data-rfid-delete]").forEach(b => b.onclick = () => deleteRfid(b.dataset.rfidDelete));
  } catch (e) { list.innerHTML = `<div class="muted">${esc(e.message)}. Verify the firmware supports /api/rfid/list.</div>`; }
}
async function deleteRfid(uid) {
  if (!confirm(`Revoke RFID access for ${uid}?`)) return;
  try { await deviceCommand(`/api/rfid/delete?${new URLSearchParams({uid})}`, "RFID access revoked."); await loadRfids(); }
  catch (e) { notify(e.message, true); }
}
async function loadEvents() {
  try {
    const result = await api("/api/events"), list = $("eventList");
    $("statEvents").textContent = result.total;
    list.innerHTML = result.events.length ? result.events.slice(0,12).map(e => `<div class="event-row"><i class="event-dot"></i><div><strong>${esc(e.message)}</strong><small>${esc(e.device_name || "Dashboard")} · ${esc(e.category)}</small></div><time class="event-time">${esc(timeText(e.created_at))}</time></div>`).join("") : '<div class="empty-state">No activity has been recorded yet.</div>';
  } catch (e) { $("eventList").innerHTML = `<div class="muted">Could not load events: ${esc(e.message)}</div>`; }
}
async function refreshCredentialLists() { renderFingerprintLabels(); await loadRfids(); }
function openDeviceDialog() { $("deviceDialog").showModal(); }
function setClock() { const d = new Date(); $("clock").textContent = d.toLocaleTimeString([], {hour:"2-digit",minute:"2-digit",second:"2-digit"}); $("todayLabel").textContent = d.toLocaleDateString([], {weekday:"short",month:"short",day:"numeric"}); }

document.addEventListener("DOMContentLoaded", async () => {
  setClock(); setInterval(setClock, 1000); renderFingerprintLabels();
  $("addDeviceTop").onclick = openDeviceDialog; $("addDeviceButton").onclick = openDeviceDialog;
  $("deviceForm").addEventListener("submit", addDevice);
  $("personForm").addEventListener("submit", addPerson);
  $("attendancePerson").addEventListener("change", syncPersonCredentials);
  $("recordTimeIn").onclick = () => recordAttendance("in");
  $("recordTimeOut").onclick = () => recordAttendance("out");
  $("saveDevice").addEventListener("click", e => { if (!$("deviceForm").reportValidity()) e.preventDefault(); });
  $("registerFinger").onclick = registerFingerprint; $("checkFinger").onclick = checkFingerprint; $("addRfid").onclick = addRfid;
  $("refreshFingerprints").onclick = renderFingerprintLabels; $("refreshRfids").onclick = loadRfids; $("refreshEvents").onclick = loadEvents;
  $("exportReadings").onclick = downloadReadings;
  $("exportAttendance").onclick = downloadAttendance;
  $("accessDevice").addEventListener("change", refreshCredentialLists);
  try {
    await api("/api/health");
    setDatabaseStatus("Connected", "Firebase Cloud Firestore");
    await Promise.all([loadDevices(), loadEvents(), loadSensorReadings(), loadAttendance()]);
    state.lastHistoryRefreshAt = Date.now();
    state.timer = setInterval(refreshAll, 30000);
  } catch (e) {
    const status = /quota exceeded/i.test(e.message) ? "Quota exceeded" : /credentials are not configured/i.test(e.message) ? "Setup required" : "Unavailable";
    setDatabaseStatus(status, e.message);
    $("deviceGrid").innerHTML = `<div class="empty-state">${esc(e.message)}</div>`;
    $("sensorReadings").innerHTML = `<tr><td colspan="10" class="muted">${esc(e.message)}</td></tr>`;
    $("attendanceRows").innerHTML = `<tr><td colspan="8" class="muted">${esc(e.message)}</td></tr>`;
    $("eventList").innerHTML = `<div class="muted">${esc(e.message)}</div>`;
    notify(e.message, true);
  }
});

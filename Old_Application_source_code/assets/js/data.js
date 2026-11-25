// ═══════════════════════════════════════════
// SHARED DATA LAYER  (MySQL via PHP API)
// ═══════════════════════════════════════════

let companies       = [];
let drivers         = [];
let companyInvoices = [];
let driverInvoices  = [];

// ── Low-level fetch helper ────────────────────
// Sends a request to the API and returns parsed JSON.
// Shows a toast and throws on network/server errors.
async function api(endpoint, method = 'GET', body = null, id = null) {
    const url = 'api/' + endpoint + '.php' + (id ? '?id=' + id : '');
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (body !== null) opts.body = JSON.stringify(body);

    let res;
    try {
        res = await fetch(url, opts);
    } catch (e) {
        toast('Network error — is the server running?', 'error');
        throw e;
    }

    const json = await res.json().catch(() => ({}));
    if (res.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Unauthorized');
    }
    if (!res.ok) {
        const msg = json.error || ('Server error ' + res.status);
        toast(msg, 'error');
        throw new Error(msg);
    }
    return json;
}

// ── Change Password (called from header modal) ─
async function submitChangePassword(e) {
    e.preventDefault();
    const btn = document.getElementById('cpSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        await api('change-password', 'POST', {
            currentPassword: document.getElementById('cpCurrent').value,
            newPassword:     document.getElementById('cpNew').value,
            confirmPassword: document.getElementById('cpConfirm').value,
        });
        toast('Password updated successfully!', 'success');
        document.getElementById('changePwForm').reset();
        document.getElementById('changePwModal').classList.remove('active');
    } catch (_) { /* error already shown by api() */ }
    btn.disabled = false;
    btn.textContent = 'Update Password';
}

// ── Load all data from the database ──────────
// Call this once on DOMContentLoaded on every page.
async function loadFromDB() {
    const [c, d, ci, di] = await Promise.all([
        api('companies'),
        api('drivers'),
        api('inv-company'),
        api('inv-driver'),
    ]);
    companies       = c;
    drivers         = d;
    companyInvoices = ci;
    driverInvoices  = di;
}

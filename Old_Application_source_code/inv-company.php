<?php
require 'includes/auth.php';
$pageTitle  = 'Invoice / Company';
$activePage = 'inv-company';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:12px;">Invoice for Company</h2>

        <!-- Collapsible totals breakdown -->
        <div id="coTotalsPanel" style="margin-bottom:16px;">
            <button onclick="toggleCoTotals()" id="coTotalsToggleBtn"
                style="background:none;border:1px solid #475569;color:#94a3b8;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px;margin-bottom:0;">
                ▶ Show Totals
            </button>
            <div id="coTotalsBody" style="display:none;margin-top:8px;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:12px 16px;max-width:420px;">
                <div id="coTotalsList" style="font-size:13px;color:#cbd5e1;margin-bottom:8px;max-height:260px;overflow-y:auto;"></div>
                <div style="border-top:1px solid #475569;padding-top:8px;display:flex;justify-content:space-between;font-size:14px;font-weight:700;color:#f1f5f9;">
                    <span>Grand Total</span>
                    <span id="coGrandTotal">$0.00</span>
                </div>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-success" onclick="openCoInvModal()">+ Create Invoice</button>
        </div>
        <div class="filter-bar">
            <div id="coFilterCompanyWrap" class="ss-wrap"></div>
            <input type="hidden" id="coFilterCompany">
            <div id="coFilterDriverWrap" class="ss-wrap"></div>
            <input type="hidden" id="coFilterDriver">
            <input type="text" id="coSearchCustomer" placeholder="🔍 Search customer..." oninput="applyCoFilters()">
            <input type="text" id="coSearchPhone" placeholder="🔍 Search phone..." oninput="applyCoFilters()">
            <button class="btn-clear" onclick="clearCoFilters()">✕ Clear</button>
            <span class="filter-count" id="coFilterCount"></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Invoice #</th><th>Company</th><th>Jobs</th><th>Subtotal</th><th>Total</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody id="coInvTbody"></tbody>
            </table>
        </div>
        <div id="coInvPagination" class="pagination-wrap"></div>
    </div>

<!-- Create / Edit Company Invoice Modal -->
<div id="coInvModal" class="modal">
    <div class="modal-box modal-xl">
        <div class="modal-hdr">
            <h2 id="coInvModalTitle">Create Company Invoice</h2>
            <button class="close-btn" onclick="closeModal('coInvModal')">&times;</button>
        </div>
        <form id="coInvForm" onsubmit="saveCoInvoice(event)">
            <div class="form-grid">
                <div class="form-group">
                    <label>Company *</label>
                    <select id="coInvCompany" required>
                        <option value="">-- Select Company --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" id="coInvDate" required>
                </div>
                <div class="form-group">
                    <label>Paid Date</label>
                    <input type="date" id="coPaidDate">
                </div>
            </div>

            <div class="jobs-section">
                <h4>📦 Jobs</h4>
                <div class="line-items-scroll">
                    <div class="line-item-header-row-xl">
                        <span>Driver</span>
                        <span>Job #</span>
                        <span>Customer</span>
                        <span>Phone</span>
                        <span>From</span>
                        <span>To</span>
                        <span>CF</span>
                        <span>Rate</span>
                        <span>Total</span>
                        <span>Bal. Due</span>
                        <span>Original Bal.</span>
                        <span>Remarks</span>
                        <span></span>
                    </div>
                    <div id="coJobsContainer"></div>
                </div>
                <button type="button" class="btn btn-primary" style="margin-top:10px;" onclick="addCoJobRow()">+ Add Job</button>
            </div>

            <div class="summary-area">
                <div class="invoice-remarks-box">
                    <label>Remarks</label>
                    <textarea id="coInvoiceRemarks" placeholder="Invoice remarks..."></textarea>
                </div>
                <div class="summary-box">
                    <div class="summary-row"><span>Subtotal (Bal. Due total):</span><span id="coSubtotal">$0.00</span></div>
                    <div class="summary-row"><span>Carrier Fee (Total table value):</span><span id="coCarrierFee">$0.00</span></div>
                    <div class="summary-row">
                        <span>Labor Cost:</span>
                        <input type="number" id="coLaborCost" placeholder="0.00" step="0.01" style="width:110px;text-align:right;" oninput="updateCoSummary()">
                    </div>
                    <div class="summary-row">
                        <span>Pads:</span>
                        <input type="number" id="coPads" placeholder="0.00" step="0.01" style="width:110px;text-align:right;" oninput="updateCoSummary()">
                    </div>
                    <div class="summary-row">
                        <span>Paid:</span>
                        <input type="number" id="coPaid" placeholder="0.00" step="0.01" style="width:110px;text-align:right;" oninput="updateCoSummary()">
                    </div>
                    <div class="summary-row total"><span>TOTAL DUE:</span><span id="coTotal">$0.00</span></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('coInvModal')">Cancel</button>
                <button type="submit" class="btn btn-success" id="coInvSubmitBtn">✔ Save Invoice</button>
            </div>
        </form>
    </div>
</div>

<!-- View Invoice Modal -->
<div id="invoiceViewModal" class="modal">
    <div class="modal-box modal-xl">
        <div class="modal-hdr">
            <h2>Invoice Detail</h2>
            <button class="close-btn" onclick="closeModal('invoiceViewModal')">&times;</button>
        </div>
        <div id="invoiceViewContent"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('invoiceViewModal')">Close</button>
            <button class="btn btn-success" onclick="downloadCoInvoicePDF(currentViewId)">📥 Download PDF</button>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
let coJobRows      = [];
let editingCoInvId = null;
let currentViewId  = null;

let coFilterCompany  = '';
let coFilterDriver   = '';
let coSearchCustomer = '';
let coSearchPhone    = '';
let coSelectCompany, coSelectDriver;

function emptyCoJob() {
    return { jobNumber:'', driverId:'', customerName:'', phone:'', from:'', to:'', cubicFeet:'', rate:'', balanceDue:'', newBalance:'', remarks:'' };
}

// ── Table render ─────────────────────────────

const PAGE_SIZE = 30;
let currentPage = 1;

function getFilteredCoInvoices() {
    return companyInvoices.filter(inv => {
        if (coFilterCompany && inv.companyId !== coFilterCompany) return false;
        if (coFilterDriver  && !(inv.lineItems || []).some(j => j.driverId == coFilterDriver)) return false;
        if (coSearchCustomer) {
            const q = coSearchCustomer;
            if (!(inv.lineItems || []).some(j => (j.customerName || '').toLowerCase().includes(q))) return false;
        }
        if (coSearchPhone) {
            const q = coSearchPhone;
            if (!(inv.lineItems || []).some(j => (j.phone || '').toLowerCase().includes(q))) return false;
        }
        return true;
    });
}

function applyCoFilters() {
    coFilterCompany  = parseInt(document.getElementById('coFilterCompany').value)  || '';
    coFilterDriver   = parseInt(document.getElementById('coFilterDriver').value)   || '';
    coSearchCustomer = document.getElementById('coSearchCustomer').value.toLowerCase().trim();
    coSearchPhone    = document.getElementById('coSearchPhone').value.toLowerCase().trim();
    currentPage = 1;
    renderPage();
}

function clearCoFilters() {
    coSelectCompany.reset();
    coSelectDriver.reset();
    document.getElementById('coSearchCustomer').value = '';
    document.getElementById('coSearchPhone').value    = '';
    coFilterCompany = ''; coFilterDriver = ''; coSearchCustomer = ''; coSearchPhone = '';
    currentPage = 1;
    renderPage();
}

function populateCoFilterDropdowns() {
    const companyOpts = [{ value: '', label: 'All Companies' }].concat(companies.map(c => ({ value: c.id,  label: c.name })));
    const driverOpts  = [{ value: '', label: 'All Drivers' }]  .concat(drivers.map(d  => ({ value: d.id,  label: `${d.firstName} ${d.lastName}` })));
    coSelectCompany = makeSearchableSelect(document.getElementById('coFilterCompanyWrap'), document.getElementById('coFilterCompany'), companyOpts, applyCoFilters);
    coSelectDriver  = makeSearchableSelect(document.getElementById('coFilterDriverWrap'),  document.getElementById('coFilterDriver'),  driverOpts,  applyCoFilters);
}

let coTotalsOpen = false;
function toggleCoTotals() {
    coTotalsOpen = !coTotalsOpen;
    document.getElementById('coTotalsBody').style.display = coTotalsOpen ? 'block' : 'none';
    document.getElementById('coTotalsToggleBtn').textContent = coTotalsOpen ? '▼ Hide Totals' : '▶ Show Totals';
}

function updateCoTotalsPanel(filtered) {
    const list  = document.getElementById('coTotalsList');
    const grand = document.getElementById('coGrandTotal');
    let sum = 0;
    list.innerHTML = filtered.map(inv => {
        const co   = companies.find(c => c.id === inv.companyId);
        const name = co ? co.name : '?';
        const t    = inv.total || 0;
        sum += t;
        const color = t < 0 ? '#f87171' : '#86efac';
        return `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #1e293b;">
            <span><strong style="color:#f1f5f9;">CI-${inv.id}</strong>&nbsp;&nbsp;${name}</span>
            <span style="color:${color};font-weight:600;">$${t.toFixed(2)}</span>
        </div>`;
    }).join('');
    const gc = sum < 0 ? '#f87171' : '#86efac';
    grand.style.color = gc;
    grand.textContent = '$' + sum.toFixed(2);
}

function renderPage() {
    const tb       = document.getElementById('coInvTbody');
    const filtered = getFilteredCoInvoices();
    updateCoTotalsPanel(filtered);
    const total    = filtered.length;
    document.getElementById('coFilterCount').textContent = (coFilterCompany || coFilterDriver || coSearchCustomer || coSearchPhone)
        ? `${total} result${total !== 1 ? 's' : ''}` : '';
    if (!total) {
        tb.innerHTML = `<tr><td colspan="7" class="empty">${companyInvoices.length ? 'No invoices match the current filters.' : 'No company invoices yet. Go to Invoice / Driver and click "🏢 Generate CI" on a driver invoice to auto-generate, or click "+ Create Invoice" to add manually.'}</td></tr>`;
        renderPagination('coInvPagination', 0, 1, PAGE_SIZE, () => {});
        return;
    }
    currentPage = Math.min(currentPage, Math.max(1, Math.ceil(total / PAGE_SIZE)));
    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageData = filtered.slice(start, start + PAGE_SIZE);
    tb.innerHTML = pageData.map(inv => {
        const co = companies.find(c => c.id === inv.companyId);
        const n  = (inv.lineItems || []).length;
        return `
            <tr>
                <td><strong>CI-${inv.id}</strong></td>
                <td>${co?.name || '?'}</td>
                <td>${n} job${n !== 1 ? 's' : ''}</td>
                <td>$${(inv.subtotal || 0).toFixed(2)}</td>
                <td><strong>$${(inv.total || 0).toFixed(2)}</strong></td>
                <td>${formatDate(inv.date)}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view"   onclick="viewCoInvoice(${inv.id})">👁️ View</button>
                    <button class="btn-xs btn-xs-pdf"    onclick="downloadCoInvoicePDF(${inv.id})">📥 PDF</button>
                    <button class="btn-xs btn-xs-edit"   onclick="editCoInvoice(${inv.id})">✏️ Edit</button>
                    <button class="btn-xs btn-xs-delete" onclick="deleteCoInvoice(${inv.id})">🗑️ Delete</button>
                </div></td>
            </tr>`;
    }).join('');
    renderPagination('coInvPagination', total, currentPage, PAGE_SIZE, p => {
        currentPage = p;
        renderPage();
    });
}

// ── Open modal (create) ──────────────────────

function openCoInvModal() {
    editingCoInvId = null;
    document.getElementById('coInvModalTitle').textContent  = 'Create Company Invoice';
    document.getElementById('coInvSubmitBtn').textContent   = '✔ Save Invoice';
    document.getElementById('coInvForm').reset();
    document.getElementById('coInvDate').valueAsDate = new Date();
    populateCoCompanySelect(null);
    coJobRows = [emptyCoJob()];
    renderCoJobRows();
    document.getElementById('coInvModal').classList.add('active');
}

// ── Open modal (edit) ────────────────────────

function editCoInvoice(id) {
    const inv = companyInvoices.find(i => i.id === id);
    if (!inv) return;
    editingCoInvId = id;
    document.getElementById('coInvModalTitle').textContent = 'Edit Company Invoice';
    document.getElementById('coInvSubmitBtn').textContent  = '✔ Update Invoice';
    document.getElementById('coInvDate').value    = inv.date;
    document.getElementById('coLaborCost').value      = inv.laborCost || '';
    document.getElementById('coPads').value           = inv.pads || '';
    document.getElementById('coPaid').value           = inv.paid || '';
    document.getElementById('coPaidDate').value       = inv.paidDate || '';
    document.getElementById('coInvoiceRemarks').value = inv.invoiceRemarks || '';
    populateCoCompanySelect(inv.companyId);
    coJobRows = JSON.parse(JSON.stringify(inv.lineItems || [emptyCoJob()]));
    renderCoJobRows();
    document.getElementById('coInvModal').classList.add('active');
}

function populateCoCompanySelect(selectedId) {
    document.getElementById('coInvCompany').innerHTML =
        '<option value="">-- Select Company --</option>' +
        companies.map(c => `<option value="${c.id}" ${c.id === selectedId ? 'selected' : ''}>${c.name}</option>`).join('');
}

// ── Job rows ─────────────────────────────────

function addCoJobRow()       { coJobRows.push(emptyCoJob()); renderCoJobRows(); }
function removeCoJobRow(idx) { if (coJobRows.length === 1) return; coJobRows.splice(idx, 1); renderCoJobRows(); }

function setCoJob(idx, field, val) {
    const num = ['cubicFeet','rate','balanceDue','newBalance'];
    coJobRows[idx][field] = num.includes(field) ? (parseFloat(val) || 0) : val;
    if (field === 'cubicFeet' || field === 'rate') renderCoJobRows();
    else updateCoSummary();
}

function renderCoJobRows() {
    document.getElementById('coJobsContainer').innerHTML = coJobRows.map((r, i) => {
        const total = ((r.cubicFeet || 0) * (r.rate || 0)).toFixed(2);
        const driverSel = '<option value="">-- Driver --</option>' +
            drivers.map(d => `<option value="${d.id}" ${d.id == r.driverId ? 'selected' : ''}>${d.firstName} ${d.lastName}</option>`).join('');
        return `
        <div class="line-item-row-xl">
            <select onchange="setCoJob(${i},'driverId',this.value)">${driverSel}</select>
            <input  type="text"   placeholder="Job #"    value="${esc(r.jobNumber)}"    onchange="setCoJob(${i},'jobNumber',this.value)">
            <input  type="text"   placeholder="Customer" value="${esc(r.customerName)}" onchange="setCoJob(${i},'customerName',this.value)">
            <input  type="tel"    placeholder="Phone"    value="${esc(r.phone)}"        onchange="setCoJob(${i},'phone',this.value)">
            <input  type="text"   placeholder="From"     value="${esc(r.from)}"         onchange="setCoJob(${i},'from',this.value)">
            <input  type="text"   placeholder="To"       value="${esc(r.to)}"           onchange="setCoJob(${i},'to',this.value)">
            <input  type="number" placeholder="CF"       value="${r.cubicFeet || ''}"   onchange="setCoJob(${i},'cubicFeet',this.value)" step="0.01">
            <input  type="number" placeholder="Rate"     value="${r.rate || ''}"        onchange="setCoJob(${i},'rate',this.value)" step="0.01">
            <div class="cell-total">$${total}</div>
            <input  type="number" placeholder="Bal Due"  value="${r.balanceDue || ''}"  onchange="setCoJob(${i},'balanceDue',this.value)" step="0.01">
            <input  type="number" placeholder="Orig Bal" value="${r.newBalance || ''}"  onchange="setCoJob(${i},'newBalance',this.value)" step="0.01">
            <input  type="text"   placeholder="Remarks"  value="${esc(r.remarks)}"      onchange="setCoJob(${i},'remarks',this.value)">
            ${coJobRows.length > 1
                ? `<button type="button" class="btn-remove" onclick="removeCoJobRow(${i})">&#x2715;</button>`
                : '<div></div>'}
        </div>`;
    }).join('');
    updateCoSummary();
}

function updateCoSummary() {
    const sub   = coJobRows.reduce((s, r) => s + (r.balanceDue || 0), 0);
    const fee   = coJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const labor = parseFloat(document.getElementById('coLaborCost').value) || 0;
    const pads  = parseFloat(document.getElementById('coPads').value) || 0;
    const paid  = parseFloat(document.getElementById('coPaid').value) || 0;
    document.getElementById('coSubtotal').textContent   = '$' + sub.toFixed(2);
    document.getElementById('coCarrierFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('coTotal').textContent      = '$' + (sub - fee - labor - pads + paid).toFixed(2);
}

// ── Save (create or update) ──────────────────

async function saveCoInvoice(e) {
    e.preventDefault();
    const cid = parseInt(document.getElementById('coInvCompany').value);
    if (!cid) { toast('Please select a company.', 'error'); return; }
    if (!coJobRows.some(r => r.customerName || r.jobNumber)) { toast('Add at least one job.', 'error'); return; }

    const sub     = coJobRows.reduce((s, r) => s + (r.balanceDue || 0), 0);
    const fee     = coJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const labor   = parseFloat(document.getElementById('coLaborCost').value) || 0;
    const pads    = parseFloat(document.getElementById('coPads').value) || 0;
    const paid     = parseFloat(document.getElementById('coPaid').value) || 0;
    const date     = document.getElementById('coInvDate').value;
    const paidDate      = document.getElementById('coPaidDate').value || '';
    const invoiceRemarks = document.getElementById('coInvoiceRemarks').value;
    const items          = JSON.parse(JSON.stringify(coJobRows));
    const payload        = { companyId: cid, date, paidDate, invoiceRemarks, lineItems: items, subtotal: sub, carrierFee: fee, laborCost: labor, pads, paid, total: sub - fee - labor - pads + paid };

    try {
        if (editingCoInvId) {
            await api('inv-company', 'PUT', payload, editingCoInvId);
            Object.assign(companyInvoices.find(i => i.id === editingCoInvId), payload);
            toast('Company invoice updated!', 'success');
        } else {
            const res = await api('inv-company', 'POST', payload);
            companyInvoices.push({ id: res.id, ...payload });
            toast('Company invoice saved!', 'success');
        }
        editingCoInvId = null;
        renderPage();
        closeModal('coInvModal');
    } catch (_) { /* error already shown by api() */ }
}

// ── Delete ───────────────────────────────────

async function deleteCoInvoice(id) {
    if (!confirm('Delete this invoice?')) return;
    try {
        await api('inv-company', 'DELETE', null, id);
        companyInvoices = companyInvoices.filter(i => i.id !== id);
        renderPage();
        toast('Invoice deleted.', 'success');
    } catch (_) { /* error already shown by api() */ }
}

// ── Build invoice HTML (shared by view + print) ──

function buildCoInvoiceHtml(id) {
    const inv  = companyInvoices.find(i => i.id === id);
    if (!inv) return '';
    const co   = companies.find(c => c.id === inv.companyId) || {};
    const jobs = inv.lineItems || [];
    let totalCF = 0, totalAmt = 0, totalBal = 0, totalNewBal = 0;
    jobs.forEach(j => {
        totalCF     += (j.cubicFeet || 0);
        totalAmt    += (j.cubicFeet || 0) * (j.rate || 0);
        totalBal    += (j.balanceDue || 0);
        totalNewBal += (j.newBalance || 0);
    });

    const rows = jobs.map(j => {
        const dr = drivers.find(d => d.id == j.driverId) || {};
        return `<tr>
            <td>${dr.firstName ? dr.firstName + ' ' + dr.lastName : '—'}</td>
            <td>${j.jobNumber || ''}</td>
            <td>${j.customerName || ''}</td>
            <td>${j.phone || ''}</td>
            <td>${j.from || ''}</td>
            <td>${j.to || ''}</td>
            <td>${j.cubicFeet || 0}</td>
            <td>$${parseFloat(j.rate || 0).toFixed(2)}</td>
            <td><strong>$${((j.cubicFeet || 0) * (j.rate || 0)).toFixed(2)}</strong></td>
            <td>$${parseFloat(j.balanceDue || 0).toFixed(2)}</td>
            <td>$${parseFloat(j.newBalance || 0).toFixed(2)}</td>
            <td>${j.remarks || ''}</td>
        </tr>`;
    }).join('');

    const laborCost = inv.laborCost || 0;
    const pads      = inv.pads || 0;
    const paid      = inv.paid || 0;

    return `
        <div class="inv-view">

            <!-- ── Header: logo+company left, company name+details right ── -->
            <div class="inv-header-row">
                <div class="inv-company-block">
                    <div class="inv-logo-name-row">
                        <img src="assets/bh-logo.png" alt="BH Logo" class="inv-brand-logo">
                    </div>
                    <div class="inv-company-addr">
                        11720 Amber Park Dr Ste 160<br>
                        Alpharetta, GA 30009<br>
                        DOT: 2521000<br>
                        Phone: +1 (347) 668-4584
                    </div>
                </div>
                <div class="inv-driver-block">
                    <h2>${co.name || 'Company'}</h2>
                    <p>Company Statement</p>
                    <p>${co.address || ''}${co.city ? ', ' + co.city : ''}</p>
                    <p>DOT: ${co.dotNumber || '—'} &nbsp;&nbsp; Tel: ${co.phone || '—'}</p>
                    ${inv.paidDate ? `<p class="inv-paid-date"><strong>Paid Date: ${formatDate(inv.paidDate)}</strong></p>` : ''}
                </div>
            </div>

            <!-- ── Invoice meta ── -->
            <div class="inv-meta">
                <div>
                    <strong>Invoice #:</strong> CI-${inv.id}<br>
                    <strong>Date:</strong> ${formatDate(inv.date)}<br>
                    <strong>Type:</strong> Company Invoice
                </div>
                <div style="text-align:right;">
                    <strong>Total Jobs:</strong> ${jobs.length}<br>
                    <strong>Total CF:</strong> ${totalCF}<br>
                    <strong>Total Due:</strong> $${(inv.total || 0).toFixed(2)}
                </div>
            </div>

            <!-- ── Jobs table ── -->
            <div style="overflow-x:auto;">
            <table class="inv-table">
                <thead><tr>
                    <th>Driver</th><th>Job #</th><th>Customer</th><th>Phone</th>
                    <th>From</th><th>To</th><th>CF</th><th>Rate</th>
                    <th>Total</th><th>Bal. Due</th><th>Original Bal.</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                    ${rows}
                    <tr class="inv-total-row">
                        <td colspan="6"><strong>TOTALS</strong></td>
                        <td><strong>${totalCF}</strong></td>
                        <td></td>
                        <td><strong>$${totalAmt.toFixed(2)}</strong></td>
                        <td><strong>$${totalBal.toFixed(2)}</strong></td>
                        <td><strong>$${totalNewBal.toFixed(2)}</strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- ── Summary (right-aligned) ── -->
            <div class="inv-summary" style="margin-top:20px;">
                <div class="inv-summary-row">
                    <span>Subtotal <em style="font-size:11px;font-weight:400;">(Bal. Due total)</em></span>
                    <span>$${(inv.subtotal || 0).toFixed(2)}</span>
                </div>
                <div class="inv-summary-row">
                    <span>Carrier Fee <em style="font-size:11px;font-weight:400;">(Total table value)</em></span>
                    <span>− $${(inv.carrierFee || 0).toFixed(2)}</span>
                </div>
                <div class="inv-summary-row">
                    <span>Labor Cost</span>
                    <span>− $${laborCost.toFixed(2)}</span>
                </div>
                <div class="inv-summary-row">
                    <span>Pads</span>
                    <span>− $${pads.toFixed(2)}</span>
                </div>
                ${paid !== 0 ? `<div class="inv-summary-row ${paid < 0 ? 'inv-paid-row' : 'inv-charge-row'}">
                    <span>Paid</span>
                    <span>${paid < 0 ? '− $' + Math.abs(paid).toFixed(2) : '+ $' + paid.toFixed(2)}</span>
                </div>` : ''}
                <div class="inv-summary-row">
                    <span>TOTAL DUE</span>
                    <span>$${(inv.total || 0).toFixed(2)}</span>
                </div>
            </div>

            <!-- ── Footer: Remarks left, Signature right ── -->
            <div class="inv-footer-row">
                <div class="inv-footer-cell">
                    ${inv.invoiceRemarks ? `<div style="margin-bottom:6px;font-size:12px;font-weight:400;white-space:pre-wrap;">${inv.invoiceRemarks}</div>` : ''}
                    <strong>Remarks</strong>
                </div>
                <div class="inv-footer-cell" style="text-align:right;"><strong>Signature</strong></div>
            </div>
        </div>`;
}

// ── View (opens modal) ────────────────────────

function viewCoInvoice(id) {
    currentViewId = id;
    document.getElementById('invoiceViewContent').innerHTML = buildCoInvoiceHtml(id);
    document.getElementById('invoiceViewModal').classList.add('active');
}

// ── Print directly (no modal needed) ─────────

function printCoInvoice(id) {
    triggerPrint(buildCoInvoiceHtml(id));
}

function invoiceInlineCSSText() {
    return `
        *{margin:0;padding:0;box-sizing:border-box;}
        body,div,td,th,p,span,strong{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif !important;color:#111;}
        h2{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif !important;font-weight:700;}
        strong,b{font-weight:700 !important;}
        .inv-view{background:#fff;color:#111;padding:28px;}

        /* ── Header row ── */
        .inv-header-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #e2e8f0;}
        .inv-company-block{display:flex;flex-direction:row;align-items:flex-start;gap:12px;}
        .inv-logo-name-row{display:flex;align-items:center;gap:10px;}
        .inv-brand-logo{height:56px;width:auto;object-fit:contain;}
        .inv-company-addr{font-size:12px;color:#444;line-height:1.8;}
        .inv-driver-block{text-align:right;}
        .inv-driver-block h2{font-size:26px;font-weight:700;letter-spacing:.02em;}
        .inv-driver-block p{font-size:12px;color:#444;margin-top:4px;}

        /* ── Meta row ── */
        .inv-meta{display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:13px;margin-bottom:18px;padding:12px 14px;border:1px solid #ddd;background:#f9fafb;}
        .inv-meta div{line-height:1.9;}

        /* ── Table ── */
        .inv-table{width:100%;border-collapse:collapse;font-size:11.5px;margin-bottom:18px;}
        .inv-table th{background:#1e293b;color:#fff;padding:8px 7px;text-align:left;border:1px solid #555;white-space:nowrap;font-weight:700;}
        .inv-table td{border:1px solid #bbb;padding:7px;vertical-align:top;}
        .inv-table tbody tr:nth-child(even){background:#f5f8ff;}
        .inv-total-row td{background:#e8edf5;font-weight:700;border-top:2px solid #333;}

        /* ── Summary box ── */
        .inv-summary{margin-left:auto;width:360px;border:1px solid #ccc;font-size:13px;margin-top:20px;}
        .inv-summary-row{display:flex;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #ddd;}
        .inv-summary-row:last-child{background:#1e293b;color:#fff;font-size:15px;font-weight:700;border-bottom:none;}
        .inv-summary-row:last-child span{color:#fff !important;}
        .inv-paid-row{background:#f0fdf4;color:#166534;font-weight:600;}
        .inv-paid-date{margin-top:8px;font-size:13px;color:#111;}
        .inv-charge-row{background:#fef2f2;color:#991b1b;font-weight:600;}

        /* ── Footer ── */
        .inv-footer-row{display:flex;justify-content:space-between;margin-top:48px;}
        .inv-footer-cell{display:flex;flex-direction:column;justify-content:flex-end;font-size:14px;font-weight:700;color:#111;}
    `;
}

async function downloadCoInvoicePDF(id) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:0;left:0;width:1100px;background:#fff;z-index:99999;';
    el.innerHTML = buildCoInvoiceHtml(id);
    document.body.appendChild(el);
    try {
        const canvas = await html2canvas(el, {
            scale: 2, useCORS: true, allowTaint: true, logging: false,
            scrollX: 0, scrollY: 0, windowWidth: 1100,
            onclone: (doc) => {
                const s = doc.createElement('style');
                s.textContent = invoiceInlineCSSText();
                doc.head.appendChild(s);
            }
        });
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const ratio = pageW / canvas.width;
        const totalH = canvas.height * ratio;
        const pages = Math.ceil(totalH / pageH);
        const imgData = canvas.toDataURL('image/jpeg', 0.98);
        for (let i = 0; i < pages; i++) {
            if (i > 0) pdf.addPage();
            pdf.addImage(imgData, 'JPEG', 0, -i * pageH, pageW, totalH);
        }
        pdf.save('CI-' + id + '.pdf');
    } finally {
        document.body.removeChild(el);
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadFromDB();
    populateCoFilterDropdowns();
    renderPage();
});
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</body>
</html>

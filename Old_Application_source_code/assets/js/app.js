// ═══════════════════════════════════════════
// DATA
// ═══════════════════════════════════════════

let companies        = [];
let drivers          = [];
let invoices         = [];      // Invoices tab
let companyInvoices  = [];      // Invoice / Company tab (independent)
let driverInvoices   = [];      // Invoice / Driver tab (independent)

let nextCompanyId   = 1;
let nextDriverId    = 1;
let nextInvoiceId   = 1;
let nextCoInvId     = 1;
let nextDrInvId     = 1;

// Working rows for each invoice form
let jobRows   = [emptyJob()];
let coJobRows = [emptyCoJob()];
let drJobRows = [emptyDrJob()];

function emptyJob()   { return { jobNumber:'', customerName:'', from:'', to:'', cubicFeet:'', rate:'', balanceDue:'', newBalance:'', remarks:'' }; }
function emptyCoJob() { return { jobNumber:'', driverId:'',    customerName:'', from:'', to:'', cubicFeet:'', rate:'', balanceDue:'', newBalance:'', remarks:'' }; }
function emptyDrJob() { return { jobNumber:'', companyId:'',   customerName:'', from:'', to:'', cubicFeet:'', rate:'', balanceDue:'', newBalance:'', remarks:'' }; }

// Format date from YYYY-MM-DD to MM-DD-YYYY
function formatDate(dateStr) {
    if (!dateStr) return '';
    const [year, month, day] = dateStr.split('-');
    return `${month}-${day}-${year}`;
}

const SAMPLE = {
    companies: [
        { id:1, name:'BH Relocation INC',  address:'11723 Amber Park DR Suite 160', city:'Alpharetta, GA 30009', phone:'(770) 123-4567', dotNumber:'2521000', mcNumber:'875158' },
        { id:2, name:'Prime Relocations',   address:'5695 Oakbrook Parkway, Suite D', city:'Norcross, GA 30093',  phone:'(770) 954-7095', dotNumber:'806005',  mcNumber:'358641'  }
    ],
    drivers: [
        { id:1, firstName:'BAKARY', lastName:'Diallo',  phone:'(770) 555-0001', license:'DL001' },
        { id:2, firstName:'JOHN',   lastName:'Doe',     phone:'(770) 555-0002', license:'DL002' },
        { id:3, firstName:'Joseph', lastName:'Smith',   phone:'(770) 555-0003', license:'DL003' },
        { id:4, firstName:'Ahmed',  lastName:'Hassan',  phone:'(404) 555-0004', license:'DL004' }
    ],
    invoices: [
        {
            id:1, companyId:1, driverId:1, date:'2026-03-01',
            lineItems:[
                { jobNumber:'J001', customerName:'TUSTIN',   from:'Atlanta, GA',  to:'Miami, FL',   cubicFeet:200,  rate:2.50, balanceDue:100, newBalance:0,   remarks:'' },
                { jobNumber:'J002', customerName:'Sara',     from:'Norcross, GA', to:'Tampa, FL',   cubicFeet:3200, rate:2.00, balanceDue:500, newBalance:200, remarks:'Paid partial' }
            ],
            subtotal:6900, carrierFee:690, total:7590
        },
        {
            id:2, companyId:2, driverId:3, date:'2026-03-03',
            lineItems:[
                { jobNumber:'P001', customerName:'Williams', from:'Norcross, GA', to:'Dallas, TX',  cubicFeet:1500, rate:0.55, balanceDue:300, newBalance:150, remarks:'Fragile items' },
                { jobNumber:'P002', customerName:'Johnson',  from:'Atlanta, GA',  to:'Houston, TX', cubicFeet:2100, rate:0.60, balanceDue:450, newBalance:0,   remarks:'' }
            ],
            subtotal:2085, carrierFee:208.5, total:2293.5
        }
    ],
    companyInvoices: [
        {
            id:1, companyId:1, date:'2026-03-01',
            lineItems:[
                { jobNumber:'CI001', driverId:1, customerName:'TUSTIN',   from:'Atlanta, GA',  to:'Miami, FL',   cubicFeet:200,  rate:2.50, balanceDue:100, newBalance:0,   remarks:'' },
                { jobNumber:'CI002', driverId:2, customerName:'Sara',     from:'Norcross, GA', to:'Tampa, FL',   cubicFeet:3200, rate:2.00, balanceDue:500, newBalance:200, remarks:'Paid partial' }
            ],
            subtotal:6900, carrierFee:690, total:7590
        }
    ],
    driverInvoices: [
        {
            id:1, driverId:1, date:'2026-03-02',
            lineItems:[
                { jobNumber:'DI001', companyId:1, customerName:'Carter',  from:'Marietta, GA', to:'Nashville, TN', cubicFeet:600,  rate:1.50, balanceDue:200, newBalance:0,   remarks:'' },
                { jobNumber:'DI002', companyId:2, customerName:'Rivera',  from:'Atlanta, GA',  to:'Charlotte, NC', cubicFeet:950,  rate:1.75, balanceDue:0,   newBalance:0,   remarks:'Fully paid' }
            ],
            subtotal:2562.5, carrierFee:256.25, total:2818.75
        }
    ],
    nextCompanyId:3, nextDriverId:5, nextInvoiceId:3, nextCoInvId:2, nextDrInvId:2
};

// ═══════════════════════════════════════════
// STORAGE
// ═══════════════════════════════════════════

function save() {
    try {
        localStorage.setItem('ims_data', JSON.stringify({
            companies, drivers, invoices, companyInvoices, driverInvoices,
            nextCompanyId, nextDriverId, nextInvoiceId, nextCoInvId, nextDrInvId
        }));
    } catch(e) {}
}

function load() {
    try {
        const raw = localStorage.getItem('ims_data');
        if (!raw) return false;
        const d = JSON.parse(raw);
        companies       = d.companies       || [];
        drivers         = d.drivers         || [];
        invoices        = d.invoices        || [];
        companyInvoices = d.companyInvoices || [];
        driverInvoices  = d.driverInvoices  || [];
        nextCompanyId   = d.nextCompanyId   || 1;
        nextDriverId    = d.nextDriverId    || 1;
        nextInvoiceId   = d.nextInvoiceId   || 1;
        nextCoInvId     = d.nextCoInvId     || 1;
        nextDrInvId     = d.nextDrInvId     || 1;
        return true;
    } catch(e) { return false; }
}

function loadSampleData() {
    if (companies.length || drivers.length || invoices.length) {
        if (!confirm('This will replace all current data with sample data. Continue?')) return;
    }
    companies       = JSON.parse(JSON.stringify(SAMPLE.companies));
    drivers         = JSON.parse(JSON.stringify(SAMPLE.drivers));
    invoices        = JSON.parse(JSON.stringify(SAMPLE.invoices));
    companyInvoices = JSON.parse(JSON.stringify(SAMPLE.companyInvoices));
    driverInvoices  = JSON.parse(JSON.stringify(SAMPLE.driverInvoices));
    nextCompanyId   = SAMPLE.nextCompanyId;
    nextDriverId    = SAMPLE.nextDriverId;
    nextInvoiceId   = SAMPLE.nextInvoiceId;
    nextCoInvId     = SAMPLE.nextCoInvId;
    nextDrInvId     = SAMPLE.nextDrInvId;
    save();
    renderAll();
    toast('Sample data loaded!', 'success');
}

function clearAllData() {
    if (!confirm('Delete ALL data? This cannot be undone.')) return;
    companies = []; drivers = []; invoices = []; companyInvoices = []; driverInvoices = [];
    nextCompanyId = nextDriverId = nextInvoiceId = nextCoInvId = nextDrInvId = 1;
    save();
    renderAll();
    toast('All data cleared.', 'success');
}

// ═══════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════

document.querySelectorAll('.nav-tab').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab, btn));
});

function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.nav-tab').forEach(b => b.classList.remove('active'));
    document.getElementById(name).classList.add('active');
    (btn || document.querySelector(`[data-tab="${name}"]`))?.classList.add('active');
}

// ═══════════════════════════════════════════
// RENDER ALL
// ═══════════════════════════════════════════

function renderAll() {
    renderDashboard();
    renderCompanies();
    renderDrivers();
    renderInvoices();
    renderCoInvTable();
    renderDrInvTable();
}

// ═══════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════

function renderDashboard() {
    document.getElementById('statCompanies').textContent = companies.length;
    document.getElementById('statDrivers').textContent   = drivers.length;
    document.getElementById('statInvoices').textContent  = invoices.length;
    document.getElementById('statRevenue').textContent   = '$' + invoices.reduce((s, i) => s + (i.total || 0), 0).toLocaleString();

    const el = document.getElementById('recentActivity');
    if (!invoices.length) {
        el.innerHTML = '<div class="empty"><div class="empty-icon">📄</div><p>No invoices yet — create one in the Invoices tab</p></div>';
        return;
    }
    el.innerHTML = invoices.slice().reverse().slice(0, 6).map(inv => {
        const co = companies.find(c => c.id === inv.companyId);
        const dr = drivers.find(d => d.id === inv.driverId);
        const jobs = (inv.lineItems || []).length;
        const customers = [...new Set((inv.lineItems || []).map(j => j.customerName).filter(Boolean))].join(', ') || '—';
        return `
            <div class="activity-item" onclick="viewInvoice(${inv.id})" style="cursor:pointer;">
                <div class="activity-title">Invoice #${inv.id} &mdash; ${co?.name || '?'} &nbsp;|&nbsp; Driver: ${dr ? dr.firstName + ' ' + dr.lastName : '?'}</div>
                <div class="activity-details">${jobs} job(s) &nbsp;|&nbsp; Customers: ${customers} &nbsp;|&nbsp; Total: $${(inv.total || 0).toFixed(2)} &nbsp;|&nbsp; ${formatDate(inv.date)}</div>
            </div>`;
    }).join('');
}

// ═══════════════════════════════════════════
// COMPANIES
// ═══════════════════════════════════════════

function renderCompanies() {
    const tb = document.getElementById('companiesTbody');
    if (!companies.length) {
        tb.innerHTML = '<tr><td colspan="7" class="empty">No companies yet. Click "+ Add Company" to start.</td></tr>';
        return;
    }
    tb.innerHTML = companies.map(c => `
        <tr>
            <td><strong>${c.name}</strong></td>
            <td>${c.address || '—'}</td>
            <td>${c.city || '—'}</td>
            <td>${c.phone}</td>
            <td>${c.dotNumber || '—'}</td>
            <td>${c.mcNumber || '—'}</td>
            <td><div class="action-btns">
                <button class="btn-xs btn-xs-edit"   onclick="openCompanyModal(${c.id})">✏️ Edit</button>
                <button class="btn-xs btn-xs-delete" onclick="deleteCompany(${c.id})">🗑️ Delete</button>
            </div></td>
        </tr>`).join('');
}

function openCompanyModal(id) {
    document.getElementById('companyForm').reset();
    document.getElementById('editCompanyId').value = '';
    document.getElementById('companyModalTitle').textContent = 'Add Company';
    document.getElementById('companySubmitBtn').textContent  = 'Save Company';
    if (id) {
        const c = companies.find(x => x.id === id);
        if (!c) return;
        document.getElementById('editCompanyId').value  = id;
        document.getElementById('companyName').value    = c.name;
        document.getElementById('companyPhone').value   = c.phone;
        document.getElementById('companyAddress').value = c.address || '';
        document.getElementById('companyCity').value    = c.city    || '';
        document.getElementById('companyDOT').value     = c.dotNumber || '';
        document.getElementById('companyMC').value      = c.mcNumber  || '';
        document.getElementById('companyModalTitle').textContent = 'Edit Company';
        document.getElementById('companySubmitBtn').textContent  = 'Update Company';
    }
    document.getElementById('companyModal').classList.add('active');
}

function saveCompany(e) {
    e.preventDefault();
    const editId = parseInt(document.getElementById('editCompanyId').value) || null;
    const data = {
        name:      document.getElementById('companyName').value.trim(),
        phone:     document.getElementById('companyPhone').value.trim(),
        address:   document.getElementById('companyAddress').value.trim(),
        city:      document.getElementById('companyCity').value.trim(),
        dotNumber: document.getElementById('companyDOT').value.trim(),
        mcNumber:  document.getElementById('companyMC').value.trim(),
    };
    if (editId) {
        Object.assign(companies.find(c => c.id === editId), data);
        toast('Company updated!', 'success');
    } else {
        companies.push({ id: nextCompanyId++, ...data });
        toast('Company added!', 'success');
    }
    save(); renderCompanies(); renderDashboard();
    closeModal('companyModal');
}

function deleteCompany(id) {
    if (!confirm('Delete this company?')) return;
    companies = companies.filter(c => c.id !== id);
    save(); renderCompanies(); renderDashboard();
    toast('Company deleted.', 'success');
}

// ═══════════════════════════════════════════
// DRIVERS
// ═══════════════════════════════════════════

function renderDrivers() {
    const tb = document.getElementById('driversTbody');
    if (!drivers.length) {
        tb.innerHTML = '<tr><td colspan="4" class="empty">No drivers yet. Click "+ Add Driver" to start.</td></tr>';
        return;
    }
    tb.innerHTML = drivers.map(d => `
        <tr>
            <td><strong>${d.firstName} ${d.lastName}</strong></td>
            <td>${d.phone || '—'}</td>
            <td>${d.license || '—'}</td>
            <td><div class="action-btns">
                <button class="btn-xs btn-xs-edit"   onclick="openDriverModal(${d.id})">✏️ Edit</button>
                <button class="btn-xs btn-xs-delete" onclick="deleteDriver(${d.id})">🗑️ Delete</button>
            </div></td>
        </tr>`).join('');
}

function openDriverModal(id) {
    document.getElementById('driverForm').reset();
    document.getElementById('editDriverId').value = '';
    document.getElementById('driverModalTitle').textContent = 'Add Driver';
    document.getElementById('driverSubmitBtn').textContent  = 'Save Driver';
    if (id) {
        const d = drivers.find(x => x.id === id);
        if (!d) return;
        document.getElementById('editDriverId').value    = id;
        document.getElementById('driverFirstName').value = d.firstName;
        document.getElementById('driverLastName').value  = d.lastName;
        document.getElementById('driverPhone').value     = d.phone    || '';
        document.getElementById('driverLicense').value   = d.license  || '';
        document.getElementById('driverModalTitle').textContent = 'Edit Driver';
        document.getElementById('driverSubmitBtn').textContent  = 'Update Driver';
    }
    document.getElementById('driverModal').classList.add('active');
}

function saveDriver(e) {
    e.preventDefault();
    const editId = parseInt(document.getElementById('editDriverId').value) || null;
    const data = {
        firstName: document.getElementById('driverFirstName').value.trim(),
        lastName:  document.getElementById('driverLastName').value.trim(),
        phone:     document.getElementById('driverPhone').value.trim(),
        license:   document.getElementById('driverLicense').value.trim(),
    };
    if (editId) {
        Object.assign(drivers.find(d => d.id === editId), data);
        toast('Driver updated!', 'success');
    } else {
        drivers.push({ id: nextDriverId++, ...data });
        toast('Driver added!', 'success');
    }
    save(); renderDrivers(); renderDashboard();
    closeModal('driverModal');
}

function deleteDriver(id) {
    if (!confirm('Delete this driver?')) return;
    drivers = drivers.filter(d => d.id !== id);
    save(); renderDrivers(); renderDashboard();
    toast('Driver deleted.', 'success');
}

// ═══════════════════════════════════════════
// INVOICES (main tab)
// ═══════════════════════════════════════════

function renderInvoices() {
    const tb = document.getElementById('invoicesTbody');
    if (!invoices.length) {
        tb.innerHTML = '<tr><td colspan="8" class="empty">No invoices yet. Click "+ Create Invoice" to start.</td></tr>';
        return;
    }
    tb.innerHTML = invoices.map(inv => {
        const co = companies.find(c => c.id === inv.companyId);
        const dr = drivers.find(d => d.id === inv.driverId);
        const n  = (inv.lineItems || []).length;
        return `
            <tr>
                <td><strong>#${inv.id}</strong></td>
                <td>${co?.name || '?'}</td>
                <td>${dr ? dr.firstName + ' ' + dr.lastName : '?'}</td>
                <td>${n} job${n !== 1 ? 's' : ''}</td>
                <td>$${(inv.subtotal || 0).toFixed(2)}</td>
                <td><strong>$${(inv.total || 0).toFixed(2)}</strong></td>
                <td>${formatDate(inv.date)}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view"   onclick="viewInvoice(${inv.id})">👁️ View</button>
                    <button class="btn-xs btn-xs-delete" onclick="deleteInvoice(${inv.id})">🗑️ Delete</button>
                </div></td>
            </tr>`;
    }).join('');
}

function openInvoiceModal() {
    document.getElementById('invoiceForm').reset();
    document.getElementById('invoiceDate').valueAsDate = new Date();
    document.getElementById('invoiceCompany').innerHTML =
        '<option value="">-- Select Company --</option>' +
        companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    document.getElementById('invoiceDriver').innerHTML =
        '<option value="">-- Select Driver --</option>' +
        drivers.map(d => `<option value="${d.id}">${d.firstName} ${d.lastName}</option>`).join('');
    jobRows = [emptyJob()];
    renderJobRows();
    document.getElementById('invoiceModal').classList.add('active');
}

function addJobRow()         { jobRows.push(emptyJob()); renderJobRows(); }
function removeJobRow(idx)   { if (jobRows.length === 1) return; jobRows.splice(idx, 1); renderJobRows(); }

function setJobField(idx, field, val) {
    const num = ['cubicFeet','rate','balanceDue','newBalance'];
    jobRows[idx][field] = num.includes(field) ? (parseFloat(val) || 0) : val;
    if (field === 'cubicFeet' || field === 'rate') renderJobRows();
    else updateInvSummary();
}

function renderJobRows() {
    document.getElementById('lineItemsContainer').innerHTML = jobRows.map((r, i) => {
        const total = ((r.cubicFeet || 0) * (r.rate || 0)).toFixed(2);
        return `
        <div class="line-item-row">
            <input type="text"   placeholder="Job #"    value="${esc(r.jobNumber)}"    onchange="setJobField(${i},'jobNumber',this.value)">
            <input type="text"   placeholder="Customer" value="${esc(r.customerName)}" onchange="setJobField(${i},'customerName',this.value)">
            <input type="text"   placeholder="From"     value="${esc(r.from)}"         onchange="setJobField(${i},'from',this.value)">
            <input type="text"   placeholder="To"       value="${esc(r.to)}"           onchange="setJobField(${i},'to',this.value)">
            <input type="number" placeholder="CF"       value="${r.cubicFeet || ''}"   onchange="setJobField(${i},'cubicFeet',this.value)">
            <input type="number" placeholder="Rate"     value="${r.rate || ''}" step="0.01" onchange="setJobField(${i},'rate',this.value)">
            <div class="cell-total">$${total}</div>
            <input type="number" placeholder="Bal Due"  value="${r.balanceDue || ''}"  step="0.01" onchange="setJobField(${i},'balanceDue',this.value)">
            <input type="number" placeholder="New Bal"  value="${r.newBalance || ''}"  step="0.01" onchange="setJobField(${i},'newBalance',this.value)">
            <input type="text"   placeholder="Remarks"  value="${esc(r.remarks)}"      onchange="setJobField(${i},'remarks',this.value)">
            ${jobRows.length > 1
                ? `<button type="button" class="btn-remove" onclick="removeJobRow(${i})">&#x2715;</button>`
                : '<div></div>'}
        </div>`;
    }).join('');
    updateInvSummary();
}

function updateInvSummary() {
    const sub = jobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    document.getElementById('invSubtotal').textContent   = '$' + sub.toFixed(2);
    document.getElementById('invCarrierFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('invTotal').textContent      = '$' + (sub + fee).toFixed(2);
}

function saveInvoice(e) {
    e.preventDefault();
    const cid = parseInt(document.getElementById('invoiceCompany').value);
    const did = parseInt(document.getElementById('invoiceDriver').value);
    if (!cid || !did) { toast('Please select company and driver.', 'error'); return; }
    if (!jobRows.some(r => r.customerName || r.jobNumber)) { toast('Add at least one job.', 'error'); return; }
    const sub = jobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    invoices.push({
        id: nextInvoiceId++, companyId: cid, driverId: did,
        date:      document.getElementById('invoiceDate').value,
        lineItems: JSON.parse(JSON.stringify(jobRows)),
        subtotal: sub, carrierFee: fee, total: sub + fee
    });
    save(); renderInvoices(); renderDashboard();
    closeModal('invoiceModal');
    toast('Invoice saved!', 'success');
}

function deleteInvoice(id) {
    if (!confirm('Delete this invoice?')) return;
    invoices = invoices.filter(i => i.id !== id);
    save(); renderInvoices(); renderDashboard();
    toast('Invoice deleted.', 'success');
}

// ═══════════════════════════════════════════
// COMPANY INVOICES (Invoice / Company tab)
// ═══════════════════════════════════════════

function renderCoInvTable() {
    const tb = document.getElementById('coInvTbody');
    if (!companyInvoices.length) {
        tb.innerHTML = '<tr><td colspan="7" class="empty">No company invoices yet. Click "+ Create Invoice" to start.</td></tr>';
        return;
    }
    tb.innerHTML = companyInvoices.map(inv => {
        const co = companies.find(c => c.id === inv.companyId);
        const n  = (inv.lineItems || []).length;
        return `
            <tr>
                <td><strong>#${inv.id}</strong></td>
                <td>${co?.name || '?'}</td>
                <td>${n} job${n !== 1 ? 's' : ''}</td>
                <td>$${(inv.subtotal || 0).toFixed(2)}</td>
                <td><strong>$${(inv.total || 0).toFixed(2)}</strong></td>
                <td>${formatDate(inv.date)}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view"   onclick="viewCoInvoice(${inv.id})">👁️ View</button>
                    <button class="btn-xs btn-xs-delete" onclick="deleteCoInvoice(${inv.id})">🗑️ Delete</button>
                </div></td>
            </tr>`;
    }).join('');
}

function openCoInvModal() {
    document.getElementById('coInvForm').reset();
    document.getElementById('coInvDate').valueAsDate = new Date();
    document.getElementById('coInvCompany').innerHTML =
        '<option value="">-- Select Company --</option>' +
        companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    coJobRows = [emptyCoJob()];
    renderCoJobRows();
    document.getElementById('coInvModal').classList.add('active');
}

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
            <input type="text"   placeholder="Job #"    value="${esc(r.jobNumber)}"    onchange="setCoJob(${i},'jobNumber',this.value)">
            <select onchange="setCoJob(${i},'driverId',this.value)">${driverSel}</select>
            <input type="text"   placeholder="Customer" value="${esc(r.customerName)}" onchange="setCoJob(${i},'customerName',this.value)">
            <input type="text"   placeholder="From"     value="${esc(r.from)}"         onchange="setCoJob(${i},'from',this.value)">
            <input type="text"   placeholder="To"       value="${esc(r.to)}"           onchange="setCoJob(${i},'to',this.value)">
            <input type="number" placeholder="CF"       value="${r.cubicFeet || ''}"   onchange="setCoJob(${i},'cubicFeet',this.value)">
            <input type="number" placeholder="Rate"     value="${r.rate || ''}" step="0.01" onchange="setCoJob(${i},'rate',this.value)">
            <div class="cell-total">$${total}</div>
            <input type="number" placeholder="Bal Due"  value="${r.balanceDue || ''}"  step="0.01" onchange="setCoJob(${i},'balanceDue',this.value)">
            <input type="number" placeholder="New Bal"  value="${r.newBalance || ''}"  step="0.01" onchange="setCoJob(${i},'newBalance',this.value)">
            <input type="text"   placeholder="Remarks"  value="${esc(r.remarks)}"      onchange="setCoJob(${i},'remarks',this.value)">
            ${coJobRows.length > 1
                ? `<button type="button" class="btn-remove" onclick="removeCoJobRow(${i})">&#x2715;</button>`
                : '<div></div>'}
        </div>`;
    }).join('');
    updateCoSummary();
}

function updateCoSummary() {
    const sub = coJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    document.getElementById('coSubtotal').textContent   = '$' + sub.toFixed(2);
    document.getElementById('coCarrierFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('coTotal').textContent      = '$' + (sub + fee).toFixed(2);
}

function saveCoInvoice(e) {
    e.preventDefault();
    const cid = parseInt(document.getElementById('coInvCompany').value);
    if (!cid) { toast('Please select a company.', 'error'); return; }
    if (!coJobRows.some(r => r.customerName || r.jobNumber)) { toast('Add at least one job.', 'error'); return; }
    const sub = coJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    companyInvoices.push({
        id: nextCoInvId++, companyId: cid,
        date:      document.getElementById('coInvDate').value,
        lineItems: JSON.parse(JSON.stringify(coJobRows)),
        subtotal: sub, carrierFee: fee, total: sub + fee
    });
    save(); renderCoInvTable();
    closeModal('coInvModal');
    toast('Company invoice saved!', 'success');
}

function deleteCoInvoice(id) {
    if (!confirm('Delete this invoice?')) return;
    companyInvoices = companyInvoices.filter(i => i.id !== id);
    save(); renderCoInvTable();
    toast('Invoice deleted.', 'success');
}

function viewCoInvoice(id) {
    const inv = companyInvoices.find(i => i.id === id);
    if (!inv) return;
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
        const driverName = dr.firstName ? `${dr.firstName} ${dr.lastName}` : '—';
        return `<tr>
            <td>${j.jobNumber || ''}</td>
            <td>${driverName}</td>
            <td>${j.customerName || ''}</td>
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

    document.getElementById('invoiceViewContent').innerHTML = `
        <div class="inv-view">
            <div class="inv-view-hdr">
                <h2>${co.name || 'Company'}</h2>
                <p>${co.address || ''}${co.city ? ', ' + co.city : ''}</p>
                <p>US DOT: ${co.dotNumber || '—'} &nbsp;&nbsp; MC/ICC: ${co.mcNumber || '—'} &nbsp;&nbsp; Tel: ${co.phone || '—'}</p>
            </div>
            <div class="inv-meta">
                <div><strong>Invoice #:</strong> CI-${inv.id}<br><strong>Date:</strong> ${formatDate(inv.date)}<br><strong>Type:</strong> Company Invoice</div>
                <div><strong>Total Jobs:</strong> ${jobs.length}<br><strong>Total CF:</strong> ${totalCF}<br><strong>Total Due:</strong> $${(inv.total || 0).toFixed(2)}</div>
            </div>
            <div style="overflow-x:auto;">
            <table class="inv-table">
                <thead><tr>
                    <th>Job #</th><th>Driver</th><th>Customer</th><th>From</th><th>To</th>
                    <th>CF</th><th>Rate</th><th>Total</th><th>Bal. Due</th><th>New Bal.</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                    ${rows}
                    <tr class="inv-total-row">
                        <td colspan="5"><strong>TOTALS</strong></td>
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
            <div class="inv-summary" style="margin-top:20px;">
                <div class="inv-summary-row"><span>Subtotal:</span><span>$${(inv.subtotal || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>Carrier Fee (10%):</span><span>$${(inv.carrierFee || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>TOTAL DUE:</span><span>$${(inv.total || 0).toFixed(2)}</span></div>
            </div>
            <div style="display:flex;gap:40px;margin-top:40px;">
                <div style="flex:1;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Authorized Signature</div>
                <div style="flex:0.4;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Date</div>
            </div>
        </div>`;
    document.getElementById('invoiceViewModal').classList.add('active');
}

// ═══════════════════════════════════════════
// DRIVER INVOICES (Invoice / Driver tab)
// ═══════════════════════════════════════════

function renderDrInvTable() {
    const tb = document.getElementById('drInvTbody');
    if (!driverInvoices.length) {
        tb.innerHTML = '<tr><td colspan="7" class="empty">No driver invoices yet. Click "+ Create Invoice" to start.</td></tr>';
        return;
    }
    tb.innerHTML = driverInvoices.map(inv => {
        const dr = drivers.find(d => d.id === inv.driverId);
        const n  = (inv.lineItems || []).length;
        return `
            <tr>
                <td><strong>#${inv.id}</strong></td>
                <td>${dr ? dr.firstName + ' ' + dr.lastName : '?'}</td>
                <td>${n} job${n !== 1 ? 's' : ''}</td>
                <td>$${(inv.subtotal || 0).toFixed(2)}</td>
                <td><strong>$${(inv.total || 0).toFixed(2)}</strong></td>
                <td>${formatDate(inv.date)}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view"   onclick="viewDrInvoice(${inv.id})">👁️ View</button>
                    <button class="btn-xs btn-xs-delete" onclick="deleteDrInvoice(${inv.id})">🗑️ Delete</button>
                </div></td>
            </tr>`;
    }).join('');
}

function openDrInvModal() {
    document.getElementById('drInvForm').reset();
    document.getElementById('drInvDate').valueAsDate = new Date();
    document.getElementById('drInvDriver').innerHTML =
        '<option value="">-- Select Driver --</option>' +
        drivers.map(d => `<option value="${d.id}">${d.firstName} ${d.lastName}</option>`).join('');
    drJobRows = [emptyDrJob()];
    renderDrJobRows();
    document.getElementById('drInvModal').classList.add('active');
}

function addDrJobRow()       { drJobRows.push(emptyDrJob()); renderDrJobRows(); }
function removeDrJobRow(idx) { if (drJobRows.length === 1) return; drJobRows.splice(idx, 1); renderDrJobRows(); }

function setDrJob(idx, field, val) {
    const num = ['cubicFeet','rate','balanceDue','newBalance'];
    drJobRows[idx][field] = num.includes(field) ? (parseFloat(val) || 0) : val;
    if (field === 'cubicFeet' || field === 'rate') renderDrJobRows();
    else updateDrSummary();
}

function renderDrJobRows() {
    document.getElementById('drJobsContainer').innerHTML = drJobRows.map((r, i) => {
        const total = ((r.cubicFeet || 0) * (r.rate || 0)).toFixed(2);
        const companySel = '<option value="">-- Company --</option>' +
            companies.map(c => `<option value="${c.id}" ${c.id == r.companyId ? 'selected' : ''}>${c.name}</option>`).join('');
        return `
        <div class="line-item-row-xl">
            <input type="text"   placeholder="Job #"    value="${esc(r.jobNumber)}"    onchange="setDrJob(${i},'jobNumber',this.value)">
            <select onchange="setDrJob(${i},'companyId',this.value)">${companySel}</select>
            <input type="text"   placeholder="Customer" value="${esc(r.customerName)}" onchange="setDrJob(${i},'customerName',this.value)">
            <input type="text"   placeholder="From"     value="${esc(r.from)}"         onchange="setDrJob(${i},'from',this.value)">
            <input type="text"   placeholder="To"       value="${esc(r.to)}"           onchange="setDrJob(${i},'to',this.value)">
            <input type="number" placeholder="CF"       value="${r.cubicFeet || ''}"   onchange="setDrJob(${i},'cubicFeet',this.value)">
            <input type="number" placeholder="Rate"     value="${r.rate || ''}" step="0.01" onchange="setDrJob(${i},'rate',this.value)">
            <div class="cell-total">$${total}</div>
            <input type="number" placeholder="Bal Due"  value="${r.balanceDue || ''}"  step="0.01" onchange="setDrJob(${i},'balanceDue',this.value)">
            <input type="number" placeholder="New Bal"  value="${r.newBalance || ''}"  step="0.01" onchange="setDrJob(${i},'newBalance',this.value)">
            <input type="text"   placeholder="Remarks"  value="${esc(r.remarks)}"      onchange="setDrJob(${i},'remarks',this.value)">
            ${drJobRows.length > 1
                ? `<button type="button" class="btn-remove" onclick="removeDrJobRow(${i})">&#x2715;</button>`
                : '<div></div>'}
        </div>`;
    }).join('');
    updateDrSummary();
}

function updateDrSummary() {
    const sub = drJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    document.getElementById('drSubtotal').textContent   = '$' + sub.toFixed(2);
    document.getElementById('drCarrierFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('drTotal').textContent      = '$' + (sub + fee).toFixed(2);
}

function saveDrInvoice(e) {
    e.preventDefault();
    const did = parseInt(document.getElementById('drInvDriver').value);
    if (!did) { toast('Please select a driver.', 'error'); return; }
    if (!drJobRows.some(r => r.customerName || r.jobNumber)) { toast('Add at least one job.', 'error'); return; }
    const sub = drJobRows.reduce((s, r) => s + (r.cubicFeet || 0) * (r.rate || 0), 0);
    const fee = sub * 0.1;
    driverInvoices.push({
        id: nextDrInvId++, driverId: did,
        date:      document.getElementById('drInvDate').value,
        lineItems: JSON.parse(JSON.stringify(drJobRows)),
        subtotal: sub, carrierFee: fee, total: sub + fee
    });
    save(); renderDrInvTable();
    closeModal('drInvModal');
    toast('Driver invoice saved!', 'success');
}

function deleteDrInvoice(id) {
    if (!confirm('Delete this invoice?')) return;
    driverInvoices = driverInvoices.filter(i => i.id !== id);
    save(); renderDrInvTable();
    toast('Invoice deleted.', 'success');
}

function viewDrInvoice(id) {
    const inv = driverInvoices.find(i => i.id === id);
    if (!inv) return;
    const dr   = drivers.find(d => d.id === inv.driverId) || {};
    const jobs = inv.lineItems || [];

    let totalCF = 0, totalAmt = 0, totalBal = 0, totalNewBal = 0;
    jobs.forEach(j => {
        totalCF     += (j.cubicFeet || 0);
        totalAmt    += (j.cubicFeet || 0) * (j.rate || 0);
        totalBal    += (j.balanceDue || 0);
        totalNewBal += (j.newBalance || 0);
    });

    const rows = jobs.map(j => {
        const co = companies.find(c => c.id == j.companyId) || {};
        const companyName = co.name || '—';
        return `<tr>
            <td>${j.jobNumber || ''}</td>
            <td>${companyName}</td>
            <td>${j.customerName || ''}</td>
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

    document.getElementById('invoiceViewContent').innerHTML = `
        <div class="inv-view">
            <div class="inv-view-hdr">
                <h2>${dr.firstName || ''} ${dr.lastName || ''}</h2>
                <p>Driver Statement</p>
                <p>Phone: ${dr.phone || '—'} &nbsp;&nbsp; License: ${dr.license || '—'}</p>
            </div>
            <div class="inv-meta">
                <div><strong>Invoice #:</strong> DI-${inv.id}<br><strong>Date:</strong> ${formatDate(inv.date)}<br><strong>Type:</strong> Driver Invoice</div>
                <div><strong>Total Jobs:</strong> ${jobs.length}<br><strong>Total CF:</strong> ${totalCF}<br><strong>Total Due:</strong> $${(inv.total || 0).toFixed(2)}</div>
            </div>
            <div style="overflow-x:auto;">
            <table class="inv-table">
                <thead><tr>
                    <th>Job #</th><th>Company</th><th>Customer</th><th>From</th><th>To</th>
                    <th>CF</th><th>Rate</th><th>Total</th><th>Bal. Due</th><th>New Bal.</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                    ${rows}
                    <tr class="inv-total-row">
                        <td colspan="5"><strong>TOTALS</strong></td>
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
            <div class="inv-summary" style="margin-top:20px;">
                <div class="inv-summary-row"><span>Subtotal:</span><span>$${(inv.subtotal || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>Carrier Fee (10%):</span><span>$${(inv.carrierFee || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>TOTAL DUE:</span><span>$${(inv.total || 0).toFixed(2)}</span></div>
            </div>
            <div style="display:flex;gap:40px;margin-top:40px;">
                <div style="flex:1;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Driver Signature</div>
                <div style="flex:0.4;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Date</div>
            </div>
        </div>`;
    document.getElementById('invoiceViewModal').classList.add('active');
}

// ═══════════════════════════════════════════
// INVOICE VIEW (main invoices tab)
// ═══════════════════════════════════════════

function viewInvoice(id) {
    const inv = invoices.find(i => i.id === id);
    if (!inv) return;
    const co   = companies.find(c => c.id === inv.companyId) || {};
    const dr   = drivers.find(d => d.id === inv.driverId)    || {};
    const jobs = inv.lineItems || [];
    let totalCF = 0;
    jobs.forEach(j => { totalCF += (j.cubicFeet || 0); });

    const rows = jobs.map(j => `
        <tr>
            <td>${j.jobNumber || ''}</td>
            <td>${j.customerName || ''}</td>
            <td>${j.from || ''}</td>
            <td>${j.to || ''}</td>
            <td>${j.cubicFeet || 0}</td>
            <td>$${parseFloat(j.rate || 0).toFixed(2)}</td>
            <td>$${((j.cubicFeet || 0) * (j.rate || 0)).toFixed(2)}</td>
            <td>$${parseFloat(j.balanceDue || 0).toFixed(2)}</td>
            <td>$${parseFloat(j.newBalance || 0).toFixed(2)}</td>
            <td>${j.remarks || ''}</td>
        </tr>`).join('');

    document.getElementById('invoiceViewContent').innerHTML = `
        <div class="inv-view">
            <div class="inv-view-hdr">
                <h2>${co.name || 'Company'}</h2>
                <p>${co.address || ''}${co.city ? ', ' + co.city : ''}</p>
                <p>US DOT: ${co.dotNumber || '—'} &nbsp;&nbsp; MC/ICC: ${co.mcNumber || '—'} &nbsp;&nbsp; Tel: ${co.phone || '—'}</p>
            </div>
            <div class="inv-meta">
                <div>
                    <strong>Invoice #:</strong> ${inv.id}<br>
                    <strong>Date:</strong> ${formatDate(inv.date)}<br>
                    <strong>Driver:</strong> ${dr.firstName || ''} ${dr.lastName || ''}<br>
                    <strong>Phone:</strong> ${dr.phone || '—'}
                </div>
                <div>
                    <strong>Total Jobs:</strong> ${jobs.length}<br>
                    <strong>Total CF:</strong> ${totalCF}<br>
                    <strong>Subtotal:</strong> $${(inv.subtotal || 0).toFixed(2)}<br>
                    <strong>Carrier Fee (10%):</strong> $${(inv.carrierFee || 0).toFixed(2)}
                </div>
            </div>
            <div style="overflow-x:auto;">
            <table class="inv-table">
                <thead><tr>
                    <th>Job #</th><th>Customer</th><th>From</th><th>To</th>
                    <th>CF</th><th>Rate</th><th>Total</th>
                    <th>Bal. Due</th><th>New Bal.</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                    ${rows}
                    <tr class="inv-total-row">
                        <td colspan="4"><strong>TOTALS</strong></td>
                        <td><strong>${totalCF}</strong></td>
                        <td></td>
                        <td><strong>$${(inv.subtotal || 0).toFixed(2)}</strong></td>
                        <td></td><td></td><td></td>
                    </tr>
                </tbody>
            </table>
            </div>
            <div class="inv-summary" style="margin-top:20px;">
                <div class="inv-summary-row"><span>Subtotal:</span><span>$${(inv.subtotal || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>Carrier Fee (10%):</span><span>$${(inv.carrierFee || 0).toFixed(2)}</span></div>
                <div class="inv-summary-row"><span>TOTAL DUE:</span><span>$${(inv.total || 0).toFixed(2)}</span></div>
            </div>
            <div style="display:flex;gap:40px;margin-top:40px;">
                <div style="flex:1;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Driver Signature</div>
                <div style="flex:0.4;border-top:2px solid #333;padding-top:6px;font-size:12px;color:#555;">Date</div>
            </div>
        </div>`;
    document.getElementById('invoiceViewModal').classList.add('active');
}

// ═══════════════════════════════════════════
// MODAL HELPERS
// ═══════════════════════════════════════════

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
});

// ═══════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════

function toast(msg, type) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = `toast show ${type}`;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 3000);
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ═══════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    if (!load()) loadSampleData();
    else renderAll();
});

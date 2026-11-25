<?php
require 'includes/auth.php';
$pageTitle  = 'Companies';
$activePage = 'companies';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:20px;">Companies</h2>
        <div class="btn-row">
            <button class="btn btn-success" onclick="openCompanyModal()">+ Add Company</button>
        </div>
        <div class="filter-bar">
            <input type="text" id="coSearchName"  placeholder="🔍 Search company..."  oninput="applyCompanyFilters()">
            <input type="text" id="coSearchPhone" placeholder="🔍 Search phone..."     oninput="applyCompanyFilters()">
            <input type="text" id="coSearchDot"   placeholder="🔍 Search DOT #..."     oninput="applyCompanyFilters()">
            <button class="btn-clear" onclick="clearCompanyFilters()">✕ Clear</button>
            <span class="filter-count" id="coFilterCount"></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Company Name</th><th>Address</th><th>City</th><th>Phone</th><th>DOT #</th><th>MC #</th><th>Actions</th></tr></thead>
                <tbody id="companiesTbody"></tbody>
            </table>
        </div>
        <div id="companiesPagination" class="pagination-wrap"></div>
    </div>

<!-- Company Modal -->
<div id="companyModal" class="modal">
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h2 id="companyModalTitle">Add Company</h2>
            <button class="close-btn" onclick="closeModal('companyModal')">&times;</button>
        </div>
        <form id="companyForm" onsubmit="saveCompany(event)">
            <input type="hidden" id="editCompanyId">
            <div class="form-grid">
                <div class="form-group"><label>Company Name *</label><input type="text" id="companyName" required placeholder="e.g. BH Relocation INC"></div>
                <div class="form-group"><label>Phone *</label><input type="text" id="companyPhone" required placeholder="(000) 000-0000"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Street Address</label><input type="text" id="companyAddress" placeholder="Street address"></div>
                <div class="form-group"><label>City, State ZIP</label><input type="text" id="companyCity" placeholder="Atlanta, GA 30301"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>US DOT Number</label><input type="text" id="companyDOT" placeholder="DOT #"></div>
                <div class="form-group"><label>MC / ICC Number</label><input type="text" id="companyMC" placeholder="MC #"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('companyModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="companySubmitBtn">Save Company</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
const PAGE_SIZE = 30;
let currentPage = 1;

let coSearchName  = '';
let coSearchPhone = '';
let coSearchDot   = '';

function getFilteredCompanies() {
    return companies.filter(c => {
        if (coSearchName  && !(c.name      || '').toLowerCase().includes(coSearchName))  return false;
        if (coSearchPhone && !(c.phone     || '').toLowerCase().includes(coSearchPhone)) return false;
        if (coSearchDot   && !(c.dotNumber || '').toLowerCase().includes(coSearchDot))   return false;
        return true;
    });
}

function applyCompanyFilters() {
    coSearchName  = document.getElementById('coSearchName').value.toLowerCase().trim();
    coSearchPhone = document.getElementById('coSearchPhone').value.toLowerCase().trim();
    coSearchDot   = document.getElementById('coSearchDot').value.toLowerCase().trim();
    currentPage = 1;
    renderPage();
}

function clearCompanyFilters() {
    document.getElementById('coSearchName').value  = '';
    document.getElementById('coSearchPhone').value = '';
    document.getElementById('coSearchDot').value   = '';
    coSearchName = ''; coSearchPhone = ''; coSearchDot = '';
    currentPage = 1;
    renderPage();
}

function renderPage() {
    const tb       = document.getElementById('companiesTbody');
    const filtered = getFilteredCompanies();
    const total    = filtered.length;
    document.getElementById('coFilterCount').textContent = (coSearchName || coSearchPhone || coSearchDot)
        ? `${total} result${total !== 1 ? 's' : ''}` : '';
    if (!total) {
        tb.innerHTML = `<tr><td colspan="7" class="empty">${companies.length ? 'No companies match the current search.' : 'No companies yet. Click "+ Add Company" to start.'}</td></tr>`;
        renderPagination('companiesPagination', 0, 1, PAGE_SIZE, () => {});
        return;
    }
    currentPage = Math.min(currentPage, Math.max(1, Math.ceil(total / PAGE_SIZE)));
    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageData = filtered.slice(start, start + PAGE_SIZE);
    tb.innerHTML = pageData.map(c => `
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
    renderPagination('companiesPagination', total, currentPage, PAGE_SIZE, p => {
        currentPage = p;
        renderPage();
    });
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

async function saveCompany(e) {
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
    try {
        if (editId) {
            await api('companies', 'PUT', data, editId);
            Object.assign(companies.find(c => c.id === editId), data);
            toast('Company updated!', 'success');
        } else {
            const res = await api('companies', 'POST', data);
            companies.push({ id: res.id, ...data });
            toast('Company added!', 'success');
        }
        renderPage();
        closeModal('companyModal');
    } catch (_) { /* error already shown by api() */ }
}

async function deleteCompany(id) {
    if (!confirm('Delete this company?')) return;
    try {
        await api('companies', 'DELETE', null, id);
        companies = companies.filter(c => c.id !== id);
        renderPage();
        toast('Company deleted.', 'success');
    } catch (_) { /* error already shown by api() */ }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadFromDB();
    renderPage();
});
</script>
</body>
</html>

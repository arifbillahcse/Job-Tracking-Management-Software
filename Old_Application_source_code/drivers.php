<?php
require 'includes/auth.php';
$pageTitle  = 'Drivers';
$activePage = 'drivers';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:20px;">Drivers</h2>
        <div class="btn-row">
            <button class="btn btn-success" onclick="openDriverModal()">+ Add Driver</button>
        </div>
        <div class="filter-bar">
            <input type="text" id="drvrSearchName"    placeholder="🔍 Search full name..."  oninput="applyDriverFilters()">
            <input type="text" id="drvrSearchPhone"   placeholder="🔍 Search phone..."       oninput="applyDriverFilters()">
            <input type="text" id="drvrSearchLicense" placeholder="🔍 Search license #..."   oninput="applyDriverFilters()">
            <button class="btn-clear" onclick="clearDriverFilters()">✕ Clear</button>
            <span class="filter-count" id="drvrFilterCount"></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Full Name</th><th>Phone</th><th>License #</th><th>Actions</th></tr></thead>
                <tbody id="driversTbody"></tbody>
            </table>
        </div>
        <div id="driversPagination" class="pagination-wrap"></div>
    </div>

<!-- Driver Modal -->
<div id="driverModal" class="modal">
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h2 id="driverModalTitle">Add Driver</h2>
            <button class="close-btn" onclick="closeModal('driverModal')">&times;</button>
        </div>
        <form id="driverForm" onsubmit="saveDriver(event)">
            <input type="hidden" id="editDriverId">
            <div class="form-grid">
                <div class="form-group"><label>First Name *</label><input type="text" id="driverFirstName" required placeholder="First name"></div>
                <div class="form-group"><label>Last Name *</label><input type="text" id="driverLastName" required placeholder="Last name"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Phone</label><input type="text" id="driverPhone" placeholder="(000) 000-0000"></div>
                <div class="form-group"><label>License Number</label><input type="text" id="driverLicense" placeholder="License #"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('driverModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="driverSubmitBtn">Save Driver</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
const PAGE_SIZE = 30;
let currentPage = 1;

let drvrSearchName    = '';
let drvrSearchPhone   = '';
let drvrSearchLicense = '';

function getFilteredDrivers() {
    return drivers.filter(d => {
        const fullName = (d.firstName + ' ' + d.lastName).toLowerCase();
        if (drvrSearchName    && !fullName.includes(drvrSearchName))                      return false;
        if (drvrSearchPhone   && !(d.phone   || '').toLowerCase().includes(drvrSearchPhone))   return false;
        if (drvrSearchLicense && !(d.license || '').toLowerCase().includes(drvrSearchLicense)) return false;
        return true;
    });
}

function applyDriverFilters() {
    drvrSearchName    = document.getElementById('drvrSearchName').value.toLowerCase().trim();
    drvrSearchPhone   = document.getElementById('drvrSearchPhone').value.toLowerCase().trim();
    drvrSearchLicense = document.getElementById('drvrSearchLicense').value.toLowerCase().trim();
    currentPage = 1;
    renderPage();
}

function clearDriverFilters() {
    document.getElementById('drvrSearchName').value    = '';
    document.getElementById('drvrSearchPhone').value   = '';
    document.getElementById('drvrSearchLicense').value = '';
    drvrSearchName = ''; drvrSearchPhone = ''; drvrSearchLicense = '';
    currentPage = 1;
    renderPage();
}

function renderPage() {
    const tb       = document.getElementById('driversTbody');
    const filtered = getFilteredDrivers();
    const total    = filtered.length;
    document.getElementById('drvrFilterCount').textContent = (drvrSearchName || drvrSearchPhone || drvrSearchLicense)
        ? `${total} result${total !== 1 ? 's' : ''}` : '';
    if (!total) {
        tb.innerHTML = `<tr><td colspan="4" class="empty">${drivers.length ? 'No drivers match the current search.' : 'No drivers yet. Click "+ Add Driver" to start.'}</td></tr>`;
        renderPagination('driversPagination', 0, 1, PAGE_SIZE, () => {});
        return;
    }
    currentPage = Math.min(currentPage, Math.max(1, Math.ceil(total / PAGE_SIZE)));
    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageData = filtered.slice(start, start + PAGE_SIZE);
    tb.innerHTML = pageData.map(d => `
        <tr>
            <td><strong>${d.firstName} ${d.lastName}</strong></td>
            <td>${d.phone || '—'}</td>
            <td>${d.license || '—'}</td>
            <td><div class="action-btns">
                <button class="btn-xs btn-xs-edit"   onclick="openDriverModal(${d.id})">✏️ Edit</button>
                <button class="btn-xs btn-xs-delete" onclick="deleteDriver(${d.id})">🗑️ Delete</button>
            </div></td>
        </tr>`).join('');
    renderPagination('driversPagination', total, currentPage, PAGE_SIZE, p => {
        currentPage = p;
        renderPage();
    });
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
        document.getElementById('driverPhone').value     = d.phone   || '';
        document.getElementById('driverLicense').value   = d.license || '';
        document.getElementById('driverModalTitle').textContent = 'Edit Driver';
        document.getElementById('driverSubmitBtn').textContent  = 'Update Driver';
    }
    document.getElementById('driverModal').classList.add('active');
}

async function saveDriver(e) {
    e.preventDefault();
    const editId = parseInt(document.getElementById('editDriverId').value) || null;
    const data = {
        firstName: document.getElementById('driverFirstName').value.trim(),
        lastName:  document.getElementById('driverLastName').value.trim(),
        phone:     document.getElementById('driverPhone').value.trim(),
        license:   document.getElementById('driverLicense').value.trim(),
    };
    try {
        if (editId) {
            await api('drivers', 'PUT', data, editId);
            Object.assign(drivers.find(d => d.id === editId), data);
            toast('Driver updated!', 'success');
        } else {
            const res = await api('drivers', 'POST', data);
            drivers.push({ id: res.id, ...data });
            toast('Driver added!', 'success');
        }
        renderPage();
        closeModal('driverModal');
    } catch (_) { /* error already shown by api() */ }
}

async function deleteDriver(id) {
    if (!confirm('Delete this driver?')) return;
    try {
        await api('drivers', 'DELETE', null, id);
        drivers = drivers.filter(d => d.id !== id);
        renderPage();
        toast('Driver deleted.', 'success');
    } catch (_) { /* error already shown by api() */ }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadFromDB();
    renderPage();
});
</script>
</body>
</html>

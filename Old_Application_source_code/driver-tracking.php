<?php
require 'includes/auth.php';
$pageTitle  = 'Driver Tracking';
$activePage = 'driver-tracking';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:25px;">🗺️ Driver Tracking</h2>

        <div class="filter-bar">
            <input type="text" id="searchDriver" placeholder="🔍 Search driver..." oninput="filterDrivers()">
            <input type="text" id="searchStatus" placeholder="🔍 Filter by status..." oninput="filterDrivers()">
            <button class="btn-clear" onclick="clearDriverSearch()">✕ Clear</button>
            <span class="filter-count" id="filterCount"></span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Driver Name</th>
                        <th>Phone</th>
                        <th>License</th>
                        <th>Active Jobs</th>
                        <th>Current Job</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="driverTableBody">
                    <tr>
                        <td colspan="8" class="empty">Loading driver data...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="driverPagination" class="pagination-wrap"></div>
    </div>

<?php include 'includes/footer.php'; ?>

<script>
let drivers = [];
let driverInvoices = [];
let currentPage = 1;
const PAGE_SIZE = 30;

async function loadData() {
    try {
        const [driversData, diData] = await Promise.all([
            api('drivers'),
            api('inv-driver')
        ]);
        drivers = driversData;
        driverInvoices = diData;
        renderTable();
    } catch (e) {
        toast('Error loading data', 'error');
    }
}

function getDriverInvoices(driverId) {
    return driverInvoices.filter(inv => inv.driverId === driverId);
}

function filterDrivers() {
    const searchDriver = document.getElementById('searchDriver').value.toLowerCase().trim();
    const searchStatus = document.getElementById('searchStatus').value.toLowerCase().trim();

    return drivers.filter(d => {
        const name = `${d.firstName} ${d.lastName}`.toLowerCase();
        const phone = (d.phone || '').toLowerCase();

        if (searchDriver && !name.includes(searchDriver) && !phone.includes(searchDriver)) return false;

        const invoices = getDriverInvoices(d.id);
        const status = invoices.length > 0 ? 'active' : 'available';
        if (searchStatus && !status.includes(searchStatus)) return false;

        return true;
    });
}

function clearDriverSearch() {
    document.getElementById('searchDriver').value = '';
    document.getElementById('searchStatus').value = '';
    renderTable();
}

function renderTable() {
    const tb = document.getElementById('driverTableBody');
    const filtered = filterDrivers();
    const total = filtered.length;

    document.getElementById('filterCount').textContent =
        document.getElementById('searchDriver').value || document.getElementById('searchStatus').value
        ? `${total} result${total !== 1 ? 's' : ''}` : '';

    if (!total) {
        tb.innerHTML = '<tr><td colspan="8" class="empty">No drivers found.</td></tr>';
        renderPagination('driverPagination', 0, 1, PAGE_SIZE, () => {});
        return;
    }

    currentPage = Math.min(currentPage, Math.max(1, Math.ceil(total / PAGE_SIZE)));
    const start = (currentPage - 1) * PAGE_SIZE;
    const pageData = filtered.slice(start, start + PAGE_SIZE);

    tb.innerHTML = pageData.map(d => {
        const invoices = getDriverInvoices(d.id);
        const status = invoices.length > 0 ? 'Active' : 'Available';
        const statusColor = invoices.length > 0 ? '#4ade80' : '#94a3b8';
        const currentJob = invoices.length > 0 ? `DI-${invoices[0].id}` : '—';
        const lastUpdated = new Date().toLocaleDateString();

        return `
            <tr>
                <td><strong>${d.firstName} ${d.lastName}</strong></td>
                <td>${d.phone || '—'}</td>
                <td>${d.license || '—'}</td>
                <td><span style="background:#3b82f6;color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;">${invoices.length}</span></td>
                <td><strong>${currentJob}</strong></td>
                <td><span style="color:${statusColor};font-weight:600;">● ${status}</span></td>
                <td>${lastUpdated}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view" onclick="viewDriverDetails(${d.id})">👁️ View</button>
                </div></td>
            </tr>
        `;
    }).join('');

    renderPagination('driverPagination', total, currentPage, PAGE_SIZE, p => {
        currentPage = p;
        renderTable();
    });
}

function viewDriverDetails(driverId) {
    const driver = drivers.find(d => d.id === driverId);
    const invoices = getDriverInvoices(driverId);

    alert(`Driver: ${driver.firstName} ${driver.lastName}\nPhone: ${driver.phone}\nActive Jobs: ${invoices.length}`);
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadData();
});
</script>

</body>
</html>

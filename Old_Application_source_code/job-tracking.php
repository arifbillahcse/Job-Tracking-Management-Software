<?php
require 'includes/auth.php';
$pageTitle  = 'Job Tracking';
$activePage = 'job-tracking';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:25px;">📍 Job Tracking</h2>

        <div class="filter-bar">
            <input type="text" id="searchJob" placeholder="🔍 Search job #, customer..." oninput="filterJobs()">
            <input type="text" id="searchStatus" placeholder="🔍 Filter by status..." oninput="filterJobs()">
            <button class="btn-clear" onclick="clearJobSearch()">✕ Clear</button>
            <span class="filter-count" id="filterCount"></span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Job #</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>From → To</th>
                        <th>CF</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="jobTableBody">
                    <tr>
                        <td colspan="10" class="empty">Loading job data...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="jobPagination" class="pagination-wrap"></div>
    </div>

<?php include 'includes/footer.php'; ?>

<script>
let driverInvoices = [];
let companyInvoices = [];
let allJobs = [];
let currentPage = 1;
const PAGE_SIZE = 30;

async function loadData() {
    try {
        const [diData, ciData] = await Promise.all([
            api('inv-driver'),
            api('inv-company')
        ]);
        driverInvoices = diData;
        companyInvoices = ciData;
        buildJobsList();
        renderTable();
    } catch (e) {
        toast('Error loading data', 'error');
    }
}

function buildJobsList() {
    allJobs = [];

    // Add driver invoice jobs
    driverInvoices.forEach(inv => {
        (inv.lineItems || []).forEach(job => {
            allJobs.push({
                invoiceId: `DI-${inv.id}`,
                invoiceType: 'Driver',
                jobNumber: job.jobNumber,
                customerName: job.customerName,
                from: job.from,
                to: job.to,
                cubicFeet: job.cubicFeet,
                amount: (job.cubicFeet * job.rate).toFixed(2),
                rate: job.rate,
                date: inv.date,
                status: 'Active'
            });
        });
    });

    // Add company invoice jobs
    companyInvoices.forEach(inv => {
        (inv.lineItems || []).forEach(job => {
            allJobs.push({
                invoiceId: `CI-${inv.id}`,
                invoiceType: 'Company',
                jobNumber: job.jobNumber,
                customerName: job.customerName,
                from: job.from,
                to: job.to,
                cubicFeet: job.cubicFeet,
                amount: (job.cubicFeet * job.rate).toFixed(2),
                rate: job.rate,
                date: inv.date,
                status: 'Active'
            });
        });
    });
}

function filterJobs() {
    const searchJob = document.getElementById('searchJob').value.toLowerCase().trim();
    const searchStatus = document.getElementById('searchStatus').value.toLowerCase().trim();

    return allJobs.filter(job => {
        const jobMatch = `${job.jobNumber}${job.customerName}`.toLowerCase();
        if (searchJob && !jobMatch.includes(searchJob)) return false;

        const status = job.status.toLowerCase();
        if (searchStatus && !status.includes(searchStatus)) return false;

        return true;
    });
}

function clearJobSearch() {
    document.getElementById('searchJob').value = '';
    document.getElementById('searchStatus').value = '';
    renderTable();
}

function renderTable() {
    const tb = document.getElementById('jobTableBody');
    const filtered = filterJobs();
    const total = filtered.length;

    document.getElementById('filterCount').textContent =
        document.getElementById('searchJob').value || document.getElementById('searchStatus').value
        ? `${total} result${total !== 1 ? 's' : ''}` : '';

    if (!total) {
        tb.innerHTML = '<tr><td colspan="10" class="empty">No jobs found.</td></tr>';
        renderPagination('jobPagination', 0, 1, PAGE_SIZE, () => {});
        return;
    }

    currentPage = Math.min(currentPage, Math.max(1, Math.ceil(total / PAGE_SIZE)));
    const start = (currentPage - 1) * PAGE_SIZE;
    const pageData = filtered.slice(start, start + PAGE_SIZE);

    tb.innerHTML = pageData.map(job => {
        const statusColor = job.status === 'Active' ? '#4ade80' : '#94a3b8';
        const badge = job.invoiceType === 'Driver'
            ? `<span style="background:#1d4ed8;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;">DI</span>`
            : `<span style="background:#16a34a;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;">CI</span>`;

        return `
            <tr>
                <td><strong>${job.invoiceId}</strong></td>
                <td>${job.jobNumber}</td>
                <td>${badge}</td>
                <td>${job.customerName}</td>
                <td>${job.from} → ${job.to}</td>
                <td>${job.cubicFeet}</td>
                <td><strong>$${job.amount}</strong></td>
                <td><span style="color:${statusColor};font-weight:600;">● ${job.status}</span></td>
                <td>${formatDate(job.date)}</td>
                <td><div class="action-btns">
                    <button class="btn-xs btn-xs-view" onclick="alert('Job: ${job.jobNumber}\\nCustomer: ${job.customerName}\\nAmount: $${job.amount}')">👁️ View</button>
                </div></td>
            </tr>
        `;
    }).join('');

    renderPagination('jobPagination', total, currentPage, PAGE_SIZE, p => {
        currentPage = p;
        renderTable();
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadData();
});
</script>

</body>
</html>

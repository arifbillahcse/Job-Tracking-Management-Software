<?php
require 'includes/auth.php';
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
include 'includes/header.php';
?>

    <div class="content">
        <h2 style="margin-bottom:25px;">Dashboard Overview</h2>
        <div class="stats-grid">
            <div class="stat-card"        onclick="location.href='companies.php'"><div class="stat-value" id="statCompanies">0</div><div class="stat-label">Total Companies</div></div>
            <div class="stat-card green"  onclick="location.href='drivers.php'"  ><div class="stat-value" id="statDrivers">0</div><div class="stat-label">Total Drivers</div></div>
            <div class="stat-card purple"><div class="stat-value" id="statInvoices">0</div><div class="stat-label">Total Invoices</div></div>
        </div>
        <h3 style="margin-bottom:15px;">Recent Invoices</h3>
        <div id="recentActivity"></div>
    </div>

<?php include 'includes/footer.php'; ?>

<script>
function renderPage() {
    // Sort by date desc — IDs are from separate tables so must NOT be compared across CI/DI
    const allInvoices = [
        ...companyInvoices.map(i => ({ ...i, _type: 'CI' })),
        ...driverInvoices.map(i  => ({ ...i, _type: 'DI' })),
    ].sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);

    document.getElementById('statCompanies').textContent = companies.length;
    document.getElementById('statDrivers').textContent   = drivers.length;
    document.getElementById('statInvoices').textContent  = allInvoices.length;

    const el = document.getElementById('recentActivity');
    if (!allInvoices.length) {
        el.innerHTML = '<div class="empty"><div class="empty-icon">📄</div><p>No invoices yet — use Invoice / Company or Invoice / Driver to create one.</p></div>';
        return;
    }

    el.innerHTML = allInvoices.slice(0, 6).map(inv => {
        const jobs      = (inv.lineItems || []).length;
        const customers = [...new Set((inv.lineItems || []).map(j => j.customerName).filter(Boolean))].join(', ') || '—';
        let badge, name;
        if (inv._type === 'CI') {
            const co = companies.find(c => c.id === inv.companyId);
            badge = `<span style="background:#1d4ed8;color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:12px;margin-right:8px;">CI-${inv.id}</span>`;
            name  = co ? esc(co.name) : `Company #${inv.companyId}`;
        } else {
            const dr = drivers.find(d => d.id === inv.driverId);
            badge = `<span style="background:#16a34a;color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:12px;margin-right:8px;">DI-${inv.id}</span>`;
            name  = dr ? esc(dr.firstName + ' ' + dr.lastName) : `Driver #${inv.driverId}`;
        }
        return `
            <div class="activity-item">
                <div class="activity-title">${badge}${name}</div>
                <div class="activity-details">
                    ${jobs} job(s) &nbsp;|&nbsp; Customers: ${esc(customers)} &nbsp;|&nbsp; Total: <strong>$${(inv.total || 0).toFixed(2)}</strong> &nbsp;|&nbsp; Date: ${formatDate(inv.date)}
                </div>
            </div>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadFromDB();
    renderPage();
});
</script>
</body>
</html>

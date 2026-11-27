// ═══════════════════════════════════════════
// SHARED UTILITIES
// ═══════════════════════════════════════════

// Format date from YYYY-MM-DD to MM-DD-YYYY
function formatDate(dateStr) {
    if (!dateStr) return '';
    const parts = String(dateStr).split('-');
    if (parts.length === 3) return `${parts[1]}-${parts[2]}-${parts[0]}`;
    return dateStr;
}

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

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close any modal when clicking its backdrop
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// Print a formatted invoice HTML string via the dedicated #printArea
function triggerPrint(html) {
    const area = document.getElementById('printArea');
    if (!area) { window.print(); return; }
    area.innerHTML = html;
    window.print();
    setTimeout(() => { area.innerHTML = ''; }, 800);
}

// Download invoice as PDF using html2pdf.js (client-side, no server needed)
function downloadPdf(html, filename) {
    if (!html) { toast('Nothing to download.', 'error'); return; }

    if (typeof html2pdf === 'undefined') {
        toast('PDF library not loaded. Please check your connection.', 'error');
        return;
    }

    // The container MUST be appended to document.body so html2canvas can
    // render it. We position it off-screen so it is invisible to the user.
    // All invoice styles are embedded inline so the PDF is self-contained
    // and independent of the page's dark-theme CSS.
    const container = document.createElement('div');
    container.style.cssText = 'position:absolute;left:-9999px;top:0;width:1100px;background:#fff;color:#111;font-family:Arial,sans-serif;';
    container.innerHTML = `
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family:Arial,sans-serif; }
            .inv-view { background:#fff; color:#111; padding:28px; font-family:Arial,sans-serif; }
            .inv-view-hdr { text-align:center; border-bottom:3px solid #111; padding-bottom:14px; margin-bottom:16px; }
            .inv-view-hdr h2 { font-size:24px; text-transform:uppercase; letter-spacing:.04em; color:#111; }
            .inv-view-hdr p  { font-size:12px; color:#444; margin-top:3px; }
            .inv-meta { display:grid; grid-template-columns:1fr 1fr; gap:20px; font-size:13px; margin-bottom:18px; color:#111; }
            .inv-meta div { line-height:1.8; }
            .inv-table { width:100%; border-collapse:collapse; font-size:11.5px; margin-bottom:18px; }
            .inv-table th { background:#1e293b; color:#fff; padding:8px 7px; text-align:left; border:1px solid #555; white-space:nowrap; }
            .inv-table td { border:1px solid #bbb; padding:7px; vertical-align:top; color:#111; }
            .inv-table tbody tr:nth-child(even) { background:#f5f8ff; }
            .inv-total-row td { background:#e8edf5; font-weight:bold; border-top:2px solid #333; }
            .inv-summary { margin-left:auto; width:320px; border:1px solid #ccc; font-size:13px; }
            .inv-summary-row { display:flex; justify-content:space-between; padding:8px 12px; border-bottom:1px solid #ddd; color:#111; }
            .inv-summary-row:last-child { background:#1e293b; color:#fff; font-size:15px; font-weight:bold; border-bottom:none; }
        </style>
        ${html}`;
    document.body.appendChild(container);

    const opt = {
        margin:      [10, 10, 10, 10],
        filename:    (filename || 'invoice') + '.pdf',
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    html2pdf().set(opt).from(container).save()
        .then(() => document.body.removeChild(container))
        .catch(() => {
            document.body.removeChild(container);
            toast('PDF generation failed. Please try again.', 'error');
        });
}

// ── Searchable Select ─────────────────────────
// Usage: makeSearchableSelect(wrapEl, hiddenEl, [{value, label}], onChange)
// Returns { reset(), setValue(val) }
function makeSearchableSelect(wrapEl, hiddenEl, options, onChange) {
    let selVal   = '';
    let selLabel = options[0]?.label || '';

    wrapEl.innerHTML = `
        <div class="ss-face"></div>
        <div class="ss-dropdown">
            <input type="text" class="ss-search" placeholder="🔍 Search...">
            <div class="ss-list"></div>
        </div>`;

    const face   = wrapEl.querySelector('.ss-face');
    const search = wrapEl.querySelector('.ss-search');
    const list   = wrapEl.querySelector('.ss-list');

    function setFace() { face.textContent = selLabel; }
    setFace();

    function renderList(q) {
        const q2  = q.toLowerCase();
        const vis = q2 ? options.filter(o => o.label.toLowerCase().includes(q2)) : options;
        list.innerHTML = vis.length
            ? vis.map(o => `<div class="ss-opt${o.value == selVal ? ' ss-sel' : ''}" data-value="${o.value}">${o.label}</div>`).join('')
            : '<div class="ss-empty">No results</div>';
    }

    function open()  { wrapEl.classList.add('ss-open'); search.value = ''; renderList(''); search.focus(); }
    function close() { wrapEl.classList.remove('ss-open'); }

    face.addEventListener('click',   e => { e.stopPropagation(); wrapEl.classList.contains('ss-open') ? close() : open(); });
    search.addEventListener('input', () => renderList(search.value));
    search.addEventListener('click', e => e.stopPropagation());
    list.addEventListener('click', e => {
        const opt = e.target.closest('.ss-opt');
        if (!opt) return;
        selVal = opt.dataset.value; selLabel = opt.textContent;
        hiddenEl.value = selVal;
        setFace(); close(); onChange();
    });
    document.addEventListener('click', close);

    return {
        reset()     { selVal = ''; selLabel = options[0]?.label || ''; hiddenEl.value = ''; setFace(); close(); },
        setValue(v) { const o = options.find(x => x.value == v); if (o) { selVal = o.value; selLabel = o.label; hiddenEl.value = v; setFace(); } },
    };
}

// ── Pagination helper ─────────────────────────
// Renders pagination controls into containerId.
// Calls onChange(newPage) when a page button is clicked.
function renderPagination(containerId, total, current, pageSize, onChange) {
    const el = document.getElementById(containerId);
    if (!el) return;
    const totalPages = Math.ceil(total / pageSize);
    if (totalPages <= 1) { el.innerHTML = ''; return; }

    window._pgnCb = onChange;

    const start = (current - 1) * pageSize + 1;
    const end   = Math.min(current * pageSize, total);

    // Build page number list with ellipsis
    const pages = [];
    const delta = 2;
    const left  = Math.max(2, current - delta);
    const right = Math.min(totalPages - 1, current + delta);

    pages.push(1);
    if (left > 2) pages.push('…');
    for (let i = left; i <= right; i++) pages.push(i);
    if (right < totalPages - 1) pages.push('…');
    if (totalPages > 1) pages.push(totalPages);

    const pageBtn = p => p === '…'
        ? `<span class="pgn-dots">…</span>`
        : `<button class="pgn-btn${p === current ? ' pgn-active' : ''}" onclick="window._pgnCb(${p})">${p}</button>`;

    el.innerHTML = `
        <div class="pgn-info">Showing ${start}–${end} of ${total} entries</div>
        <div class="pgn-btns">
            <button class="pgn-btn" ${current === 1 ? 'disabled' : ''} onclick="window._pgnCb(${current - 1})">← Prev</button>
            ${pages.map(pageBtn).join('')}
            <button class="pgn-btn" ${current === totalPages ? 'disabled' : ''} onclick="window._pgnCb(${current + 1})">Next →</button>
        </div>`;
}

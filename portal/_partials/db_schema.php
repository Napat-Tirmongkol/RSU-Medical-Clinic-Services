<?php
// portal/_partials/db_schema.php — Database Schema Explorer (graph view)
// Loaded by portal/index.php — Cytoscape.js loaded via CDN below.
?>
<style>
.ds-page { padding: 4px 4px 80px; }
.ds-h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.ds-sub { font-size: 12px; color: #64748b; margin-bottom: 16px; }

.ds-toolbar { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px 14px 0 0; flex-wrap: wrap; }
.ds-toolbar input[type="search"] { font-size: 13px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; min-width: 220px; }
.ds-toolbar select { font-size: 13px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
.ds-toolbar .btn { padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; color: #475569; }
.ds-toolbar .btn:hover { background: #f1f5f9; }
.ds-toolbar .stats { margin-left: auto; font-size: 11px; font-weight: 700; color: #64748b; display: flex; gap: 12px; }
.ds-toolbar .stats span b { color: #0f172a; }

.ds-canvas-wrap { display: flex; height: calc(100vh - 220px); background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 14px 14px; overflow: hidden; }
.ds-canvas { flex: 1; min-width: 0; background: linear-gradient(180deg, #fafbfc, #f1f5f9); position: relative; }
.ds-side { width: 320px; border-left: 1px solid #e2e8f0; background: #fff; overflow-y: auto; padding: 16px; flex-shrink: 0; }
.ds-side.is-empty { display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 13px; font-weight: 700; text-align: center; padding: 20px; }

.ds-legend { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.ds-legend-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 9999px; font-size: 10px; font-weight: 800; cursor: pointer; transition: filter 0.15s; user-select: none; }
.ds-legend-chip:hover { filter: brightness(1.1); }
.ds-legend-chip.is-off { opacity: 0.35; }
.ds-legend-chip .dot { width: 10px; height: 10px; border-radius: 50%; }

.ds-table-card { background: #fff; }
.ds-table-card h3 { font-size: 15px; font-weight: 900; color: #0f172a; margin: 0 0 4px; word-break: break-all; }
.ds-table-card .meta { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 9999px; font-size: 10px; font-weight: 800; color: #fff; margin-bottom: 12px; }
.ds-section-title { font-size: 10px; font-weight: 900; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; margin: 14px 0 6px; padding-top: 10px; border-top: 1px solid #f1f5f9; }
.ds-section-title:first-of-type { padding-top: 0; border-top: 0; margin-top: 8px; }
.ds-cols-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.ds-cols-table th { text-align: left; padding: 5px 6px; font-weight: 800; color: #64748b; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; font-size: 9px; letter-spacing: 0.05em; }
.ds-cols-table td { padding: 5px 6px; border-bottom: 1px solid #f8fafc; vertical-align: top; word-break: break-all; }
.ds-cols-table tr:hover td { background: #fafbfc; }
.ds-cols-table .col-name { font-weight: 700; color: #0f172a; }
.ds-cols-table .col-type { font-family: ui-monospace, Menlo, monospace; color: #7c3aed; font-size: 10px; }
.ds-cols-table .col-key  { font-size: 9px; font-weight: 900; padding: 1px 5px; border-radius: 4px; }
.ds-cols-table .col-key-pri { background: #dcfce7; color: #15803d; }
.ds-cols-table .col-key-uni { background: #dbeafe; color: #1e40af; }
.ds-cols-table .col-key-mul { background: #fef3c7; color: #b45309; }

.ds-rel-item { display: flex; align-items: center; gap: 6px; padding: 5px 0; font-size: 11px; cursor: pointer; }
.ds-rel-item:hover .ds-rel-target { text-decoration: underline; color: #4f46e5; }
.ds-rel-item .ds-rel-col { font-family: ui-monospace, Menlo, monospace; color: #475569; font-size: 10px; }
.ds-rel-item .ds-rel-arrow { color: #94a3b8; }
.ds-rel-item .ds-rel-target { font-weight: 800; color: #0f172a; word-break: break-all; }

body[data-theme='dark'] .ds-toolbar,
body[data-theme='dark'] .ds-canvas-wrap,
body[data-theme='dark'] .ds-side,
body[data-theme='dark'] .ds-table-card { background: #1e293b; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .ds-canvas { background: linear-gradient(180deg, #0f172a, #1e293b); }
body[data-theme='dark'] .ds-toolbar input, body[data-theme='dark'] .ds-toolbar select, body[data-theme='dark'] .ds-toolbar .btn { background: #0f172a; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .ds-cols-table th { color: #cbd5e1; border-color: #334155; }
body[data-theme='dark'] .ds-cols-table td { border-color: #334155; }
body[data-theme='dark'] .ds-cols-table .col-name { color: #f1f5f9; }
body[data-theme='dark'] .ds-table-card h3 { color: #f1f5f9; }
</style>

<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.min.js"></script>

<div class="ds-page">
    <h1 class="ds-h1"><i class="fa-solid fa-diagram-project" style="color:#0891b2"></i> Database Schema Explorer</h1>
    <p class="ds-sub">มุมมองความสัมพันธ์ระหว่างตาราง — เส้นทึบ = Foreign Key จริง · เส้นประ = ความสัมพันธ์ที่อนุมานจากชื่อคอลัมน์ (อาจมี false positive)</p>

    <div class="ds-toolbar">
        <input type="search" id="ds-q" placeholder="ค้นหาตาราง…">
        <select id="ds-layout">
            <option value="cose">Force-directed (cose)</option>
            <option value="concentric">Concentric (by domain)</option>
            <option value="grid">Grid</option>
            <option value="circle">Circle</option>
            <option value="breadthfirst">Breadthfirst</option>
        </select>
        <button type="button" class="btn" id="ds-fit"><i class="fa-solid fa-expand"></i> Fit</button>
        <button type="button" class="btn" id="ds-toggle-heu"><i class="fa-solid fa-eye"></i> ซ่อน heuristic</button>
        <div class="stats" id="ds-stats">
            <span>กำลังโหลด…</span>
        </div>
    </div>

    <div class="ds-canvas-wrap">
        <div class="ds-canvas" id="ds-cy"></div>
        <div class="ds-side is-empty" id="ds-side">
            <div>คลิกที่ตารางในกราฟเพื่อดูรายละเอียด<br>คอลัมน์ + ความสัมพันธ์ + จำนวนแถว</div>
        </div>
    </div>
</div>

<script>
(function() {
    const AJAX = 'ajax_db_schema.php';
    let cy = null;
    let allData = null;       // raw graph JSON
    let showHeuristic = true;
    let activeDomains = null; // null = all on; Set when filtered

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function buildElements(data) {
        const elements = [];
        for (const n of data.nodes) {
            if (activeDomains && !activeDomains.has(n.domain)) continue;
            elements.push({
                data: { id: n.id, label: n.label, domain: n.domain, color: n.color, rows: n.rows },
                classes: 'node node-' + n.domain,
            });
        }
        // Build a set of node ids that survived the domain filter so we don't
        // dangle edges whose endpoints were filtered out
        const present = new Set(elements.map(e => e.data.id));
        for (const e of data.edges) {
            if (!showHeuristic && e.type === 'heuristic') continue;
            if (!present.has(e.source) || !present.has(e.target)) continue;
            elements.push({
                data: { source: e.source, target: e.target, label: e.label, type: e.type },
                classes: 'edge edge-' + e.type,
            });
        }
        return elements;
    }

    function renderLegend(legend) {
        const el = document.getElementById('ds-stats');
        const stats = `<span>ตาราง <b>${allData.stats.tables.toLocaleString('th-TH')}</b></span>
                       <span>FK <b>${allData.stats.fk.toLocaleString('th-TH')}</b></span>
                       <span>Heuristic <b>${allData.stats.heuristic.toLocaleString('th-TH')}</b></span>`;
        el.innerHTML = stats;
        // Insert legend chips before the stats span — toolbar wraps gracefully
        const toolbar = document.querySelector('.ds-toolbar');
        // Remove any previous legend nodes from a re-render
        toolbar.querySelectorAll('.ds-legend-chip').forEach(n => n.remove());
        const frag = document.createDocumentFragment();
        for (const lg of legend) {
            const chip = document.createElement('span');
            chip.className = 'ds-legend-chip';
            chip.style.background = lg.color + '22';
            chip.style.color = lg.color;
            chip.dataset.domain = lg.domain;
            chip.innerHTML = `<span class="dot" style="background:${lg.color}"></span>${esc(lg.label)} (${lg.count})`;
            chip.addEventListener('click', () => toggleDomain(lg.domain, chip));
            frag.appendChild(chip);
        }
        toolbar.insertBefore(frag, document.getElementById('ds-stats'));
    }

    function toggleDomain(domain, chip) {
        if (activeDomains === null) {
            // First click → start filtering to "all except this one"
            activeDomains = new Set(allData.legend.map(lg => lg.domain));
        }
        if (activeDomains.has(domain)) {
            activeDomains.delete(domain);
            chip.classList.add('is-off');
        } else {
            activeDomains.add(domain);
            chip.classList.remove('is-off');
        }
        // If user re-enabled everything, drop the filter entirely so legend
        // chips return to a uniform "all on" state
        if (activeDomains.size === allData.legend.length) activeDomains = null;
        renderGraph();
    }

    function renderGraph() {
        const layout = document.getElementById('ds-layout').value || 'cose';
        if (cy) cy.destroy();
        cy = cytoscape({
            container: document.getElementById('ds-cy'),
            elements: buildElements(allData),
            style: [
                {
                    selector: 'node',
                    style: {
                        'background-color': 'data(color)',
                        'label': 'data(label)',
                        'color': '#0f172a',
                        'font-size': 10,
                        'font-weight': 700,
                        'text-valign': 'bottom',
                        'text-margin-y': 5,
                        'text-outline-width': 2,
                        'text-outline-color': '#fff',
                        'width': 'mapData(rows, 0, 10000, 20, 60)',
                        'height': 'mapData(rows, 0, 10000, 20, 60)',
                        'border-width': 2,
                        'border-color': '#fff',
                        'transition-property': 'background-color, border-color, border-width',
                        'transition-duration': '0.18s',
                    },
                },
                {
                    selector: 'node:selected',
                    style: { 'border-color': '#0f172a', 'border-width': 3 },
                },
                {
                    selector: 'node.faded',
                    style: { 'opacity': 0.12 },
                },
                {
                    selector: 'edge',
                    style: {
                        'width': 1.5,
                        'line-color': '#94a3b8',
                        'curve-style': 'bezier',
                        'target-arrow-color': '#94a3b8',
                        'target-arrow-shape': 'triangle',
                        'arrow-scale': 0.7,
                        'opacity': 0.55,
                    },
                },
                {
                    selector: 'edge.edge-fk',
                    style: { 'line-color': '#0f172a', 'target-arrow-color': '#0f172a', 'opacity': 0.7, 'width': 2 },
                },
                {
                    selector: 'edge.edge-heuristic',
                    style: { 'line-style': 'dashed', 'line-color': '#cbd5e1', 'target-arrow-color': '#cbd5e1' },
                },
                {
                    selector: 'edge.highlighted',
                    style: { 'line-color': '#4f46e5', 'target-arrow-color': '#4f46e5', 'opacity': 1, 'width': 3 },
                },
                {
                    selector: 'edge.faded',
                    style: { 'opacity': 0.08 },
                },
            ],
            layout: layoutOptions(layout),
            // Default wheel sensitivity — Cytoscape warns against custom values
            // because they translate inconsistently across mice/trackpads/OSes
            minZoom: 0.15,
            maxZoom: 3,
        });

        cy.on('tap', 'node', evt => loadTable(evt.target.id()));
        cy.on('mouseover', 'node', evt => {
            const n = evt.target;
            const neighborhood = n.closedNeighborhood();
            cy.elements().addClass('faded');
            neighborhood.removeClass('faded');
            neighborhood.connectedEdges().filter(e => e.source().id() === n.id() || e.target().id() === n.id()).addClass('highlighted');
        });
        cy.on('mouseout', 'node', () => {
            cy.elements().removeClass('faded highlighted');
        });
    }

    function layoutOptions(name) {
        switch (name) {
            case 'concentric':
                return {
                    name: 'concentric',
                    concentric: n => n.data('rows') || 1,
                    levelWidth: () => 1,
                    minNodeSpacing: 30,
                    animate: true,
                };
            case 'grid':         return { name: 'grid', padding: 30, animate: true };
            case 'circle':       return { name: 'circle', padding: 30, animate: true };
            case 'breadthfirst': return { name: 'breadthfirst', padding: 30, animate: true, spacingFactor: 1.2 };
            case 'cose':
            default:
                return {
                    name: 'cose',
                    nodeRepulsion: 8000,
                    idealEdgeLength: 80,
                    edgeElasticity: 100,
                    gravity: 0.25,
                    numIter: 1500,
                    animate: false,
                    randomize: true,
                };
        }
    }

    async function loadTable(name) {
        const side = document.getElementById('ds-side');
        side.classList.remove('is-empty');
        side.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</div>';
        try {
            const res = await fetch(AJAX + '?action=table&name=' + encodeURIComponent(name), { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || 'load failed');

            const keyBadge = (k) => {
                if (k === 'PRI') return '<span class="col-key col-key-pri">PRI</span>';
                if (k === 'UNI') return '<span class="col-key col-key-uni">UNI</span>';
                if (k === 'MUL') return '<span class="col-key col-key-mul">MUL</span>';
                return '';
            };
            const colsHtml = json.columns.map(c => `
                <tr>
                    <td class="col-name">${esc(c.name)} ${keyBadge(c.kkey)}</td>
                    <td class="col-type">${esc(c.type)}</td>
                    <td>${c.nullable === 'YES' ? '<span style="color:#94a3b8">null</span>' : '<b style="color:#15803d">●</b>'}</td>
                </tr>`).join('');

            const outHtml = json.out_fks.length
                ? json.out_fks.map(r => `<div class="ds-rel-item" onclick="dsJump('${esc(r.tgt)}')">
                    <span class="ds-rel-col">${esc(r.src)}</span>
                    <span class="ds-rel-arrow">→</span>
                    <span class="ds-rel-target">${esc(r.tgt)}</span>
                    </div>`).join('')
                : '<div style="font-size:11px;color:#94a3b8">— ไม่มี FK ออก —</div>';

            const inHtml = json.in_fks.length
                ? json.in_fks.map(r => `<div class="ds-rel-item" onclick="dsJump('${esc(r.src_table)}')">
                    <span class="ds-rel-target">${esc(r.src_table)}</span>
                    <span class="ds-rel-arrow">←</span>
                    <span class="ds-rel-col">${esc(r.src_col)}</span>
                    </div>`).join('')
                : '<div style="font-size:11px;color:#94a3b8">— ไม่มีตารางอ้างถึง —</div>';

            side.innerHTML = `<div class="ds-table-card">
                <h3>${esc(json.name)}</h3>
                <span class="meta" style="background:${esc(json.color)}"><i class="fa-solid fa-folder-tree"></i> ${esc(json.domain_label)}</span>
                <span class="meta" style="background:#475569"><i class="fa-solid fa-database"></i> ${json.row_count.toLocaleString('th-TH')} แถว</span>
                <div class="ds-section-title">คอลัมน์ (${json.columns.length})</div>
                <table class="ds-cols-table">
                    <thead><tr><th>ชื่อ</th><th>Type</th><th title="NOT NULL">NN</th></tr></thead>
                    <tbody>${colsHtml}</tbody>
                </table>
                <div class="ds-section-title">FK ออก (declared)</div>
                ${outHtml}
                <div class="ds-section-title">ตารางที่อ้างถึง (declared)</div>
                ${inHtml}
            </div>`;
        } catch (err) {
            side.innerHTML = `<div style="text-align:center;padding:40px;color:#b91c1c">ERROR: ${esc(err.message)}</div>`;
        }
    }

    // Exposed for inline handlers in the side-panel relation links
    window.dsJump = function(name) {
        if (!cy) return;
        const node = cy.getElementById(name);
        if (node.empty()) return;
        cy.animate({ center: { eles: node }, zoom: 1.5, duration: 350 });
        node.select();
        loadTable(name);
    };

    document.getElementById('ds-q').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        if (!cy) return;
        if (!q) { cy.elements().removeClass('faded'); return; }
        cy.elements().addClass('faded');
        cy.nodes().filter(n => n.id().toLowerCase().includes(q)).removeClass('faded');
    });
    document.getElementById('ds-layout').addEventListener('change', renderGraph);
    document.getElementById('ds-fit').addEventListener('click', () => cy && cy.fit(null, 30));
    document.getElementById('ds-toggle-heu').addEventListener('click', e => {
        showHeuristic = !showHeuristic;
        e.currentTarget.innerHTML = showHeuristic
            ? '<i class="fa-solid fa-eye"></i> ซ่อน heuristic'
            : '<i class="fa-solid fa-eye-slash"></i> แสดง heuristic';
        renderGraph();
    });

    async function boot() {
        try {
            const res = await fetch(AJAX + '?action=graph', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            allData = json;
            renderLegend(json.legend);
            renderGraph();
        } catch (err) {
            document.getElementById('ds-stats').innerHTML = `<span style="color:#b91c1c">ERROR: ${esc(err.message)}</span>`;
        }
    }
    boot();
})();
</script>

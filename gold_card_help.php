<?php
// gold_card_help.php — แสดง GOLD_CARD_GUIDE.md เป็นหน้า in-app help
// Render markdown โดย marked.js (CDN) — ไม่ expose .md file ผ่าน URL โดยตรง
declare(strict_types=1);

$mdPath = __DIR__ . '/GOLD_CARD_GUIDE.md';
$md = is_readable($mdPath) ? file_get_contents($mdPath) : '';
if ($md === false) $md = '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คู่มือใช้งานระบบบัตรทอง</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Sarabun', system-ui, -apple-system, sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #fffbeb 100%);
            color: #0f172a;
            line-height: 1.7;
            min-height: 100vh;
        }
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: #fff;
            border-bottom: 1.5px solid #e2e8f0;
            padding: 14px 20px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .topbar h1 {
            margin: 0; font-size: 16px; font-weight: 900; color: #92400e;
            display: flex; align-items: center; gap: 8px;
        }
        .topbar .actions { display: flex; gap: 8px; }
        .topbar a, .topbar button {
            background: #fffbeb; color: #92400e;
            border: 1.5px solid #fde68a; border-radius: 10px;
            padding: 6px 12px; font-size: 12px; font-weight: 800;
            cursor: pointer; text-decoration: none;
            transition: all .15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .topbar a:hover, .topbar button:hover {
            background: #f59e0b; color: #fff; border-color: #f59e0b;
        }

        .layout {
            max-width: 1100px; margin: 0 auto;
            padding: 24px 20px 60px;
            display: grid; grid-template-columns: 1fr; gap: 24px;
        }
        @media (min-width: 1024px) {
            .layout { grid-template-columns: 240px 1fr; }
        }

        /* TOC sidebar */
        .toc {
            background: #fff; border: 1.5px solid #e2e8f0;
            border-radius: 16px; padding: 18px;
            font-size: 13px;
        }
        @media (min-width: 1024px) {
            .toc { position: sticky; top: 78px; max-height: calc(100vh - 100px); overflow-y: auto; }
        }
        .toc h3 {
            margin: 0 0 8px; font-size: 11px; font-weight: 900;
            color: #94a3b8; text-transform: uppercase; letter-spacing: .08em;
        }
        .toc a {
            display: block; padding: 6px 10px; margin: 2px 0;
            border-radius: 8px; color: #334155;
            text-decoration: none; font-weight: 600;
            transition: all .12s;
        }
        .toc a:hover { background: #fffbeb; color: #92400e; }
        .toc a.active { background: #f59e0b; color: #fff; }
        .toc .toc-h3 { padding-left: 22px; font-size: 12px; font-weight: 500; }

        /* Content */
        .content {
            background: #fff; border: 1.5px solid #e2e8f0;
            border-radius: 20px; padding: 32px 36px;
            box-shadow: 0 4px 24px rgba(0,0,0,.04);
        }
        @media (max-width: 640px) {
            .content { padding: 20px 18px; border-radius: 16px; }
        }

        .content h1 {
            font-size: 26px; font-weight: 900; color: #92400e;
            margin: 0 0 12px; line-height: 1.3;
            padding-bottom: 12px; border-bottom: 3px solid #f59e0b;
        }
        .content h2 {
            font-size: 20px; font-weight: 900; color: #0f172a;
            margin: 32px 0 12px; padding-left: 12px;
            border-left: 4px solid #f59e0b;
        }
        .content h3 {
            font-size: 16px; font-weight: 800; color: #1e293b;
            margin: 24px 0 8px;
        }
        .content p { margin: 8px 0; color: #334155; }
        .content blockquote {
            margin: 12px 0; padding: 12px 16px;
            background: #fffbeb; border-left: 4px solid #f59e0b;
            border-radius: 0 12px 12px 0;
            color: #92400e; font-style: italic;
        }
        .content code {
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            background: #f1f5f9; padding: 2px 6px;
            border-radius: 5px; font-size: 0.88em;
            color: #be185d;
        }
        .content pre {
            background: #1e293b; color: #e2e8f0;
            padding: 16px 20px; border-radius: 12px;
            overflow-x: auto; font-size: 13px;
            line-height: 1.5;
        }
        .content pre code { background: transparent; color: inherit; padding: 0; }
        .content table {
            width: 100%; border-collapse: collapse;
            margin: 12px 0; font-size: 14px;
            background: #fff;
        }
        .content table th {
            background: #fffbeb; color: #92400e;
            font-weight: 900; text-align: left;
            padding: 10px 12px;
            border: 1.5px solid #fde68a;
        }
        .content table td {
            padding: 10px 12px;
            border: 1.5px solid #e2e8f0;
            color: #334155; vertical-align: top;
        }
        .content table tr:nth-child(even) td { background: #f8fafc; }
        .content ul, .content ol { padding-left: 24px; }
        .content li { margin: 4px 0; }
        .content a { color: #0891b2; text-decoration: underline; }
        .content hr {
            border: 0; height: 1px;
            background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
            margin: 32px 0;
        }
        .content em { color: #475569; font-style: italic; font-size: 0.92em; }

        /* Anchor scroll offset for sticky topbar */
        .content :is(h1, h2, h3) { scroll-margin-top: 80px; }

        @media print {
            .topbar, .toc { display: none; }
            .layout { display: block; padding: 0; }
            .content { box-shadow: none; border: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <h1><i class="fa-solid fa-id-card" style="color:#f59e0b"></i>คู่มือบัตรทอง</h1>
        <div class="actions">
            <button onclick="window.print()" title="พิมพ์ / Save as PDF"><i class="fa-solid fa-print"></i>พิมพ์</button>
            <a href="javascript:history.length>1?history.back():window.close()"><i class="fa-solid fa-arrow-left"></i>กลับ</a>
        </div>
    </header>

    <main class="layout">
        <aside class="toc" id="toc">
            <h3><i class="fa-solid fa-list mr-1"></i>สารบัญ</h3>
            <div id="toc-list"></div>
        </aside>

        <article id="content" class="content">
            <p style="text-align:center;color:#94a3b8;padding:60px 0">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:24px"></i><br>
                กำลังโหลดเอกสาร…
            </p>
        </article>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
    <script>
        const MD_CONTENT = <?= json_encode($md, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        (function() {
            if (!MD_CONTENT) {
                document.getElementById('content').innerHTML =
                    '<p style="text-align:center;color:#dc2626;padding:60px 0">' +
                    '<i class="fa-solid fa-triangle-exclamation"></i> ไม่พบไฟล์คู่มือ (GOLD_CARD_GUIDE.md)</p>';
                return;
            }

            marked.setOptions({ gfm: true, breaks: false, headerIds: true });

            // slugify Thai/English for stable anchors
            function slugify(s) {
                return s.toLowerCase()
                    .replace(/[^\w฀-๿\s-]/g, '')
                    .trim().replace(/\s+/g, '-')
                    .substring(0, 80);
            }

            // Custom renderer to add IDs we can predict for TOC
            const renderer = new marked.Renderer();
            renderer.heading = (text, level, raw) => {
                const id = slugify(raw);
                return `<h${level} id="${id}">${text}</h${level}>`;
            };

            const html = marked.parse(MD_CONTENT, { renderer });
            document.getElementById('content').innerHTML = html;

            // Build TOC from h2/h3
            const headings = document.querySelectorAll('#content h2, #content h3');
            const tocList = document.getElementById('toc-list');
            headings.forEach(h => {
                const a = document.createElement('a');
                a.href = '#' + h.id;
                a.textContent = h.textContent;
                if (h.tagName === 'H3') a.classList.add('toc-h3');
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    document.getElementById(h.id).scrollIntoView({ behavior: 'smooth', block: 'start' });
                    history.replaceState(null, '', '#' + h.id);
                });
                tocList.appendChild(a);
            });

            // Highlight TOC on scroll (IntersectionObserver)
            if ('IntersectionObserver' in window) {
                const links = Array.from(tocList.querySelectorAll('a'));
                const linkMap = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
                const obs = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            links.forEach(a => a.classList.remove('active'));
                            const link = linkMap.get(entry.target.id);
                            if (link) link.classList.add('active');
                        }
                    });
                }, { rootMargin: '-80px 0px -70% 0px' });
                headings.forEach(h => obs.observe(h));
            }

            // Scroll to hash on load
            if (location.hash) {
                const el = document.getElementById(location.hash.slice(1));
                if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth' }), 100);
            }
        })();
    </script>
</body>
</html>

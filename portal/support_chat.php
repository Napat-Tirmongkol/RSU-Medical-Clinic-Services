<?php
// portal/support_chat.php — Premium Staff Support Chat Center
declare(strict_types=1);
// NOTE: session_start() is handled by auth.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Chat - Central HUB</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        @font-face {
            font-family: 'RSU';
            src: url('../assets/fonts/RSU_Regular.ttf') format('truetype');
            font-weight: normal;
        }
        @font-face {
            font-family: 'RSU';
            src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype');
            font-weight: bold;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #F0F4FF;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ── Page Layout ── */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 28px 32px;
            gap: 24px;
        }

        /* ── Header ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .page-header h1 {
            font-size: 32px;
            font-weight: 900;
            color: #0F172A;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .page-header .subtitle {
            font-size: 11px;
            font-weight: 700;
            color: #94A3B8;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-top: 4px;
        }
        .btn-back {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: #fff;
            border: 1.5px solid #E2E8F0;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 900;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-back:hover { border-color: #BFDBFE; color: #2563EB; box-shadow: 0 8px 24px rgba(37,99,235,0.08); }

        /* ── Chat Main Container ── */
        .chat-app {
            display: flex;
            flex: 1;
            min-height: 0;
            background: #ffffff;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 20px 60px -10px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(226,232,240,0.8);
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 360px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #F1F5F9;
            background: #FAFBFF;
        }
        .sidebar-header {
            padding: 24px 24px 20px;
            border-bottom: 1px solid #F1F5F9;
        }
        .sidebar-title {
            font-size: 13px;
            font-weight: 900;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 16px;
        }
        .search-wrap {
            position: relative;
        }
        .search-wrap i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #CBD5E1;
            font-size: 13px;
            pointer-events: none;
        }
        .search-wrap input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            background: #F1F5F9;
            border: 1.5px solid transparent;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #334155;
            outline: none;
            transition: all 0.2s;
        }
        .search-wrap input:focus {
            background: #fff;
            border-color: #BFDBFE;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.06);
        }
        .search-wrap input::placeholder { color: #CBD5E1; }

        .user-list {
            flex: 1;
            overflow-y: auto;
        }
        .user-list::-webkit-scrollbar { width: 4px; }
        .user-list::-webkit-scrollbar-thumb { background: #E2E8F0; border-radius: 10px; }

        .user-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 24px;
            cursor: pointer;
            border-bottom: 1px solid #F8FAFC;
            transition: background 0.15s;
        }
        .user-item:hover { background: #F8FAFF; }
        .user-item.active { background: #EFF6FF; border-right: 3px solid #2563EB; }

        .user-item img {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            object-fit: cover;
            flex-shrink: 0;
            border: 2px solid #E2E8F0;
        }
        .user-item-info { flex: 1; min-width: 0; }
        .user-item-name {
            font-size: 14px;
            font-weight: 800;
            color: #1E293B;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 3px;
        }
        .user-item-preview {
            font-size: 12px;
            font-weight: 500;
            color: #94A3B8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-item-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }
        .user-item-time {
            font-size: 10px;
            font-weight: 700;
            color: #CBD5E1;
            text-transform: uppercase;
        }
        .unread-badge {
            background: #EF4444;
            color: #fff;
            font-size: 10px;
            font-weight: 900;
            padding: 2px 7px;
            border-radius: 99px;
        }
        .loading-state {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
        }
        .spinner {
            width: 36px;
            height: 36px;
            border: 4px solid #DBEAFE;
            border-top-color: #2563EB;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Chat Window ── */
        .chat-window {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Placeholder */
        .chat-placeholder {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #FAFBFF;
            text-align: center;
            padding: 40px;
        }
        .placeholder-icon {
            width: 88px;
            height: 88px;
            background: #fff;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 20px 40px rgba(37,99,235,0.12), 0 0 0 1px rgba(219,234,254,0.8);
            position: relative;
        }
        .placeholder-icon i { font-size: 36px; color: #3B82F6; }
        .placeholder-dot {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 22px;
            height: 22px;
            background: #10B981;
            border-radius: 50%;
            border: 4px solid #FAFBFF;
            animation: blink 2s infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.4} }
        .placeholder-icon h3 { font-size: 22px; font-weight: 900; color: #1E293B; margin-bottom: 8px; }
        .placeholder-icon p { font-size: 14px; font-weight: 600; color: #94A3B8; line-height: 1.7; }

        /* Active chat */
        .chat-active {
            flex: 1;
            display: none;
            flex-direction: column;
        }
        .chat-active.visible { display: flex; }

        .chat-header {
            padding: 20px 32px;
            border-bottom: 1px solid #F1F5F9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            flex-shrink: 0;
        }
        .chat-header-user {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .chat-header-img-wrap {
            position: relative;
        }
        .chat-header-img-wrap img {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            object-fit: cover;
            border: 2px solid #E2E8F0;
        }
        .online-dot {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 16px;
            height: 16px;
            background: #10B981;
            border-radius: 50%;
            border: 3px solid #fff;
            box-shadow: 0 0 0 0 rgba(16,185,129,0.4);
            animation: online-pulse 2s infinite;
        }
        @keyframes online-pulse {
            0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.5); }
            70% { box-shadow: 0 0 0 8px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .chat-header-name {
            font-size: 16px;
            font-weight: 900;
            color: #1E293B;
            margin-bottom: 4px;
        }
        .chat-header-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 900;
            color: #10B981;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }
        .chat-header-actions {
            display: flex;
            gap: 10px;
        }
        .icon-btn {
            width: 40px;
            height: 40px;
            background: #F8FAFC;
            border: 1px solid #F1F5F9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94A3B8;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }
        .icon-btn:hover { background: #EFF6FF; color: #2563EB; border-color: #BFDBFE; }

        /* Messages */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
            background: #F8FAFF;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .messages-container::-webkit-scrollbar { width: 4px; }
        .messages-container::-webkit-scrollbar-thumb { background: #E2E8F0; border-radius: 10px; }

        .msg-bubble {
            max-width: 72%;
            padding: 14px 20px;
            border-radius: 24px;
            font-size: 14px;
            line-height: 1.6;
            font-weight: 600;
        }
        .msg-bubble p { margin: 0; }
        .msg-bubble .msg-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 6px;
            opacity: 0.55;
        }
        .msg-bubble .msg-meta span { font-size: 10px; font-weight: 700; text-transform: uppercase; }

        .msg-user {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            align-self: flex-start;
            border-bottom-left-radius: 6px;
            color: #334155;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }
        .msg-staff {
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            color: #FFFFFF;
            align-self: flex-end;
            border-bottom-right-radius: 6px;
            box-shadow: 0 8px 24px rgba(37,99,235,0.25);
        }

        /* Input */
        .chat-input-bar {
            padding: 20px 28px;
            background: #fff;
            border-top: 1px solid #F1F5F9;
            flex-shrink: 0;
        }
        .chat-form {
            display: flex;
            gap: 14px;
            align-items: flex-end;
        }
        .chat-form textarea {
            flex: 1;
            min-height: 56px;
            max-height: 200px;
            background: #F8FAFC;
            border: 1.5px solid #E2E8F0;
            border-radius: 20px;
            padding: 16px 24px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            color: #334155;
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
            resize: none;
            line-height: 1.5;
            overflow-y: auto;
        }
        .chat-form textarea:focus {
            background: #fff;
            border-color: #93C5FD;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.08);
        }
        .chat-form textarea::placeholder { color: #CBD5E1; }
        .send-btn {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            border: none;
            border-radius: 18px;
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(37,99,235,0.35);
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .send-btn:hover { transform: scale(1.05); box-shadow: 0 12px 32px rgba(37,99,235,0.4); }
        .send-btn:active { transform: scale(0.95); }

        /* Quick reply chips above the input */
        .quick-replies {
            display: flex; flex-wrap: wrap; gap: 6px;
            padding: 10px 28px 0; background: #fff;
        }
        .quick-replies-label {
            font-size: 9px; font-weight: 900; color: #94A3B8;
            text-transform: uppercase; letter-spacing: .14em;
            display: inline-flex; align-items: center; gap: 4px;
            margin-right: 4px;
        }
        .qr-chip {
            padding: 5px 11px;
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s, color .15s, border-color .15s, transform .1s;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .qr-chip:hover {
            background: #DBEAFE;
            color: #1D4ED8;
            border-color: #BFDBFE;
        }
        .qr-chip:active { transform: scale(.96); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_partials/header.php'; ?>

    <div class="page-wrapper" style="height: calc(100vh - 60px); padding: 20px 32px;">
        <!-- Header -->
        <div class="page-header" style="margin-bottom: 20px;">
            <div>
                <h1 style="font-size: 24px;">Live Support Chat</h1>
                <p class="subtitle">Real-time Patient Support Center</p>
            </div>
            <a href="index.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>กลับหน้าหลัก</span>
            </a>
        </div>

        <!-- Chat App -->
        <div class="chat-app">
            <!-- Sidebar -->
            <div class="sidebar">
                <div class="sidebar-header">
                    <p class="sidebar-title">การสนทนา</p>
                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="search-input" placeholder="ค้นหาการสนทนา..." oninput="filterUsers(this.value)">
                    </div>
                </div>
                <div class="user-list" id="user-list-container">
                    <div class="loading-state"><div class="spinner"></div></div>
                </div>
            </div>

            <!-- Chat Window -->
            <div class="chat-window">
                <!-- Placeholder -->
                <div class="chat-placeholder" id="chat-placeholder">
                    <div class="placeholder-icon">
                        <i class="fa-solid fa-comments"></i>
                        <div class="placeholder-dot"></div>
                    </div>
                    <h3 style="font-size:22px;font-weight:900;color:#1E293B;margin-bottom:8px">เริ่มการสนทนา</h3>
                    <p style="font-size:14px;font-weight:600;color:#94A3B8;line-height:1.7">เลือกรายชื่อผู้ใช้งานทางด้านซ้าย<br>เพื่อตรวจสอบข้อความและตอบกลับ</p>
                </div>

                <!-- Active Chat -->
                <div class="chat-active" id="chat-active">
                    <!-- Header -->
                    <div class="chat-header">
                        <div class="chat-header-user">
                            <div class="chat-header-img-wrap">
                                <img id="active-user-img" src="" alt="user">
                                <span class="online-dot"></span>
                            </div>
                            <div>
                                <div class="chat-header-name" id="active-user-name">...</div>
                                <div class="chat-header-status">
                                    <span style="width:7px;height:7px;background:#10B981;border-radius:50%;display:inline-block"></span>
                                    Online Now
                                </div>
                            </div>
                        </div>
                        <div class="chat-header-actions">
                            <button class="icon-btn" title="โทร"><i class="fa-solid fa-phone"></i></button>
                            <button class="icon-btn" title="เพิ่มเติม"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="messages-container" id="messages-container"></div>

                    <!-- Quick reply chips -->
                    <div class="quick-replies" id="quick-replies">
                        <span class="quick-replies-label"><i class="fa-solid fa-bolt"></i> ตอบด่วน</span>
                    </div>

                    <!-- Input -->
                    <div class="chat-input-bar">
                        <form class="chat-form" id="chat-form" onsubmit="handleStaffSubmit(event)">
                            <textarea id="chat-input" rows="1" placeholder="พิมพ์ข้อความตอบกลับ... (Enter ส่ง · Shift+Enter ขึ้นบรรทัดใหม่)"></textarea>
                            <button type="submit" class="send-btn" title="ส่งข้อความ (Enter)">
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const portal_CSRF = <?= json_encode(get_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
        let currentUserId = null;
        let allUsers = [];
        // Cursor (highest message id already rendered) per conversation — drives since_id polling
        const lastMsgIdByUser = Object.create(null);
        // Per-conversation drafts so switching tabs doesn't lose unsent text
        const drafts = Object.create(null);
        // Sidebar pagination state
        let currentPage = 1, totalPages = 1, currentSearch = '';
        let searchDebounceTimer = null;
        // Title flash bookkeeping
        let prevTotalUnread = 0;
        const BASE_TITLE = 'Support Chat - Central HUB';

        // Quick-reply templates (chips). Edit list to taste — admin UI can be added later.
        const QUICK_REPLIES = [
            'สวัสดีครับ มีอะไรให้เราช่วยไหมครับ',
            'ขอบคุณที่ติดต่อเข้ามา จะรีบดำเนินการให้ครับ',
            'รบกวนรอสักครู่ กำลังตรวจสอบให้ครับ',
            'ขออภัยในความไม่สะดวกครับ',
            'รับทราบแล้วครับ',
            'หากมีคำถามเพิ่มเติม สามารถสอบถามได้ตลอดครับ',
        ];

        // ── Safe HTML escape helper ──
        function esc(str) {
            if (str == null) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Search input now triggers server-side query (debounced 250ms)
        function filterUsers(query) {
            currentSearch = String(query || '').trim();
            currentPage = 1;
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(loadUsers, 250);
        }

        function renderUserList(users) {
            const container = document.getElementById('user-list-container');
            if (users.length === 0) {
                container.innerHTML = '<div style="padding:40px;text-align:center;color:#CBD5E1;font-size:13px;font-weight:700">ยังไม่มีข้อความเข้ามา</div>';
                renderPagination();
                return;
            }
            container.innerHTML = users.map(u => {
                const isActive = currentUserId == u.id;
                const avatarUrl = esc(u.picture_url || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(u.full_name) + '&background=EFF6FF&color=2563EB&bold=true'));
                const time = new Date(u.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const fallbackAvatar = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(u.full_name) + '&background=EFF6FF&color=2563EB&bold=true';
                return '<div onclick="selectUser(' + u.id + ')" class="user-item' + (isActive ? ' active' : '') + '" data-uid="' + u.id + '">'
                    + '<img src="' + avatarUrl + '" alt="avatar" onerror="this.src=\'' + esc(fallbackAvatar) + '\'">'
                    + '<div class="user-item-info">'
                    + '<div class="user-item-name">' + esc(u.full_name) + '</div>'
                    + '<div class="user-item-preview">' + esc(u.last_message) + '</div>'
                    + '</div>'
                    + '<div class="user-item-meta">'
                    + '<span class="user-item-time">' + time + '</span>'
                    + (u.unread_count > 0 ? '<span class="unread-badge">' + u.unread_count + '</span>' : '')
                    + '</div>'
                    + '</div>';
            }).join('');

            // Store user data for selectUser lookup
            allUsers.forEach(u => {
                const el = container.querySelector('[data-uid="' + u.id + '"]');
                if (el) el._userData = u;
            });
            renderPagination();
        }

        function renderPagination() {
            let host = document.getElementById('user-list-pagination');
            if (!host) {
                host = document.createElement('div');
                host.id = 'user-list-pagination';
                host.style.cssText = 'padding:8px;display:flex;flex-wrap:wrap;justify-content:center;gap:4px;border-top:1px solid #E2E8F0;background:#F8FAFC';
                document.getElementById('user-list-container').after(host);
            }
            if (totalPages <= 1) { host.innerHTML = ''; return; }
            const btn = (label, target, opts = {}) => {
                const disabled = !!opts.disabled;
                const active = !!opts.active;
                const bg  = active ? '#2563EB' : (disabled ? '#F1F5F9' : '#FFFFFF');
                const fg  = active ? '#FFFFFF' : (disabled ? '#CBD5E1' : '#475569');
                const cur = disabled ? 'default' : 'pointer';
                return `<button type="button" data-page="${target}" ${disabled ? 'disabled' : ''}
                    style="min-width:28px;height:28px;border:1px solid #E2E8F0;border-radius:6px;background:${bg};color:${fg};font-size:11px;font-weight:800;cursor:${cur};padding:0 6px">${label}</button>`;
            };
            const parts = [];
            parts.push(btn('«', 1, { disabled: currentPage === 1 }));
            parts.push(btn('‹', Math.max(1, currentPage - 1), { disabled: currentPage === 1 }));
            for (let p = Math.max(1, currentPage - 2); p <= Math.min(totalPages, currentPage + 2); p++) {
                parts.push(btn(String(p), p, { active: p === currentPage }));
            }
            parts.push(btn('›', Math.min(totalPages, currentPage + 1), { disabled: currentPage === totalPages }));
            parts.push(btn('»', totalPages, { disabled: currentPage === totalPages }));
            host.innerHTML = parts.join('') +
                `<div style="flex-basis:100%;text-align:center;font-size:10px;font-weight:700;color:#94A3B8;margin-top:2px">หน้า ${currentPage} / ${totalPages}</div>`;
            host.querySelectorAll('button[data-page]').forEach(b => {
                b.addEventListener('click', () => {
                    const p = parseInt(b.dataset.page, 10);
                    if (!Number.isNaN(p) && p !== currentPage) { currentPage = p; loadUsers(); }
                });
            });
        }

        function selectUser(id) {
            const u = allUsers.find(x => x.id == id);
            if (!u) return;

            // Save current draft before switching away
            const input = document.getElementById('chat-input');
            if (currentUserId !== null) drafts[currentUserId] = input.value;

            currentUserId = id;
            // Force a full reload of this conversation's history
            lastMsgIdByUser[id] = 0;
            document.getElementById('messages-container').innerHTML = '';
            document.getElementById('chat-placeholder').style.display = 'none';
            document.getElementById('chat-active').classList.add('visible');
            document.getElementById('active-user-name').innerText = u.full_name;
            const imgEl = document.getElementById('active-user-img');
            const fallback = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(u.full_name) + '&background=EFF6FF&color=2563EB&bold=true';
            imgEl.src = u.picture_url || fallback;
            imgEl.onerror = () => { imgEl.src = fallback; };

            // Restore the draft for this conversation (if any)
            input.value = drafts[id] || '';
            autoGrowInput(input);
            input.focus();

            renderUserList(allUsers);
            loadMessages();
        }

        function autoGrowInput(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 200) + 'px';
        }

        function renderQuickReplies() {
            const host = document.getElementById('quick-replies');
            if (!host) return;
            const labelHtml = '<span class="quick-replies-label"><i class="fa-solid fa-bolt"></i> ตอบด่วน</span>';
            const chipsHtml = QUICK_REPLIES.map((text, i) =>
                `<button type="button" class="qr-chip" data-idx="${i}" title="${esc(text)}">${esc(text.length > 28 ? text.slice(0, 28) + '…' : text)}</button>`
            ).join('');
            host.innerHTML = labelHtml + chipsHtml;
            host.querySelectorAll('.qr-chip').forEach(btn => {
                btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.idx, 10);
                    insertQuickReply(QUICK_REPLIES[idx] || '');
                });
            });
        }

        function insertQuickReply(text) {
            if (!text) return;
            const t = document.getElementById('chat-input');
            const start = t.selectionStart ?? t.value.length;
            const end   = t.selectionEnd   ?? t.value.length;
            const before = t.value.slice(0, start);
            const after  = t.value.slice(end);
            const sep = before && !before.endsWith('\n') && !before.endsWith(' ') ? ' ' : '';
            t.value = before + sep + text + after;
            const pos = before.length + sep.length + text.length;
            t.setSelectionRange(pos, pos);
            autoGrowInput(t);
            t.focus();
            if (currentUserId !== null) drafts[currentUserId] = t.value;
        }

        function updateUnreadTitle() {
            const total = allUsers.reduce((sum, u) => sum + (parseInt(u.unread_count, 10) || 0), 0);
            document.title = total > 0 ? `(${total}) ${BASE_TITLE}` : BASE_TITLE;
            // Browser notification — only fire on a new bump (avoid noise on every poll)
            if (total > prevTotalUnread && 'Notification' in window && Notification.permission === 'granted') {
                try {
                    new Notification('ข้อความใหม่ในระบบ Support Chat', {
                        body: `มีข้อความที่ยังไม่ได้ตอบทั้งหมด ${total} รายการ`,
                        tag: 'support-chat-unread',
                    });
                } catch (e) { /* ignore */ }
            }
            prevTotalUnread = total;
        }

        async function loadUsers() {
            try {
                const params = new URLSearchParams({ action: 'list_users', page: String(currentPage) });
                if (currentSearch !== '') params.set('q', currentSearch);
                const res = await fetch('ajax_support_chat.php?' + params.toString());
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Response is not JSON (likely auth redirect):', text.substring(0, 200));
                    document.getElementById('user-list-container').innerHTML =
                        `<div style="padding:24px;text-align:center;color:#EF4444;font-size:13px;font-weight:700">
                            <i class="fa-solid fa-triangle-exclamation" style="margin-bottom:8px;display:block;font-size:24px"></i>
                            Session หมดอายุ<br><a href="index.php" style="color:#2563EB">กลับหน้าหลัก</a>
                        </div>`;
                    return;
                }
                if (data.success) {
                    allUsers = data.users;
                    currentPage = data.page || 1;
                    totalPages = data.pages || 1;
                    if (allUsers.length === 0 && data.empty_message) {
                        document.getElementById('user-list-container').innerHTML =
                            `<div style="padding:40px;text-align:center;color:#CBD5E1;font-size:13px;font-weight:700">${esc(data.empty_message)}</div>`;
                        renderPagination();
                    } else {
                        renderUserList(allUsers);
                    }
                    updateUnreadTitle();
                } else {
                    console.warn('API error:', data.error);
                    document.getElementById('user-list-container').innerHTML =
                        `<div style="padding:24px;text-align:center;color:#EF4444;font-size:12px;font-weight:700">${esc(data.error)}</div>`;
                }
            } catch(e) {
                console.error('loadUsers network error:', e);
            }
        }

        async function loadMessages() {
            if (!currentUserId) return;
            const sinceId = lastMsgIdByUser[currentUserId] || 0;
            try {
                const res = await fetch(`ajax_support_chat.php?action=get_messages&user_id=${currentUserId}&since_id=${sinceId}`);
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch(e) {
                    console.error('loadMessages: non-JSON response:', text.substring(0, 150));
                    return;
                }
                if (!data.success) return;

                const container = document.getElementById('messages-container');
                if (!data.messages || data.messages.length === 0) return;

                const renderBubble = m => {
                    const isStaff = m.sender_type === 'staff';
                    return `
                        <div class="msg-bubble ${isStaff ? 'msg-staff' : 'msg-user'}">
                            <p>${esc(m.message)}</p>
                            <div class="msg-meta">
                                <span>${isStaff ? 'คุณ' : 'ผู้ใช้งาน'}</span>
                                <span>${esc(m.time)}</span>
                            </div>
                        </div>
                    `;
                };

                // Auto-scroll only if admin was already near the bottom
                const wasAtBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 80;

                if (sinceId === 0) {
                    container.innerHTML = data.messages.map(renderBubble).join('');
                } else {
                    container.insertAdjacentHTML('beforeend', data.messages.map(renderBubble).join(''));
                }

                // Update cursor — id ของข้อความล่าสุด
                const lastId = data.messages[data.messages.length - 1].id;
                if (lastId > (lastMsgIdByUser[currentUserId] || 0)) {
                    lastMsgIdByUser[currentUserId] = lastId;
                }

                if (sinceId === 0 || wasAtBottom) container.scrollTop = container.scrollHeight;
            } catch(e) {
                console.error('loadMessages error:', e);
            }
        }

        async function handleStaffSubmit(e) {
            e.preventDefault();
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message || !currentUserId) return;

            const formData = new FormData();
            formData.append('user_id', currentUserId);
            formData.append('message', message);
            formData.append('csrf_token', portal_CSRF);

            // Optimistic clear so admin can keep typing; we'll restore on failure
            input.disabled = true;

            try {
                const res = await fetch('ajax_support_chat.php?action=send_reply', {
                    method: 'POST',
                    body: formData
                });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch(e) {
                    console.error('sendReply: non-JSON response:', text.substring(0, 150));
                    return;
                }
                if (data.success) {
                    input.value = '';
                    if (currentUserId !== null) drafts[currentUserId] = '';
                    autoGrowInput(input);
                    loadMessages();
                } else {
                    Swal.fire({ icon: 'error', title: 'ส่งข้อความไม่สำเร็จ', text: data.error || 'กรุณาลองใหม่' });
                }
            } catch(err) {
                console.error('sendReply error:', err);
                Swal.fire({ icon: 'error', title: 'เครือข่ายขัดข้อง', text: 'ส่งข้อความไม่สำเร็จ — กรุณาลองใหม่' });
            } finally {
                input.disabled = false;
                input.focus();
            }
        }

        // Wire up the textarea: Enter sends, Shift+Enter newline, autogrow, draft on every keystroke
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('chat-input');
            if (input) {
                input.addEventListener('keydown', e => {
                    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                        e.preventDefault();
                        document.getElementById('chat-form').requestSubmit();
                    }
                });
                input.addEventListener('input', () => {
                    autoGrowInput(input);
                    if (currentUserId !== null) drafts[currentUserId] = input.value;
                });
            }
            renderQuickReplies();

            // Ask for browser notification permission once (silent if denied)
            if ('Notification' in window && Notification.permission === 'default') {
                try { Notification.requestPermission(); } catch (e) { /* ignore */ }
            }
        });

        // Initial Load
        loadUsers();
        setInterval(loadUsers, 5000);
        setInterval(() => { if (currentUserId) loadMessages(); }, 3000);
    </script>
</body>
</html>

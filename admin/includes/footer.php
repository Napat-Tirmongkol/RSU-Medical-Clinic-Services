<?php
// admin/includes/footer.php
$_scriptDir   = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$_depth       = max(0, substr_count(trim($_scriptDir, '/'), '/'));
$_jsEndpoint  = str_repeat('../', $_depth) . 'api/log_js_error.php';
$_curPage     = basename($_SERVER['PHP_SELF'] ?? '');
?>
        </div>
    </main>

    <script>
    /* ── JS Error Tracker (admin) ─────────────────────────────── */
    (function () {
      var ENDPOINT = '<?= htmlspecialchars($_jsEndpoint, ENT_QUOTES) ?>';
      var MAX = 10, sent = 0, seen = {};
      function send(data) {
        if (sent >= MAX) return;
        var key = (data.message + '|' + data.source).slice(0, 120);
        if (seen[key]) return;
        seen[key] = true; sent++;
        var blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
        if (navigator.sendBeacon) { navigator.sendBeacon(ENDPOINT, blob); }
        else { fetch(ENDPOINT, { method: 'POST', body: blob, keepalive: true }).catch(function(){}); }
      }
      window.onerror = function (msg, src, line, col, err) {
        send({ level:'error', message:String(msg), source:(src||'unknown')+':'+line+':'+col, stack:err&&err.stack?err.stack:'', url:location.href });
        return false;
      };
      window.addEventListener('unhandledrejection', function (e) {
        var r = e.reason;
        // Skip harmless AbortError from skipped View Transitions
        // (filter defined in header.php — same predicate to keep behavior consistent)
        if (typeof window.__isSkippedViewTransition === 'function' && window.__isSkippedViewTransition(r)) return;
        send({ level:'error', message:'UnhandledRejection: '+(r instanceof Error?r.message:String(r)), source:'promise', stack:r instanceof Error?(r.stack||''):'', url:location.href });
      });
      var _ce = console.error.bind(console);
      console.error = function () {
        _ce.apply(console, arguments);
        var args = Array.prototype.slice.call(arguments);
        var msg = args.map(function(a){ if(a instanceof Error) return a.message; try{return typeof a==='object'?JSON.stringify(a):String(a);}catch(e){return String(a);} }).join(' ');
        send({ level:'error', message:'[console.error] '+msg, source:'console', stack:args[0] instanceof Error?(args[0].stack||''):'', url:location.href });
      };
    })();
    /* ── End JS Error Tracker ─────────────────────────────────── */
    </script>

    <!-- ════════ Guided Tour (Driver.js) ════════ -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
    <script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
    <script src="../assets/js/rsu-tour.js"></script>
    <script>
    (function () {
        // Per-page tour definitions. Key bumps invalidate localStorage so a redesigned
        // tour replays for everyone (e.g., welcome_v1 → welcome_v2).
        var TOURS = {
            'index.php': {
                key: 'ecadmin_welcome_v1',
                label: 'แนะนำการใช้งาน',
                steps: [
                    { popover: {
                        title: '👋 ยินดีต้อนรับสู่ e-Campaign',
                        description: 'ระบบจัดการแคมเปญฉีดวัคซีน / อบรม / ตรวจสุขภาพ ของคลินิก RSU<br><br>ทัวร์สั้นๆ จะพาดูเมนูหลักและสอนวิธี<b>สร้างแคมเปญแรก</b>ของคุณครับ'
                    }},
                    { element: '.admin-sidebar', popover: {
                        title: 'เมนูซ้าย',
                        description: 'ทุกหน้างานอยู่ที่นี่ — กดหัวกลุ่มเพื่อพับ/กางเมนู',
                        side: 'right'
                    }},
                    { element: 'a.nav-link[href*="index.php"]', popover: {
                        title: 'Dashboard',
                        description: 'หน้าหลัก — สรุปสถานะแบบเรียลไทม์ (อัปเดตทุก 15 วินาที)',
                        side: 'right'
                    }},
                    { element: '.ec-grid-kpi', popover: {
                        title: '4 ตัวเลขสำคัญ',
                        description: '<b>แคมเปญที่เปิด · จองวันนี้ · ผู้ใช้ใหม่ · รออนุมัติ</b><br>กดที่ "รออนุมัติ" เพื่อข้ามไปจัดการ booking ที่ค้าง',
                        side: 'bottom'
                    }},
                    { element: '.nav-section[data-section="campaign"]', popover: {
                        title: 'กลุ่มแคมเปญ',
                        description: '3 หน้าหลักของ workflow — <b>สร้างแคมเปญ → กำหนดรอบเวลา → ดูผู้เข้าร่วม</b>',
                        side: 'right'
                    }},
                    { element: 'a.nav-link[href*="campaigns.php"]', popover: {
                        title: 'เริ่มต้นที่นี่',
                        description: 'คลิก "สร้างแคมเปญ" เพื่อสร้างกิจกรรมแรก — ทัวร์จะพาทำต่อในหน้าถัดไป',
                        side: 'right'
                    }},
                    { popover: {
                        title: 'พร้อมเริ่มแล้ว 🎉',
                        description: 'ปุ่ม <i class="fa-solid fa-circle-question"></i> มุมขวาล่าง — กดเรียกทัวร์ซ้ำเมื่อใดก็ได้'
                    }},
                ]
            },

            'campaigns.php': {
                key: 'ecadmin_campaigns_v1',
                label: 'สอนสร้างแคมเปญ',
                steps: [
                    { popover: {
                        title: '📋 หน้าจัดการแคมเปญ',
                        description: 'รวมรายการแคมเปญทั้งหมด · สร้างใหม่ · แก้ไข · เปิด/ปิด QR Walk-in · สร้างลิงก์แชร์'
                    }},
                    { element: 'button[onclick="openAddModal()"]', popover: {
                        title: '➕ ปุ่มสร้างแคมเปญ',
                        description: 'คลิกเพื่อเปิดฟอร์ม — กรอกชื่อ · ประเภท · โควต้ารวม · ช่วงวันที่เปิดรับ · สถานที่ จากนั้นกดบันทึก',
                        side: 'bottom'
                    }},
                    { element: '.glass-table-container', popover: {
                        title: 'ตารางรายการแคมเปญ',
                        description: 'ดู<b>ยอดจองคงเหลือ</b>, <b>วันที่หมดเขต</b> และ<b>สถานะ</b>ของทุกแคมเปญในที่เดียว',
                        side: 'top'
                    }},
                    { element: '.act-btn-edit', popover: {
                        title: '✏️ แก้ไขแคมเปญ',
                        description: 'ปรับชื่อ/โควต้า/วันที่/รูปหน้าปก · เปลี่ยนสถานะ (active / full / closed) ได้ที่นี่',
                        side: 'left'
                    }},
                    { element: '.walkin-qr-btn', popover: {
                        title: '🚶 Walk-in QR',
                        description: 'พิมพ์โปสเตอร์ A4 พร้อม QR — ผู้ป่วยที่มาหน้างานสแกนแล้วลงทะเบียนเข้าระบบได้ทันที (รับ overflow แม้สถานะเป็น "เต็มแล้ว")',
                        side: 'left'
                    }},
                    { element: 'a.nav-link[href*="time_slots.php"]', popover: {
                        title: 'ขั้นต่อไป → รอบเวลา',
                        description: 'หลังสร้างแคมเปญแล้ว ต้องไปกำหนด<b>รอบเวลา (slot)</b> ที่นี่ก่อน แล้วผู้ใช้ถึงจะจองได้',
                        side: 'right'
                    }},
                ]
            },

            'time_slots.php': {
                key: 'ecadmin_time_slots_v1',
                label: 'สอนกำหนดรอบเวลา',
                steps: [
                    { popover: {
                        title: '🕐 จัดการรอบเวลา',
                        description: 'สร้างรอบเวลาที่ผู้ป่วยจะมาจอง — เลือกแคมเปญ · กำหนดวัน · เวลา · โควต้าต่อรอบ'
                    }},
                    { element: '#addSlotBtn', popover: {
                        title: '➕ สร้างรอบเวลา',
                        description: 'เพิ่มรอบใหม่ — เลือกแคมเปญ, ระบุเวลาเริ่ม–สิ้นสุด, โควต้า, และจำนวนวันที่ต้องการ (สร้างซ้ำหลายวันได้)',
                        side: 'bottom'
                    }},
                    { element: '#btnViewCalendar', popover: {
                        title: 'สลับมุมมอง',
                        description: '<b>ปฏิทิน</b> = เห็นทั้งเดือนในตารางใหญ่ · <b>ตาราง</b> = ลิสต์เรียงตามวันที่ — เลือกใช้ตามความถนัด',
                        side: 'bottom'
                    }},
                    { element: '#monthLabelBtn', popover: {
                        title: 'เลือกเดือนที่ดู',
                        description: 'เลื่อนไปเดือนก่อน/ถัดไปด้วยลูกศร · กดที่ชื่อเดือนเพื่อกระโดดไปเดือนใดก็ได้',
                        side: 'bottom'
                    }},
                    { element: 'a.nav-link[href*="bookings.php"]', popover: {
                        title: 'พอมีคนจอง → ดูที่นี่',
                        description: 'เมื่อสร้าง slot เสร็จและประกาศแคมเปญแล้ว booking ใหม่จะมาแสดงที่หน้า "ผู้เข้าร่วม"',
                        side: 'right'
                    }},
                ]
            },

            'bookings.php': {
                key: 'ecadmin_bookings_v1',
                label: 'สอนจัดการ booking',
                steps: [
                    { popover: {
                        title: '👥 ผู้เข้าร่วม',
                        description: 'ดู · อนุมัติ · เช็คอิน · ยกเลิก booking ของทุกแคมเปญในที่เดียว'
                    }},
                    { element: '#kpiPending', popover: {
                        title: 'KPI 3 สถานะ',
                        description: '<b>รออนุมัติ</b> = ยังไม่ถูกยืนยัน (booked) · <b>รอเข้าร่วม</b> = ยืนยันแล้ว (confirmed) · <b>เข้าร่วมแล้ว</b> = เช็คอินแล้ว (completed)',
                        side: 'bottom'
                    }},
                    { element: '#filterDateFrom', popover: {
                        title: 'กรองช่วงวันที่',
                        description: 'กรอง booking ตามวันที่ของ slot — default แสดง 30 วันล่าสุด',
                        side: 'bottom'
                    }},
                    { element: '#filterCampaign', popover: {
                        title: 'กรองตามแคมเปญ',
                        description: 'เลือกแคมเปญที่ต้องการดู — หรือ "ทุกกิจกรรม" เพื่อดูรวม',
                        side: 'bottom'
                    }},
                    { element: 'button[onclick="openWalkinModal()"]', popover: {
                        title: 'เพิ่ม Walk-in ด้วยตัวเอง',
                        description: 'admin เพิ่ม booking ของคนที่มาหน้างานแบบ manual ได้ — กรอกชื่อ/รหัส แล้วเลือก slot ที่จะลง',
                        side: 'left'
                    }},
                ]
            },
        };

        var curPage = '<?= htmlspecialchars($_curPage, ENT_QUOTES) ?>';
        var tour = TOURS[curPage];

        // Wait for Driver.js + RsuTour to load, then wire up
        document.addEventListener('DOMContentLoaded', function () {
            if (!tour || !window.RsuTour) {
                // No tour for this page — hide the FAB
                var fab = document.getElementById('ec-tour-fab');
                if (fab) fab.style.display = 'none';
                return;
            }

            window._ecTourSteps = tour.steps;
            window._ecTourKey   = tour.key;

            // Auto-start on first visit (RsuTour stores tour_done_<key> in localStorage)
            window.RsuTour.maybeAutoStart(tour.key, tour.steps);

            // Update FAB tooltip with this page's tour label
            var fab = document.getElementById('ec-tour-fab');
            if (fab && tour.label) {
                fab.setAttribute('title', tour.label);
                fab.setAttribute('aria-label', tour.label);
            }
        });
    })();
    </script>

    <button id="ec-tour-fab" type="button"
            onclick="window.RsuTour && window._ecTourSteps && RsuTour.start(window._ecTourSteps, window._ecTourKey)"
            aria-label="ดูคำแนะนำการใช้งาน" title="ดูคำแนะนำการใช้งาน"
            style="position:fixed;bottom:20px;right:20px;width:48px;height:48px;border-radius:50%;border:none;background:linear-gradient(135deg,#2e9e63,#34d399);color:#fff;font-size:18px;cursor:pointer;box-shadow:0 6px 18px rgba(46,158,99,.42);z-index:90;transition:transform .15s ease, box-shadow .15s ease;display:flex;align-items:center;justify-content:center">
        <i class="fa-solid fa-circle-question"></i>
    </button>
    <style>
        #ec-tour-fab:hover { transform: translateY(-2px) scale(1.06); box-shadow: 0 10px 24px rgba(46,158,99,.55); }
        #ec-tour-fab:active { transform: translateY(0) scale(0.98); }
        @media print { #ec-tour-fab { display: none !important; } }
    </style>
</body>
</html>
<?php // end admin/includes/footer.php ?>

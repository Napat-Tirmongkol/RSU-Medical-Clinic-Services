<?php
// Sub-view: Survey Questions (post-checkin) — admin จัดการคำถามที่ user ต้องตอบหลังเช็คอิน
require_once __DIR__ . '/../../../includes/survey_helper.php';
$pdo = db();
ensure_survey_schema($pdo);

// Pagination + filter
$search = trim($_GET['s'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = "WHERE survey_type = 'post_checkin'";
$params = [];
if ($search !== '') {
    $where .= " AND question_text LIKE ?";
    $params[] = "%$search%";
}

$total = 0; $rows = [];
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_survey_questions $where");
    $sc->execute($params);
    $total = (int)$sc->fetchColumn();
    $sr = $pdo->prepare("SELECT * FROM sys_survey_questions $where ORDER BY sort_order ASC, id ASC LIMIT $limit OFFSET $offset");
    $sr->execute($params);
    $rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$totalPages = max(1, (int)ceil($total / $limit));
$totalAll   = (int)$pdo->query("SELECT COUNT(*) FROM sys_survey_questions WHERE survey_type = 'post_checkin'")->fetchColumn();
$activeAll  = (int)$pdo->query("SELECT COUNT(*) FROM sys_survey_questions WHERE survey_type = 'post_checkin' AND is_active = 1")->fetchColumn();

$typeLabels = ['rating'=>'ให้คะแนน 1-5','text'=>'ตอบเอง (ข้อความ)','single_choice'=>'ตัวเลือกเดียว'];
?>
<style>
    #sq-modal { z-index: 200; }
    #sq-modal-box { max-height: 90vh; }
</style>

<div class="max-w-[1100px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-pink-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-pink-50 rounded-xl shadow-sm border border-pink-100 flex items-center justify-center text-pink-600 text-xl">
            <i class="fa-solid fa-clipboard-question"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">แบบสอบถามหลังเช็คอิน</h2>
            <p class="text-slate-500 text-sm font-medium">user จะถูกบังคับตอบทุกครั้งหลังเช็คอินเข้าร่วมกิจกรรมสำเร็จ — เพิ่ม/แก้/เปิด-ปิดได้ที่นี่</p>
        </div>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-pink-50 border border-pink-100 text-pink-700 text-[10px] font-black uppercase tracking-widest">
            <?= $activeAll ?>/<?= $totalAll ?> active
        </span>
        <button onclick="sqOpenAdd()" class="px-4 py-2 bg-pink-600 text-white rounded-xl text-sm font-black hover:bg-pink-700 transition-all flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus"></i>เพิ่มคำถาม
        </button>
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center gap-3">
            <form method="GET" class="flex-1 flex items-center gap-2">
                <input type="hidden" name="section" value="clinic_data">
                <input type="hidden" name="cd_view" value="survey">
                <i class="fa-solid fa-magnifying-glass text-slate-400 text-sm"></i>
                <input type="search" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาข้อความคำถาม"
                    class="flex-1 bg-transparent text-sm font-bold text-slate-700 outline-none placeholder:text-slate-300">
                <?php if ($search !== ''): ?><a href="?section=clinic_data&cd_view=survey" class="text-xs text-slate-400"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
            </form>
            <p class="text-[11px] font-black text-slate-400">หน้า <?= $page ?>/<?= $totalPages ?> · รวม <?= $total ?> รายการ</p>
        </div>

        <?php if (empty($rows)): ?>
        <div class="p-12 text-center">
            <i class="fa-solid fa-clipboard-question text-5xl text-slate-200 mb-3"></i>
            <p class="font-black text-slate-500">ยังไม่มีคำถาม</p>
            <p class="text-sm text-slate-400 mt-1">กดปุ่ม "เพิ่มคำถาม" ด้านบน</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-3 py-3 text-center w-12">#</th>
                        <th class="px-3 py-3 text-left">คำถาม</th>
                        <th class="px-3 py-3 text-left w-32">ประเภทคำตอบ</th>
                        <th class="px-3 py-3 text-center w-20">บังคับตอบ</th>
                        <th class="px-3 py-3 text-center w-20">เปิดใช้</th>
                        <th class="px-3 py-3 text-center w-32">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $opts = $r['options_json'] ? json_decode($r['options_json'], true) : null;
                    ?>
                    <tr class="border-t border-slate-100 hover:bg-slate-50/50">
                        <td class="px-3 py-3 text-center font-black text-slate-400"><?= (int)$r['sort_order'] ?></td>
                        <td class="px-3 py-3">
                            <div class="font-black text-slate-800"><?= htmlspecialchars($r['question_text']) ?></div>
                            <?php if (is_array($opts)): ?>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <?php foreach ($opts as $o): ?>
                                <span class="inline-block px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-bold"><?= htmlspecialchars($o) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[10px] font-black">
                                <?= htmlspecialchars($typeLabels[$r['answer_type']] ?? $r['answer_type']) ?>
                            </span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <?php if ((int)$r['is_required']): ?>
                                <span class="inline-block px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-black">บังคับ</span>
                            <?php else: ?>
                                <span class="inline-block px-2 py-0.5 rounded-full bg-slate-50 text-slate-500 border border-slate-200 text-[10px] font-black">ไม่บังคับ</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <button onclick="sqToggle(<?= (int)$r['id'] ?>)"
                                class="<?= (int)$r['is_active'] ? 'bg-emerald-500' : 'bg-slate-300' ?> relative inline-flex h-5 w-9 items-center rounded-full transition-colors">
                                <span class="<?= (int)$r['is_active'] ? 'translate-x-4' : 'translate-x-1' ?> inline-block h-3 w-3 transform rounded-full bg-white transition-transform"></span>
                            </button>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <button onclick='sqOpenEdit(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="px-2 py-1 text-pink-600 hover:bg-pink-50 rounded text-xs font-black"><i class="fa-solid fa-pen"></i></button>
                            <button onclick="sqDelete(<?= (int)$r['id'] ?>)"
                                class="px-2 py-1 text-rose-500 hover:bg-rose-50 rounded text-xs font-black"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="p-4 border-t border-slate-100 flex items-center justify-center gap-1 text-xs">
            <?php
            $qs = function(int $p) use ($search) {
                $q = ['section'=>'clinic_data','cd_view'=>'survey','p'=>$p];
                if ($search !== '') $q['s'] = $search;
                return '?' . http_build_query($q);
            };
            $btn = function(string $href, string $label, bool $active = false, bool $disabled = false) {
                $cls = $active ? 'bg-pink-600 text-white' : ($disabled ? 'bg-slate-50 text-slate-300 pointer-events-none' : 'bg-white text-slate-600 hover:bg-pink-50 border border-slate-200');
                return "<a href=\"$href\" class=\"px-2.5 py-1.5 rounded-lg font-black $cls\">$label</a>";
            };
            echo $btn($qs(1), '«', false, $page === 1);
            echo $btn($qs(max(1, $page - 1)), '‹', false, $page === 1);
            for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++) {
                echo $btn($qs($p), (string)$p, $p === $page);
            }
            echo $btn($qs(min($totalPages, $page + 1)), '›', false, $page === $totalPages);
            echo $btn($qs($totalPages), '»', false, $page === $totalPages);
            ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="mt-5 p-4 rounded-2xl bg-amber-50 border border-amber-200 flex items-start gap-3 text-[12px] text-amber-800 font-medium">
        <i class="fa-solid fa-circle-exclamation text-amber-500 mt-0.5"></i>
        <div>
            <strong class="font-black">หมายเหตุ:</strong>
            ลบคำถามที่มีคนตอบไปแล้ว — คำตอบเดิมจะยังอยู่ใน DB แต่จะ orphan (ไม่มีคำถามอ้างถึง) ถ้าต้องการเก็บประวัติ แนะนำให้ "ปิดใช้" แทน
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="sq-modal" class="hidden fixed inset-0 flex items-center justify-center bg-black/40 p-4">
    <div id="sq-modal-box" class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 id="sq-modal-title" class="font-black text-slate-800">เพิ่มคำถาม</h3>
            <button onclick="sqCloseModal()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="sq-form" onsubmit="sqSave(event)" class="p-6 space-y-4">
            <input type="hidden" name="id" id="sq-id">

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ข้อความคำถาม *</label>
                <input type="text" name="question_text" id="sq-question-text" required maxlength="255"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-pink-400">
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ประเภทคำตอบ *</label>
                <div class="grid grid-cols-3 gap-1.5 p-1 bg-slate-50 border border-slate-200 rounded-xl">
                    <?php foreach ($typeLabels as $k=>$l): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="answer_type" value="<?= $k ?>" class="peer hidden" <?= $k === 'rating' ? 'checked' : '' ?> onchange="sqToggleType()">
                        <div class="text-center py-2 rounded-lg text-[11px] font-black text-slate-500 peer-checked:bg-white peer-checked:text-pink-700 peer-checked:shadow-sm transition-all"><?= htmlspecialchars($l) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="sq-field-options" class="hidden">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ตัวเลือก (บรรทัดละ 1, อย่างน้อย 2 ตัวเลือก) *</label>
                <textarea name="options" id="sq-options" rows="4"
                    placeholder="เร็วกว่าที่คาด&#10;พอดี&#10;รอนาน"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-pink-400 font-mono"></textarea>
            </div>

            <label class="flex items-center gap-3 cursor-pointer p-3 bg-slate-50 rounded-xl border border-slate-200">
                <input type="checkbox" name="is_required" id="sq-is-required" checked class="w-4 h-4 accent-pink-600">
                <div class="flex-1">
                    <div class="font-black text-sm text-slate-800">บังคับตอบ</div>
                    <div class="text-[11px] text-slate-500 font-medium">user จะ submit แบบสอบถามไม่ได้ถ้ายังไม่ตอบข้อนี้</div>
                </div>
            </label>

            <div class="flex gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="sqCloseModal()" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-sm font-black hover:bg-slate-200">ยกเลิก</button>
                <button type="submit" class="flex-[2] px-4 py-2.5 bg-pink-600 text-white rounded-xl text-sm font-black hover:bg-pink-700 shadow-sm">
                    <i class="fa-solid fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
async function sqPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'survey_q'); fd.append('action', action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
    return res.json();
}

function sqToggleType() {
    const t = document.querySelector('[name=answer_type]:checked').value;
    document.getElementById('sq-field-options').classList.toggle('hidden', t !== 'single_choice');
}

function sqOpenAdd() {
    document.getElementById('sq-modal-title').textContent = 'เพิ่มคำถาม';
    document.getElementById('sq-form').reset();
    document.getElementById('sq-id').value = '';
    document.querySelector('[name=answer_type][value=rating]').checked = true;
    document.getElementById('sq-is-required').checked = true;
    sqToggleType();
    const m = document.getElementById('sq-modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function sqOpenEdit(row) {
    document.getElementById('sq-modal-title').textContent = 'แก้คำถาม #' + row.id;
    document.getElementById('sq-id').value = row.id;
    document.getElementById('sq-question-text').value = row.question_text;
    document.querySelector(`[name=answer_type][value=${row.answer_type}]`).checked = true;
    document.getElementById('sq-is-required').checked = !!parseInt(row.is_required, 10);
    if (row.options_json) {
        try {
            const opts = JSON.parse(row.options_json);
            document.getElementById('sq-options').value = Array.isArray(opts) ? opts.join('\n') : '';
        } catch (e) { document.getElementById('sq-options').value = ''; }
    } else {
        document.getElementById('sq-options').value = '';
    }
    sqToggleType();
    const m = document.getElementById('sq-modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function sqCloseModal() {
    const m = document.getElementById('sq-modal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}

async function sqSave(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    if (!data.is_required) data.is_required = '0';
    const res = await sqPost(data.id ? 'update' : 'add', data);
    if (res.ok) {
        showPortalToast(res.message, 'success');
        sqCloseModal();
        setTimeout(() => location.reload(), 400);
    } else {
        Swal.fire('ผิดพลาด', res.message || 'บันทึกไม่สำเร็จ', 'error');
    }
}

async function sqToggle(id) {
    const res = await sqPost('toggle', { id });
    if (res.ok) location.reload();
    else Swal.fire('Error', res.message, 'error');
}

async function sqDelete(id) {
    const c = await Swal.fire({
        title: 'ลบคำถามนี้?',
        text: 'คำตอบเดิมที่อ้างถึงคำถามนี้จะกลายเป็น orphan',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
    });
    if (!c.isConfirmed) return;
    const res = await sqPost('delete', { id });
    if (res.ok) { showPortalToast('ลบแล้ว', 'success'); setTimeout(() => location.reload(), 400); }
    else Swal.fire('Error', res.message, 'error');
}
</script>

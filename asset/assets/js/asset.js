/* asset/assets/js/asset.js */
(function () {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    window.ASSET_CSRF = csrfMeta ? csrfMeta.content : '';
})();

window.assetConfirmDelete = function (url, label) {
    Swal.fire({
        title: 'ลบรายการนี้?',
        html: `<div class="text-slate-600 text-sm">คุณกำลังจะลบ <strong>${label || ''}</strong><br>การกระทำนี้ไม่สามารถย้อนกลับได้</div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', window.ASSET_CSRF);
        fetch(url, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    Swal.fire({ icon: 'success', title: 'ลบสำเร็จ', timer: 1200, showConfirmButton: false })
                        .then(() => window.location.reload());
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถลบได้', 'error');
                }
            })
            .catch(() => Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', 'error'));
    });
};

window.assetQuickStatus = function (id, currentStatus) {
    Swal.fire({
        title: 'เปลี่ยนสถานะครุภัณฑ์',
        input: 'select',
        inputOptions: {
            in_use: 'ใช้งาน',
            repair: 'ซ่อม',
            reserve: 'สำรอง',
            disposed: 'จำหน่าย',
            lost: 'สูญหาย',
        },
        inputValue: currentStatus,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', window.ASSET_CSRF);
        fd.append('id', id);
        fd.append('status', res.value);
        fetch('ajax/update_status.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    Swal.fire({ icon: 'success', title: 'อัปเดตสำเร็จ', timer: 1100, showConfirmButton: false })
                        .then(() => window.location.reload());
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message || 'อัปเดตไม่สำเร็จ', 'error');
                }
            })
            .catch(() => Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', 'error'));
    });
};

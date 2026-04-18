// [สร้างไฟล์ใหม่: assets/js/theme.js]

try {
    // (1. สคริปต์ "อ่านธีม" - ทำงานทันทีที่โหลด)
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    }

    // (2. สคริปต์ "สลับธีม" - รอการคลิก)
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            if (document.body.classList.contains('dark-mode')) {
                // --- (จากมืด -> ไปสว่าง) ---
                document.documentElement.classList.remove('dark-mode');
                document.body.classList.remove('dark-mode');
                localStorage.setItem('theme', 'light');
            } else {
                // --- (จากสว่าง -> ไปมืด) ---
                document.documentElement.classList.add('dark-mode');
                document.body.classList.add('dark-mode');
                localStorage.setItem('theme', 'dark');
            }
        });
    }
} catch (e) {
    console.error('Theme toggle script error:', e);
}
<!-- portal/_partials/header.php -->
<header class="portal-header au">
    <div class="w-full px-5 sm:px-8 py-3 flex items-center justify-between gap-4" style="min-height:60px">

        <!-- Left/Center: Global Search -->
        <div style="flex: 1; display: flex; justify-content: flex-start;">
            <div class="relative group w-full max-w-[400px]">
                <input type="text" placeholder="ค้นหาเมนู หรือแคมเปญ"
                    class="w-full pl-5 pr-10 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] font-bold text-slate-800 outline-none focus:bg-white focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition-all font-prompt">
                <button
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-emerald-600 transition-colors flex items-center justify-center">
                    <i class="fa-solid fa-magnifying-glass text-sm"></i>
                </button>
            </div>
        </div>

        <!-- Right Action Icons -->
        <div class="flex items-center gap-3 sm:gap-4">

            <!-- Dark Mode Toggle Button -->
            <button id="darkModeToggle" onclick="toggleDarkMode()" title="สลับโหมดมืด/สว่าง"
                class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition-colors shadow-sm dark-mode-btn">
                <i class="fa-solid fa-moon"></i>
            </button>

            <!-- Divider -->
            <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>

            <!-- User Identity & Logout -->
            <div class="flex items-center gap-2 sm:gap-3">
                <div class="text-right hidden sm:block">
                    <div
                        class="text-[9px] font-extrabold uppercase tracking-widest text-slate-500 leading-none mb-1">
                        Admin</div>
                    <div class="text-[13px] font-black text-slate-900 leading-none">
                        <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?>
                    </div>
                </div>
                <div class="w-9 h-9 rounded-xl flex flex-shrink-0 items-center justify-center shadow-md shadow-emerald-500/20 text-sm"
                    style="background: linear-gradient(135deg, #2e9e63, #10b981); color:#fff;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <a href="../admin/auth/logout.php" title="ออกจากระบบ"
                    class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex flex-shrink-0 items-center justify-center hover:bg-rose-500 hover:text-white transition-colors border border-rose-100 ml-1">
                    <i class="fa-solid fa-power-off text-xs"></i>
                </a>
            </div>
        </div>
    </div>
</header>

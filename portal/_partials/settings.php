            <!-- ════════════ SECTION: SETTINGS (CONTENT ONLY) ════════════ -->
            <div class="max-w-[1000px] mx-auto px-4 py-8">
                    
                    <!-- Header -->
                    <div class="mb-8 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-green-600 text-xl">
                                <i class="fa-solid fa-gears"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-black text-slate-800">Settings & Maintenance</h2>
                                <p class="text-slate-500 text-sm font-medium">จัดการตั้งค่าและตรวจสอบสถานะระบบทั้งหมด</p>
                            </div>
                        </div>
                        <button onclick="triggerGitPull()" class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 transition-all flex items-center gap-2 shadow-lg shadow-blue-100">
                            <i class="fa-solid fa-rotate"></i> Git Pull Now
                        </button>
                    </div>

                    <div class="space-y-8">
                        
                        <!-- 1. System Status & Services -->
                        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 bg-slate-50 border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider">Services & Maintenance</h3>
                                <span class="text-[10px] font-bold text-slate-400 bg-white px-2 py-1 rounded-lg border border-gray-100">REAL-TIME STATUS</span>
                            </div>
                            
                            <div class="p-6">
                                <!-- Status Banner -->
                                <div id="status-banner" class="rounded-2xl border p-5 mb-6 flex items-center gap-5 transition-all"
                                     style="<?= $allOnline ? 'background:#f0fdf4; border-color:#bbf7d0;' : 'background:#fffbeb; border-color:#fef3c7;' ?>">
                                    <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 text-xl"
                                         style="<?= $allOnline ? 'background:#dcfce7; color:#16a34a;' : 'background:#fef3c7; color:#d97706;' ?>">
                                        <i id="banner-icon" class="fa-solid <?= $allOnline ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div id="banner-title" class="font-black text-slate-800 text-base">
                                            <?= $allOnline ? 'ทุกระบบพร้อมใช้งาน' : 'บางระบบปิดปรับปรุง' ?>
                                        </div>
                                        <p id="banner-desc" class="text-slate-600 text-xs mt-0.5 font-medium">
                                            <?= $allOnline ? 'ผู้ใช้ทุกคนสามารถเข้าใช้งานได้ตามปกติ' : 'คุณสามารถเปิดระบบที่ปิดอยู่ได้จากรายการด้านล่าง' ?>
                                        </p>
                                    </div>
                                    <button onclick="triggerGitPull()" class="px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold hover:bg-black transition-all flex items-center gap-2 whitespace-nowrap">
                                        <i class="fa-solid fa-rotate"></i> Git Pull Update
                                    </button>
                                </div>

                                <!-- Projects Toggle Grid -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php foreach ($mProjects as $p):
                                        $isActive = $mData[$p['key']] ?? true;
                                        ?>
                                        <div class="border border-gray-100 rounded-2xl p-4 flex items-center gap-4 hover:bg-slate-50 transition-all" id="card-<?= $p['key'] ?>">
                                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shadow-sm"
                                                 style="background:<?= $p['icon_bg'] ?>; color:<?= $p['icon_color'] ?>;">
                                                <i class="fa-solid <?= $p['icon'] ?>"></i>
                                            </div>
                                            <div class="flex-1">
                                                <div class="font-black text-slate-800 text-sm"><?= htmlspecialchars($p['title']) ?></div>
                                                <div class="status-badge <?= $isActive ? 'on' : 'off' ?> mt-1" id="badge-<?= $p['key'] ?>">
                                                    <span class="status-dot"></span>
                                                    <span class="text-[9px]"><?= $isActive ? 'ONLINE' : 'MAINTENANCE' ?></span>
                                                </div>
                                            </div>
                                            <div class="toggle-wrap">
                                                <label class="toggle">
                                                    <input type="checkbox" data-project="<?= $p['key'] ?>" <?= $isActive ? 'checked' : '' ?> onchange="toggleMaintenance(this)">
                                                    <div class="toggle-track"></div>
                                                    <div class="toggle-thumb"></div>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Configuration & Identity -->
                        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 bg-slate-50 border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider">App Configuration</h3>
                                <i class="fa-solid fa-palette text-slate-300"></i>
                            </div>
                            <div class="p-8">
                                <form id="siteSettingsForm" method="POST" action="ajax_site_settings.php" enctype="multipart/form-data" class="space-y-6">
                                    <?php csrf_field(); ?>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                        <!-- Site Name -->
                                        <div>
                                            <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">Site Name</label>
                                            <input type="text" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>" 
                                                   class="w-full px-4 py-3 bg-slate-50 border border-gray-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all text-sm font-bold text-slate-800 outline-none">
                                        </div>
                                        <!-- Logo -->
                                        <div>
                                            <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">System Logo</label>
                                            <div class="flex items-center gap-4">
                                                <?php if (defined('SITE_LOGO') && SITE_LOGO !== ''): ?>
                                                    <div class="w-12 h-12 border border-gray-200 rounded-xl p-1 bg-white">
                                                        <img src="../<?= htmlspecialchars(SITE_LOGO) ?>" class="w-full h-full object-contain">
                                                    </div>
                                                <?php endif; ?>
                                                <input type="file" name="site_logo" class="text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Gemini API -->
                                    <div class="pt-4 border-t border-gray-50">
                                        <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">Gemini AI API Key</label>
                                        <div class="relative max-w-md">
                                            <input type="password" id="gemini_api_key_v3" name="gemini_api_key" value="<?= htmlspecialchars(GEMINI_API_KEY) ?>" 
                                                   class="w-full px-4 py-3 bg-slate-50 border border-gray-200 rounded-xl font-mono text-xs font-bold text-slate-800 pr-12 outline-none">
                                            <button type="button" onclick="const p = document.getElementById('gemini_api_key_v3'); p.type = p.type==='password'?'text':'password';"
                                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </div>
                                        <p class="text-[10px] text-slate-400 mt-2 font-medium">ใช้สำหรับ AI Assistant และการวิเคราะห์ Log</p>
                                    </div>

                                    <div class="pt-4">
                                        <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-xl text-sm font-black hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all flex items-center gap-2">
                                            <i class="fa-solid fa-save"></i> บันทึกการตั้งค่า
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 3. Integrations & Diagnostic Logs -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Diagnostic Links (Fixed for Light Mode) -->
                            <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
                                <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-6">Diagnostic Logs</h4>
                                <div class="grid grid-cols-1 gap-3">
                                    <a href="javascript:switchSection('error_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-red-50 hover:border-red-100 transition-all group">
                                        <div class="w-8 h-8 rounded-lg bg-red-100 text-red-600 flex items-center justify-center text-xs"><i class="fa-solid fa-bug"></i></div>
                                        <span class="text-xs font-bold text-slate-700">Error Logs</span>
                                    </a>
                                    <a href="javascript:switchSection('activity_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-emerald-50 hover:border-emerald-100 transition-all group">
                                        <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs"><i class="fa-solid fa-bolt"></i></div>
                                        <span class="text-xs font-bold text-slate-700">Activity Logs</span>
                                    </a>
                                    <a href="javascript:switchSection('email_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-blue-50 hover:border-blue-100 transition-all group">
                                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center text-xs"><i class="fa-solid fa-envelope"></i></div>
                                        <span class="text-xs font-bold text-slate-700">Email Logs</span>
                                    </a>
                                </div>
                            </div>

                            <!-- Integration Grid -->
                            <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
                                <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-6">Integration Hub</h4>
                                <div class="grid grid-cols-1 gap-3">
                                    <a href="javascript:switchSection('smtp_settings')" class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl hover:bg-blue-50 hover:border-blue-200 border border-transparent transition-all group">
                                        <div class="flex items-center gap-4">
                                            <i class="fa-solid fa-at text-slate-400 group-hover:text-blue-500"></i>
                                            <span class="text-xs font-bold text-slate-700">SMTP Settings</span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                                    </a>
                                    <a href="javascript:switchSection('clinic_data')" class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl hover:bg-teal-50 hover:border-teal-200 border border-transparent transition-all group">
                                        <div class="flex items-center gap-4">
                                            <i class="fa-solid fa-hospital text-slate-400 group-hover:text-teal-500"></i>
                                            <span class="text-xs font-bold text-slate-700">Clinic Profile</span>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                                    </a>
                                    <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-transparent">
                                        <div class="flex items-center gap-4">
                                            <i class="fa-solid fa-server text-slate-400"></i>
                                            <span class="text-xs font-bold text-slate-700">PHP Version</span>
                                        </div>
                                        <span class="text-xs font-mono font-black text-slate-400"><?= phpversion() ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Git History -->
                        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden mb-12">
                            <div class="px-6 py-4 bg-slate-50 border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider">Git Update History</h3>
                                <i class="fa-solid fa-clock-rotate-left text-slate-300"></i>
                            </div>
                            <div class="max-h-[300px] overflow-y-auto divide-y divide-gray-50">
                                <?php if (empty($gitPullLogs)): ?>
                                    <div class="py-12 text-center text-slate-400 text-xs font-bold">ไม่พบประวัติการอัปเดต</div>
                                <?php else: ?>
                                    <?php foreach ($gitPullLogs as $log): 
                                        $isOk = $log['status'] === 'success';
                                        $dt = new DateTime($log['created_at']);
                                    ?>
                                        <div class="p-4 flex items-center justify-between hover:bg-slate-50 transition-all">
                                            <div class="flex items-center gap-4">
                                                <div class="w-2 h-2 rounded-full <?= $isOk ? 'bg-green-500' : 'bg-red-500' ?>"></div>
                                                <div>
                                                    <div class="text-xs font-bold text-slate-800"><?= htmlspecialchars($log['message'] ?? 'Git Pull') ?></div>
                                                    <div class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($log['triggered_by']) ?> • <?= $dt->format('d M Y H:i') ?></div>
                                                </div>
                                            </div>
                                            <?php if ($log['detail']): ?>
                                                <button onclick="Swal.fire({title:'Update Detail', html:'<pre style=\'text-align:left;font-size:11px;background:#f8fafc;padding:15px;border-radius:10px;font-family:monospace;overflow:auto;max-height:400px\'>' + <?= json_encode(htmlspecialchars($log['detail'])) ?> + '</pre>'})" 
                                                        class="text-[9px] font-black text-blue-500 uppercase tracking-widest hover:underline">Details</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
                </div>

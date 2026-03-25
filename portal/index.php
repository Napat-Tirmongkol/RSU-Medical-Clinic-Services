<?php
// portal/index.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจสอบสิทธิ์

$pdo = db();
// 1. ดึงสถิติต้นทาง
$totalUsers = $pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
$totalAdmins = $pdo->query("SELECT COUNT(*) FROM sys_admins")->fetchColumn();
$activeCampaigns = $pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();

// --- SSO Logic for Legacy e_Borrow ---
// ให้แอดมินที่ล็อกอินผ่าน Portal มีสิทธิ์เข้าใช้งาน e_Borrow อัตโนมัติ (ถ้ามีรายชื่อใน sys_staff)
if (!isset($_SESSION['user_id']) && isset($_SESSION['admin_username'])) {
    $staff = $pdo->prepare("SELECT id, full_name, role FROM sys_staff WHERE username = :uname LIMIT 1");
    $staff->execute([':uname' => $_SESSION['admin_username']]);
    $staffData = $staff->fetch();
    if ($staffData) {
        $_SESSION['user_id'] = $staffData['id'];
        $_SESSION['full_name'] = $staffData['full_name'];
        $_SESSION['role'] = $staffData['role'];
    } else {
        // กรณีไม่มีรายชื่อพนักงาน แต่เป็นแอดมินสูงสุด ให้ใช้สิทธิ์แอดมินจำลอง
        $_SESSION['user_id'] = $_SESSION['admin_id'] ?? 999;
        $_SESSION['full_name'] = $_SESSION['admin_username'] ?? 'Administrator';
        $_SESSION['role'] = 'admin';
    }
}
// ------------------------------------

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hub Portal - RSU Healthcare Services</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- UI Framework -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'Prompt', 'sans-serif'],
                        prompt: ['Prompt', 'sans-serif'],
                    },
                    colors: {
                        primary: '#0052CC',
                        secondary: '#0747A6',
                        accent: '#FFAB00',
                    }
                }
            }
        }
    </script>
    
    <style>
        body {
            background-color: #f8fafc;
            background-image: 
                radial-gradient(at 0% 0%, rgba(0, 82, 204, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(255, 171, 0, 0.05) 0px, transparent 50%);
            min-h-screen;
        }
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px -10px rgba(0, 82, 204, 0.2);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade {
            animation: fadeIn 0.6s ease-out forwards;
        }
    </style>
</head>
<body class="p-4 md:p-8">

    <!-- Top Navigation -->
    <header class="max-w-7xl mx-auto flex flex-col md:flex-row md:justify-between md:items-center gap-6 mb-12 animate-fade">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-primary rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg shadow-blue-200">
                <i class="fa-solid fa-square-poll-vertical"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight uppercase">Hub Portal</h1>
                <p class="text-xs text-primary font-bold tracking-widest uppercase opacity-70">RSU Healthcare Services</p>
            </div>
        </div>
        
        <div class="flex items-center gap-4 glass px-6 py-3 rounded-[24px]">
            <div class="w-10 h-10 bg-blue-100 text-primary rounded-full flex items-center justify-center font-bold">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div class="text-right">
                <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Signed in as</p>
                <p class="text-sm font-bold text-gray-800"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></p>
            </div>
            <div class="h-6 w-px bg-gray-200 mx-2"></div>
            <a href="../admin/logout.php" class="text-gray-400 hover:text-red-500 transition-colors">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto space-y-12">
        
        <!-- Welcome Hero -->
        <section class="animate-fade" style="animation-delay: 0.1s;">
            <div class="bg-primary bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] p-10 md:p-14 rounded-[48px] text-white relative overflow-hidden shadow-2xl shadow-blue-200">
                <div class="relative z-10 max-w-2xl">
                    <h2 class="text-4xl md:text-5xl font-black mb-6 leading-tight">Welcome back! <br><span class="text-accent underline decoration-4 underline-offset-8">Single Access</span> to all apps.</h2>
                    <p class="text-blue-100 text-lg mb-8 font-medium leading-relaxed opacity-90">จุดศูนย์กลางการจัดการระบบหลังบ้านทั้งหมดของ RSU Medical Analytics & Healthcare Services จัดการผู้ใช้งานและดูภาพรวมได้ที่นี่ที่เดียว</p>
                    
                    <div class="flex flex-wrap gap-4">
                        <div class="bg-white/10 backdrop-blur-md px-6 py-4 rounded-[28px] border border-white/20">
                            <p class="text-blue-100 text-xs font-black uppercase tracking-wider mb-1">Total Hub Users</p>
                            <h4 class="text-3xl font-black"><?= number_format($totalUsers) ?></h4>
                        </div>
                        <div class="bg-white/10 backdrop-blur-md px-6 py-4 rounded-[28px] border border-white/20">
                            <p class="text-blue-100 text-xs font-black uppercase tracking-wider mb-1">Active Projects</p>
                            <h4 class="text-3xl font-black">2</h4>
                        </div>
                        <div class="bg-white/10 backdrop-blur-md px-6 py-4 rounded-[28px] border border-white/20">
                            <p class="text-blue-100 text-xs font-black uppercase tracking-wider mb-1">System Health</p>
                            <h4 class="text-3xl font-black text-green-300">A+</h4>
                        </div>
                    </div>
                </div>
                
                <!-- Decoration Icons -->
                <i class="fa-solid fa-microchip absolute -bottom-10 -right-10 text-[250px] text-white/5 rotate-12"></i>
            </div>
        </section>

        <!-- Project & Identity Grid -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-8 animate-fade" style="animation-delay: 0.2s;">
            
            <!-- IDENTITY CARD (Portal Core) -->
            <div class="lg:col-span-1 bg-white p-8 rounded-[40px] shadow-sm border border-gray-100 flex flex-col justify-between card-hover transition-all duration-300">
                <div>
                    <div class="w-14 h-14 bg-accent/10 text-accent rounded-3xl flex items-center justify-center text-2xl mb-8">
                        <i class="fa-solid fa-id-card-clip"></i>
                    </div>
                    <h3 class="text-2xl font-black text-gray-900 mb-4 tracking-tight">User Directory</h3>
                    <p class="text-gray-500 text-sm leading-relaxed mb-8">ฐานข้อมูลศูนย์กลางผู้ใช้งานทั้งหมด (Master List) ไม่ว่าจะเป็นนักศึกษา บุคลากร หรือบุคคลภายนอก</p>
                    
                    <ul class="space-y-4 mb-4">
                        <li class="flex items-center gap-3 text-sm font-semibold text-gray-600">
                            <i class="fa-solid fa-circle-check text-green-500"></i> จัดการรายชื่อ (<?= number_format($totalUsers) ?> ชีวิต)
                        </li>
                        <li class="flex items-center gap-3 text-sm font-semibold text-gray-600">
                            <i class="fa-solid fa-circle-check text-green-500"></i> จัดการสิทธิ์แอดมิน (<?= number_format($totalAdmins) ?> บัญชี)
                        </li>
                    </ul>
                </div>
                <div class="flex gap-3">
                    <a href="../sys_admin/users.php" class="flex-1 text-center bg-gray-50 hover:bg-gray-100 py-4 rounded-2xl text-sm font-black text-gray-700 transition-all active:scale-95">Users</a>
                    <a href="../sys_admin/manage_admins.php" class="flex-1 text-center bg-accent text-white py-4 rounded-2xl text-sm font-black shadow-lg shadow-yellow-100 hover:brightness-95 transition-all active:scale-95">Admins</a>
                </div>
            </div>

            <!-- E-CAMPAIGN V2 -->
            <div class="lg:col-span-2 bg-white p-10 rounded-[40px] shadow-sm border border-gray-100 flex flex-col md:flex-row gap-10 card-hover transition-all duration-300">
                <div class="flex-1">
                    <div class="w-14 h-14 bg-blue-100 text-primary rounded-3xl flex items-center justify-center text-2xl mb-8 group-hover:bg-primary transition-all">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div class="inline-block px-3 py-1 bg-green-50 text-green-600 rounded-full text-[10px] font-black uppercase tracking-widest mb-4 border border-green-100">Live & Running</div>
                    <h3 class="text-3xl font-black text-gray-900 mb-6 tracking-tight">e-Campaign V2</h3>
                    <p class="text-gray-500 text-sm leading-relaxed mb-8">ระบบจัดการแคมเปญ งานอบรม งานสแกน และการลงทะเบียนฉีดวัคซีน ครอบคลุมการจัดการรอบเวลาและ QR Check-in แบบ Real-time</p>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 bg-gray-50 rounded-2xl">
                            <h5 class="text-gray-400 text-[10px] font-bold uppercase tracking-widest">Active Camp</h5>
                            <p class="text-xl font-black text-gray-800"><?= $activeCampaigns ?></p>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-2xl">
                            <h5 class="text-gray-400 text-[10px] font-bold uppercase tracking-widest">Status</h5>
                            <p class="text-xl font-black text-green-500">Active</p>
                        </div>
                    </div>
                </div>
                <div class="w-full md:w-56 shrink-0 flex flex-col gap-4 justify-end">
                    <a href="../admin/index.php" class="w-full bg-primary text-white py-6 rounded-[24px] text-center font-black text-lg shadow-xl shadow-blue-200 hover:bg-secondary transition-all active:scale-95">
                        Open App <i class="fa-solid fa-arrow-right-long ml-2"></i>
                    </a>
                </div>
            </div>

            <!-- E-BORROW (ACTIVE) -->
            <div class="lg:col-span-3 bg-white p-8 rounded-[40px] shadow-sm border border-gray-100 flex items-center justify-between group transition-all duration-300 hover:shadow-xl hover:shadow-primary/5 hover:-translate-y-1">
                <div class="flex items-center gap-6">
                    <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-3xl flex items-center justify-center text-2xl group-hover:bg-blue-600 group-hover:text-white transition-all">
                        <i class="fa-solid fa-toolbox"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-3">
                            <h3 class="text-xl font-black text-gray-900 group-hover:text-blue-600 tracking-tight">e-Borrow & Inventory</h3>
                        </div>
                        <p class="text-gray-500 text-xs mt-1">ระบบยืม-คืนอุปกรณ์ทางการแพทย์และเวชภัณฑ์ (RSU Healthcare Services)</p>
                    </div>
                </div>
                <a href="../archive/e_Borrow/admin/index.php" class="px-8 py-4 bg-gray-900 text-white rounded-2xl text-sm font-black hover:bg-blue-600 hover:shadow-lg hover:shadow-blue-200 transition-all">
                    เข้าใช้งานระบบ
                </a>
            </div>

        </section>

    </main>

    <footer class="max-w-7xl mx-auto mt-20 text-center pb-12 animate-fade" style="animation-delay: 0.3s;">
        <p class="text-[10px] text-gray-400 font-bold tracking-[0.2em] uppercase mb-4">Centralized Command Center &copy; 2026 RSU Medical Clinic</p>
        <div class="flex justify-center gap-6 text-gray-300">
             <i class="fa-solid fa-shield-halved text-primary/20 text-3xl"></i>
        </div>
    </footer>

</body>
</html>

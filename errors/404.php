<?php http_response_code(404); ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>404 — ไม่พบหน้าที่ต้องการ | RSU Medical Hub</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_Regular.ttf') format('truetype'); }
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight:bold; }
        body { font-family:'RSU', sans-serif; background-color: #F8FAFF; }
        .premium-gradient { background: linear-gradient(135deg, #2e9e63 0%, #10b981 100%); }
        .glass-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-[#f0f4f9]">
    <div class="w-full max-w-md text-center animate-in zoom-in fade-in duration-700">
        <div class="glass-card rounded-[3.5rem] p-12 shadow-[0_30px_80px_rgba(0,0,0,0.06)] relative overflow-hidden">
            <!-- Decorative circles -->
            <div class="absolute -right-20 -top-20 w-48 h-48 bg-green-50 rounded-full blur-3xl opacity-30"></div>
            <div class="absolute -left-20 -bottom-20 w-48 h-48 bg-emerald-50 rounded-full blur-3xl opacity-30"></div>
            
            <div class="relative z-10">
                <div class="mb-6">
                    <span class="px-4 py-1.5 bg-green-50 text-green-600 rounded-full text-[10px] font-black uppercase tracking-[0.2em] border border-green-100">Error 404</span>
                </div>
                
                <h1 class="text-[8rem] font-black text-slate-100 leading-none mb-4 select-none tracking-tighter drop-shadow-sm">404</h1>
                
                <div class="w-20 h-20 premium-gradient rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-xl shadow-green-100 -mt-16 relative z-20">
                    <i class="fa-solid fa-compass text-white text-3xl animate-pulse"></i>
                </div>
                
                <h2 class="text-3xl font-black text-slate-900 mb-4 tracking-tight">ไม่พบหน้าที่ต้องการ</h2>
                <p class="text-slate-400 text-sm font-bold mb-10 leading-relaxed px-4">
                    ขออภัย ไม่พบหน้าที่คุณกำลังมองหา<br>
                    อาจถูกย้าย ลบออก หรือ URL ไม่ถูกต้องครับ
                </p>
                
                <div class="space-y-4">
                    <a href="javascript:history.back()" 
                       class="flex items-center justify-center gap-3 w-full py-5 premium-gradient text-white font-black rounded-2xl shadow-lg shadow-green-100 transition-all active:scale-95 text-sm uppercase tracking-wider">
                        <i class="fa-solid fa-arrow-left"></i>
                        กลับหน้าก่อนหน้า
                    </a>
                    
                    <a href="/e-campaignv2/user/hub.php" 
                       class="flex items-center justify-center gap-3 w-full py-5 bg-white text-slate-400 font-black rounded-2xl border border-slate-100 hover:bg-slate-50 transition-all active:scale-95 text-sm uppercase tracking-wider">
                        <i class="fa-solid fa-house"></i>
                        ไปหน้าหลัก
                    </a>
                </div>
                
                <div class="mt-10 pt-8 border-t border-slate-100 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-circle-info text-green-300 text-[10px]"></i>
                    <span class="text-slate-300 text-[10px] font-black uppercase tracking-widest">RSU Medical Clinic Services</span>
                </div>
            </div>
        </div>
        
        <p class="mt-8 text-slate-300 text-[10px] font-black uppercase tracking-[0.3em]">System Health: Online</p>
    </div>
</body>
</html>

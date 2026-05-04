<?php http_response_code(503); header('Retry-After: 300'); ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>503 — ระบบอยู่ระหว่างปรับปรุง</title>
  <link rel="icon" href="data:,">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Sarabun', sans-serif;background:linear-gradient(135deg,#0052CC 0%,#0070f3 100%);min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{background:rgba(255,255,255,.97);border-radius:32px;box-shadow:0 20px 60px rgba(0,0,0,.2);padding:48px 40px;max-width:420px;width:100%;text-align:center;animation:up .5s cubic-bezier(.16,1,.3,1) both}
    @keyframes up{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.7;transform:scale(.95)}}
    .code-badge{display:inline-flex;align-items:center;gap:8px;background:#EDE9FE;color:#5B21B6;font-weight:800;font-size:13px;padding:6px 16px;border-radius:999px;letter-spacing:.05em;margin-bottom:20px}
    .icon-wrap{width:88px;height:88px;background:linear-gradient(135deg,#EDE9FE,#F5F3FF);border-radius:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:40px;color:#7C3AED;animation:pulse 2s ease-in-out infinite}
    h1{font-size:22px;font-weight:800;color:#111827;margin-bottom:8px}
    p{font-size:14px;color:#6B7280;line-height:1.7;margin-bottom:24px}
    .progress-wrap{background:#F3F4F6;border-radius:999px;height:6px;overflow:hidden;margin-bottom:32px}
    .progress-bar{height:100%;width:60%;background:linear-gradient(90deg,#7C3AED,#A78BFA);border-radius:999px;animation:progress 2.5s ease-in-out infinite alternate}
    @keyframes progress{from{width:30%}to{width:85%}}
    .btn{display:inline-flex;align-items:center;gap:8px;background:#0052CC;color:#fff;font-weight:700;font-size:14px;padding:14px 28px;border-radius:16px;text-decoration:none;transition:.2s;box-shadow:0 4px 16px rgba(0,82,204,.25);cursor:pointer;border:none}
    .btn:hover{background:#003fa3;transform:translateY(-1px)}
    .hint{font-size:12px;color:#D1D5DB;margin-top:20px}
  </style>
</head>
<body>
  <div class="card">
    <div class="code-badge"><i class="fa-solid fa-wrench"></i> ERROR 503</div>
    <div class="icon-wrap"><i class="fa-solid fa-gear"></i></div>
    <h1>ระบบอยู่ระหว่างปรับปรุง</h1>
    <p>เราปิดปรับปรุงระบบชั่วคราว<br>เพื่อให้บริการที่ดียิ่งขึ้น กรุณากลับมาใหม่ในอีกสักครู่</p>
    <div class="progress-wrap"><div class="progress-bar"></div></div>
    <button onclick="location.reload()" class="btn"><i class="fa-solid fa-rotate-right"></i> ลองใหม่อีกครั้ง</button>
    <p class="hint">ระบบจะกลับมาใช้งานได้ในไม่ช้า ขออภัยในความไม่สะดวก</p>
  </div>
</body>
</html>

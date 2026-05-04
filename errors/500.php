<?php http_response_code(500); ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>500 — เกิดข้อผิดพลาดภายในระบบ</title>
  <link rel="icon" href="data:,">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Sarabun', sans-serif;background:#f4f7fa;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border-radius:32px;box-shadow:0 8px 48px rgba(0,0,0,.07);padding:48px 40px;max-width:420px;width:100%;text-align:center;animation:up .5s cubic-bezier(.16,1,.3,1) both}
    @keyframes up{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    @keyframes spin{to{transform:rotate(360deg)}}
    .code-badge{display:inline-flex;align-items:center;gap:8px;background:#FEE2E2;color:#991B1B;font-weight:800;font-size:13px;padding:6px 16px;border-radius:999px;letter-spacing:.05em;margin-bottom:20px}
    .icon-wrap{width:88px;height:88px;background:linear-gradient(135deg,#FEE2E2,#FFF1F2);border-radius:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:40px;color:#EF4444;position:relative}
    .icon-wrap::after{content:'';position:absolute;inset:-4px;border-radius:32px;border:2px dashed #FCA5A5;animation:spin 8s linear infinite}
    h1{font-size:22px;font-weight:800;color:#111827;margin-bottom:8px}
    p{font-size:14px;color:#6B7280;line-height:1.7;margin-bottom:32px}
    .btn{display:inline-flex;align-items:center;gap:8px;background:#0052CC;color:#fff;font-weight:700;font-size:14px;padding:14px 28px;border-radius:16px;text-decoration:none;transition:.2s;box-shadow:0 4px 16px rgba(0,82,204,.25)}
    .btn:hover{background:#003fa3;transform:translateY(-1px)}
    .btn-ghost{display:inline-flex;align-items:center;gap:6px;color:#9CA3AF;font-size:13px;font-weight:600;text-decoration:none;margin-top:16px;transition:.2s;cursor:pointer;background:none;border:none}
    .btn-ghost:hover{color:#374151}
    .actions{display:flex;flex-direction:column;align-items:center;gap:4px}
    .hint{font-size:12px;color:#D1D5DB;margin-top:24px}
  </style>
</head>
<body>
  <div class="card">
    <div class="code-badge"><i class="fa-solid fa-triangle-exclamation"></i> ERROR 500</div>
    <div class="icon-wrap"><i class="fa-solid fa-server"></i></div>
    <h1>เกิดข้อผิดพลาดภายในระบบ</h1>
    <p>ระบบเกิดข้อผิดพลาดที่ไม่คาดคิด<br>ทีมงานได้รับแจ้งแล้วและกำลังดำเนินการแก้ไข</p>
    <div class="actions">
      <button onclick="location.reload()" class="btn"><i class="fa-solid fa-rotate-right"></i> ลองใหม่อีกครั้ง</button>
      <a href="/" class="btn-ghost"><i class="fa-solid fa-house text-xs"></i> ไปหน้าแรก</a>
    </div>
    <p class="hint">หากปัญหายังคงอยู่ กรุณาติดต่อผู้ดูแลระบบ</p>
  </div>
</body>
</html>

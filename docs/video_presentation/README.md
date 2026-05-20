# Video Presentations

สคริปต์ + slide deck สำหรับสร้างวีดีโอพรีเซนต์ของ RSU Medical Clinic.
ทุกไฟล์เขียนใน Markdown ตามรูปแบบที่ Gamma / Canva / Marp / Slidev
อ่านได้ทันที — slide แยกด้วย `---` และ narration อยู่ใน blockquote
ใต้ slide

## ไฟล์ที่มี

| ไฟล์ | เนื้อหา | ความยาว | กลุ่มผู้ฟัง |
|---|---|---|---|
| `user_hub_demo.md` | ฝั่งผู้ใช้ (LINE login → hub → services → vaccine → PDPA) | ~2:45 นาที · 8 slides | Executive / Dean / committee |

## วิธีนำไปสร้างวีดีโอ (เร็วสุด 5 นาที)

### Option A — Gamma.app (แนะนำ)

1. เปิด <https://gamma.app> · login (free 100 credits)
2. New → **Import** → **Paste in text or markdown**
3. คัดลอกเนื้อหาทั้งไฟล์ `user_hub_demo.md` แล้ววาง
4. กด Continue → เลือก theme (แนะนำ theme เขียวให้ตรง brand RSU)
5. คลิก **Generate** — Gamma แปลง heading + bullet เป็นสไลด์อัตโนมัติ
6. แต่ละ slide จะมี blockquote เป็น speaker note → ใช้สำหรับ:
   - บันทึก voice-over เอง (record บน Gamma)
   - หรือ generate AI narration (premium feature)
7. Export → **MP4** (รองรับ 1080p · voice-over auto-sync)

### Option B — Canva

1. <https://canva.com> → Create design → Video presentation
2. **Magic Switch / Magic Write** → paste markdown → convert to slides
3. คลิก slide → recording mode → อ่าน narration จาก blockquote
4. Export → MP4

### Option C — Marp + ffmpeg (DIY local)

```bash
# 1. แปลง markdown → PNG ทีละ slide
npm install -g @marp-team/marp-cli
marp user_hub_demo.md --images png --output ./frames/

# 2. สร้าง MP3 จาก narration (ใช้ ElevenLabs API key)
#    (sample script ใน build_video.sh ที่จะมาทีหลัง)

# 3. รวม PNG + MP3 → MP4
ffmpeg -framerate 1/20 -i frames/slide-%d.png -i narration.mp3 \
       -c:v libx264 -tune stillimage -c:a aac -pix_fmt yuv420p \
       output.mp4
```

### Option D — Slidev

```bash
npx slidev user_hub_demo.md
# เปิด browser → record screen → voice-over manual
```

## เคล็ดลับ

- **Screenshot placeholders** — markdown มี `placeholder:*.png` ที่ต้องเอา
  screenshot จริงไปแทน:
  - `login-flow.png` — capture flow LINE login + PDPA consent
  - `profile-hub.png` — capture หน้า hub.php
  - `services-grid.png` — capture service menu
  - `vaccine-history.png` — capture vaccine list ของ test user
- **Tone narration** — speaker note เขียนแบบเป็นทางการพอเพื่อ executive
  ถ้าผู้ฟังเป็นนักศึกษา ปรับให้สบายๆ ขึ้น
- **ความยาวจริง** — Thai ~150 words/นาที · narration ทุกสไลด์รวม ~400 คำ
  → ใช้เวลาประมาณ 2:30-2:45 นาที
- **Branding** — สีหลัก brand-500 (`#2e9e63`) สำหรับ accent
  - Gamma: เลือก theme green-rich หรือ custom HEX `#2e9e63`
- **Multi-language** — ถ้าต้องทำเวอร์ชัน EN ก็แปล narration ใน blockquote
  เก็บไว้คู่กัน (script เดียว 2 ภาษา)

## Roadmap

- [ ] เพิ่ม `admin_demo.md` สำหรับด้าน admin (PDPA Audit + Vaccine catalog)
- [ ] เพิ่ม `iso_audit_demo.md` สำหรับ ISO 27001 auditor
- [ ] เพิ่ม `build_video.sh` — ffmpeg + ElevenLabs pipeline
- [ ] เพิ่ม screenshot library พร้อมในโฟลเดอร์ `screenshots/`

<?php // portal/_partials/satisfaction_modal.php ?>

<!-- ══════════════════════════════════════════════
     Satisfaction Survey Modal — Standalone Component
     Usage: include this file, then call openSurveyModal()
     ══════════════════════════════════════════════ -->

<!-- Overlay -->
<div id="surveyOverlay" aria-modal="true" role="dialog" aria-labelledby="surveyTitle" style="display:none">
  <div class="sm-backdrop" id="smBackdrop" onclick="closeSurveyModal()"></div>

  <div class="sm-modal" id="smModal">

    <!-- Decorative gradient bar -->
    <div class="sm-grad-bar"></div>

    <!-- Header -->
    <div class="sm-header">
      <div class="sm-header-left">
        <div class="sm-icon-wrap">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
          </svg>
        </div>
        <div>
          <h2 class="sm-title" id="surveyTitle">ประเมินความพึงพอใจ</h2>
          <p class="sm-subtitle">RSU Medical Clinic Services</p>
        </div>
      </div>
      <button class="sm-close-btn" onclick="closeSurveyModal()" aria-label="ปิด">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <!-- Content -->
    <div class="sm-body" id="smBody">

      <!-- Question -->
      <div class="sm-question">
        <p class="sm-q-text">คุณพึงพอใจกับระบบมากน้อยเพียงใด?</p>
      </div>

      <!-- Stars -->
      <div class="sm-stars-wrap">
        <div class="sm-stars" id="smStars">
          <?php foreach ([1,2,3,4,5] as $i): ?>
          <button class="sm-star" data-value="<?= $i ?>" onclick="setSurveyRating(<?= $i ?>)"
                  onmouseenter="hoverStar(<?= $i ?>)" onmouseleave="unhoverStar()"
                  aria-label="<?= $i ?> ดาว">
            <svg viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
          </button>
          <?php endforeach; ?>
        </div>
        <div class="sm-rating-label" id="smRatingLabel">
          <span>แตะดาวเพื่อให้คะแนน</span>
        </div>
      </div>

      <!-- Dots indicator -->
      <div class="sm-dots" id="smDots">
        <?php foreach ([1,2,3,4,5] as $i): ?>
        <div class="sm-dot" id="smDot<?= $i ?>"></div>
        <?php endforeach; ?>
      </div>

      <!-- Feedback Textarea -->
      <div class="sm-field">
        <label class="sm-label" for="smComment">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          ข้อเสนอแนะ <span class="sm-optional">(ไม่บังคับ)</span>
        </label>
        <textarea id="smComment" class="sm-textarea"
          placeholder="ข้อเสนอแนะเพิ่มเติมเพื่อพัฒนาระบบ..."
          rows="3" maxlength="500"></textarea>
        <div class="sm-char-count"><span id="smCharCount">0</span> / 500</div>
      </div>

      <!-- Submit -->
      <button class="sm-submit-btn" id="smSubmitBtn" onclick="submitSurvey()">
        <span id="smBtnContent">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          ส่งผลประเมิน
        </span>
      </button>

      <p class="sm-privacy">ข้อมูลของคุณจะถูกเก็บเป็นความลับและใช้เพื่อพัฒนาระบบเท่านั้น</p>

    </div>

    <!-- Success State (hidden) -->
    <div class="sm-success" id="smSuccess">
      <div class="sm-success-ring">
        <div class="sm-success-icon">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
      </div>
      <h3 class="sm-success-title">ขอบคุณครับ!</h3>
      <p class="sm-success-msg">ความคิดเห็นของคุณมีคุณค่าอย่างยิ่งต่อการพัฒนาระบบ</p>
      <div class="sm-success-stars" id="smSuccessStars"></div>
    </div>

  </div>
</div>

<!-- ══ Styles ══ -->
<style>
/* ── Reset & base ─────────────────────────────── */
#surveyOverlay * { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Overlay ──────────────────────────────────── */
#surveyOverlay {
  position: fixed; inset: 0; z-index: 9999;
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
}
.sm-backdrop {
  position: absolute; inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  animation: smFadeIn .2s ease;
}

/* ── Modal card ───────────────────────────────── */
.sm-modal {
  position: relative; z-index: 1;
  width: 100%; max-width: 460px;
  background: #fff;
  border-radius: 24px;
  box-shadow:
    0 0 0 1px rgba(37, 99, 235, 0.07),
    0 24px 60px -8px rgba(15, 23, 42, 0.22),
    0 8px 24px -4px rgba(15, 23, 42, 0.10);
  overflow: hidden;
  animation: smSlideUp .28s cubic-bezier(.34,1.56,.64,1);
}
.sm-grad-bar {
  height: 4px;
  background: linear-gradient(90deg, #2563EB 0%, #60A5FA 50%, #34D399 100%);
}

/* ── Header ───────────────────────────────────── */
.sm-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 22px 16px;
  border-bottom: 1px solid #F1F5F9;
}
.sm-header-left { display: flex; align-items: center; gap: 12px; }
.sm-icon-wrap {
  width: 42px; height: 42px;
  background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #2563EB;
  flex-shrink: 0;
}
.sm-title {
  font-size: 16px; font-weight: 800;
  color: #0F172A; letter-spacing: -0.3px;
  font-family: inherit;
}
.sm-subtitle {
  font-size: 11px; color: #94A3B8;
  font-weight: 500; margin-top: 1px;
  font-family: inherit;
}
.sm-close-btn {
  width: 32px; height: 32px;
  border-radius: 10px; border: 1px solid #E2E8F0;
  background: #F8FAFC; color: #94A3B8;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: all .15s ease;
  flex-shrink: 0;
}
.sm-close-btn:hover { background: #FEF2F2; color: #EF4444; border-color: #FECACA; }

/* ── Body ─────────────────────────────────────── */
.sm-body { padding: 24px 22px 22px; }

/* ── Question ─────────────────────────────────── */
.sm-question { margin-bottom: 20px; text-align: center; }
.sm-q-text {
  font-size: 14px; font-weight: 600;
  color: #334155; font-family: inherit;
}

/* ── Stars ────────────────────────────────────── */
.sm-stars-wrap { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.sm-stars {
  display: flex; gap: 6px;
  padding: 16px 20px;
  background: #F8FAFC;
  border-radius: 16px;
  border: 1px solid #F1F5F9;
}
.sm-star {
  width: 44px; height: 44px;
  background: none; border: none; cursor: pointer;
  color: #E2E8F0;
  transition: color .15s ease, transform .18s cubic-bezier(.34,1.56,.64,1);
  display: flex; align-items: center; justify-content: center;
  border-radius: 10px; padding: 4px;
}
.sm-star svg { width: 32px; height: 32px; pointer-events: none; }
.sm-star:hover,
.sm-star.sm-hovered,
.sm-star.sm-active { color: #F59E0B; }
.sm-star.sm-active { transform: scale(1.18); }
.sm-star:focus-visible { outline: 2px solid #2563EB; outline-offset: 2px; }

/* ── Rating label ─────────────────────────────── */
.sm-rating-label {
  font-size: 12px; font-weight: 600;
  color: #64748B; height: 18px;
  transition: all .2s ease;
  font-family: inherit;
}
.sm-rating-label.sm-rated { color: #2563EB; }

/* ── Dots ─────────────────────────────────────── */
.sm-dots {
  display: flex; gap: 5px; justify-content: center;
  margin: 12px 0 20px;
}
.sm-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #E2E8F0;
  transition: background .2s ease, transform .2s ease, width .2s ease;
}
.sm-dot.sm-dot-active {
  background: #2563EB;
  width: 18px; border-radius: 3px;
}

/* ── Field ────────────────────────────────────── */
.sm-field { margin-bottom: 18px; }
.sm-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; font-weight: 700;
  color: #475569; margin-bottom: 8px;
  font-family: inherit;
}
.sm-optional { font-weight: 400; color: #94A3B8; }
.sm-textarea {
  width: 100%; padding: 12px 14px;
  border: 1.5px solid #E2E8F0;
  border-radius: 14px;
  font-size: 13px; line-height: 1.6;
  color: #334155; resize: vertical;
  font-family: inherit;
  background: #FAFAFA;
  transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
  min-height: 80px;
}
.sm-textarea::placeholder { color: #CBD5E1; }
.sm-textarea:focus {
  outline: none;
  border-color: #93C5FD;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
}
.sm-char-count {
  font-size: 11px; color: #CBD5E1;
  text-align: right; margin-top: 4px;
  font-family: inherit;
}

/* ── Submit button ────────────────────────────── */
.sm-submit-btn {
  width: 100%;
  padding: 14px 20px;
  background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
  color: #fff; border: none;
  border-radius: 14px; cursor: pointer;
  font-size: 14px; font-weight: 700;
  font-family: inherit; letter-spacing: 0.2px;
  transition: all .18s ease;
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
  display: flex; align-items: center; justify-content: center;
}
#smBtnContent {
  display: flex; align-items: center; gap: 8px;
}
.sm-submit-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #1D4ED8 0%, #1E40AF 100%);
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
  transform: translateY(-1px);
}
.sm-submit-btn:active:not(:disabled) { transform: translateY(0); }
.sm-submit-btn:disabled {
  background: linear-gradient(135deg, #93C5FD 0%, #60A5FA 100%);
  box-shadow: none; cursor: not-allowed; transform: none;
}
.sm-submit-btn.sm-loading { pointer-events: none; }

/* ── Privacy ──────────────────────────────────── */
.sm-privacy {
  font-size: 10.5px; color: #CBD5E1;
  text-align: center; margin-top: 12px;
  line-height: 1.5; font-family: inherit;
}

/* ── Success state ────────────────────────────── */
.sm-success {
  display: none;
  flex-direction: column; align-items: center;
  padding: 32px 22px 36px;
  text-align: center;
  animation: smFadeIn .3s ease;
}
.sm-success-ring {
  width: 80px; height: 80px;
  border-radius: 50%;
  background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 18px;
  box-shadow: 0 0 0 8px rgba(52, 211, 153, 0.12);
  animation: smPop .4s cubic-bezier(.34,1.56,.64,1) .1s both;
}
.sm-success-icon { color: #10B981; }
.sm-success-title {
  font-size: 22px; font-weight: 800;
  color: #0F172A; margin-bottom: 8px;
  font-family: inherit; letter-spacing: -0.5px;
}
.sm-success-msg {
  font-size: 13px; color: #64748B;
  line-height: 1.6; max-width: 260px;
  font-family: inherit;
}
.sm-success-stars {
  display: flex; gap: 4px;
  margin-top: 16px; color: #F59E0B;
  font-size: 22px; letter-spacing: 2px;
}

/* ── Animations ───────────────────────────────── */
@keyframes smFadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}
@keyframes smSlideUp {
  from { opacity: 0; transform: translateY(24px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes smPop {
  from { transform: scale(0.5); opacity: 0; }
  to   { transform: scale(1);   opacity: 1; }
}
@keyframes smSpin {
  to { transform: rotate(360deg); }
}
.sm-spinner {
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: smSpin .7s linear infinite;
}

/* ── Satisfaction Modal — DARK MODE ─────────────────────────────── */
body[data-theme='dark'] .sm-modal { background:#0f172a; color:#e2e8f0; }
body[data-theme='dark'] .sm-textarea { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] .sm-textarea::placeholder { color:#475569; }
body[data-theme='dark'] .sm-textarea:focus { background:#0f172a; }
</style>

<!-- ══ Script ══ -->
<script>
(function () {
  let _rating = 0;
  const _labels = ['', 'ต้องปรับปรุง', 'พอใช้ได้', 'ปานกลาง', 'ดีมาก', 'ยอดเยี่ยม! 🎉'];

  /* ── Open / Close ── */
  window.openSurveyModal = function () {
    const el = document.getElementById('surveyOverlay');
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    _reset();
    setTimeout(() => document.getElementById('smComment').focus(), 300);
  };

  window.closeSurveyModal = function () {
    const modal = document.getElementById('smModal');
    modal.style.animation = 'smSlideUp .2s cubic-bezier(.55,.06,.68,.19) reverse both';
    setTimeout(() => {
      document.getElementById('surveyOverlay').style.display = 'none';
      modal.style.animation = '';
      document.body.style.overflow = '';
    }, 180);
  };

  /* ── Rating ── */
  window.setSurveyRating = function (val) {
    _rating = val;
    _paintStars(val, true);
    _updateDots(val);
    _updateLabel(val);
  };

  window.hoverStar = function (val) { _paintStars(val, false, true); };
  window.unhoverStar = function ()  { _paintStars(_rating, true); };

  function _paintStars(upTo, lock, hover) {
    document.querySelectorAll('.sm-star').forEach(btn => {
      const v = +btn.dataset.value;
      btn.classList.toggle('sm-active',   lock  && v <= upTo);
      btn.classList.toggle('sm-hovered', !lock  && v <= upTo);
    });
  }

  function _updateDots(val) {
    document.querySelectorAll('.sm-dot').forEach((d, i) => {
      d.classList.toggle('sm-dot-active', i < val);
    });
  }

  function _updateLabel(val) {
    const el = document.getElementById('smRatingLabel');
    el.innerHTML = `<span>${_labels[val]}</span>`;
    el.classList.toggle('sm-rated', val > 0);
  }

  /* ── Char counter ── */
  document.addEventListener('DOMContentLoaded', function () {
    const ta = document.getElementById('smComment');
    const cc = document.getElementById('smCharCount');
    if (ta && cc) {
      ta.addEventListener('input', () => { cc.textContent = ta.value.length; });
    }

    /* Close on Escape */
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && document.getElementById('surveyOverlay').style.display !== 'none') {
        closeSurveyModal();
      }
    });
  });

  /* ── Submit ── */
  window.submitSurvey = function () {
    if (_rating === 0) {
      _shakeStars();
      return;
    }

    const btn     = document.getElementById('smSubmitBtn');
    const comment = document.getElementById('smComment').value.trim();

    /* Loading state */
    btn.disabled = true;
    btn.classList.add('sm-loading');
    document.getElementById('smBtnContent').innerHTML =
      '<div class="sm-spinner"></div><span>กำลังส่ง...</span>';

    const fd = new FormData();
    fd.append('rating',  _rating);
    fd.append('comment', comment);
    fd.append('context', window.location.pathname);

    fetch('../admin/ajax/ajax_save_survey.php', { method: 'POST', body: fd })
      .then(d => {
        if (d.ok) { _showSuccess(); setTimeout(closeSurveyModal, 2000); }
        else { btn.disabled = false; btn.classList.remove('sm-loading'); }
      })
      .catch(() => { btn.disabled = false; btn.classList.remove('sm-loading'); });
  };

  function _showSuccess() {
    document.getElementById('smBody').style.display = 'none';
    const s = document.getElementById('smSuccess');
    s.style.display = 'flex';

    /* Render selected stars */
    const starsEl = document.getElementById('smSuccessStars');
    starsEl.innerHTML = Array.from({ length: 5 }, (_, i) =>
      `<span style="color:${i < _rating ? '#F59E0B' : '#E2E8F0'}">★</span>`
    ).join('');
  }

  function _shakeStars() {
    const el = document.getElementById('smStars');
    el.style.animation = 'none';
    el.style.transform = 'translateX(6px)';
    let n = 0;
    const iv = setInterval(() => {
      el.style.transform = n % 2 ? 'translateX(6px)' : 'translateX(-6px)';
      if (++n > 5) { clearInterval(iv); el.style.transform = ''; }
    }, 60);

    const lbl = document.getElementById('smRatingLabel');
    lbl.innerHTML = '<span style="color:#EF4444">กรุณาเลือกคะแนนก่อนส่ง</span>';
  }

  function _reset() {
    _rating = 0;
    document.querySelectorAll('.sm-star').forEach(b => {
      b.classList.remove('sm-active', 'sm-hovered');
    });
    document.querySelectorAll('.sm-dot').forEach(d => d.classList.remove('sm-dot-active'));
    document.getElementById('smRatingLabel').innerHTML = '<span>แตะดาวเพื่อให้คะแนน</span>';
    document.getElementById('smRatingLabel').classList.remove('sm-rated');
    document.getElementById('smComment').value = '';
    document.getElementById('smCharCount').textContent = '0';
    document.getElementById('smBtnContent').innerHTML =
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>ส่งผลประเมิน';
    document.getElementById('smSubmitBtn').disabled = false;
    document.getElementById('smSubmitBtn').classList.remove('sm-loading');
    document.getElementById('smBody').style.display = 'block';
    document.getElementById('smSuccess').style.display = 'none';
  }
})();
</script>

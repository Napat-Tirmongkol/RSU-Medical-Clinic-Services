<?php
declare(strict_types=1);

/**
 * Shared footer
 * Usage:
 * require_once __DIR__ . '/footer.php';
 * render_footer();
 */
function render_footer(): void {
  // คำนวณ path ของ API endpoint สัมพัทธ์กับหน้าปัจจุบัน
  $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
  $depth = max(0, substr_count(trim($scriptDir, '/'), '/'));
  $jsApiEndpoint = str_repeat('../', $depth) . 'api/log_js_error.php';

  // Bottom nav — แสดงเฉพาะหน้า user ที่ login แล้ว
  $showNav = !empty($_SESSION['evax_student_id'])
    && strpos($_SERVER['SCRIPT_NAME'] ?? '', '/user/') !== false;

  $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
  $navItems = [
    ['file' => 'hub.php',              'icon' => 'fa-solid fa-house',          'label' => 'หน้าหลัก'],
    ['file' => 'booking_campaign.php', 'icon' => 'fa-solid fa-syringe',        'label' => 'จองคิว'],
    ['file' => 'my_bookings.php',      'icon' => 'fa-solid fa-calendar-check', 'label' => 'นัดหมาย'],
    ['file' => 'profile.php',          'icon' => 'fa-solid fa-user',           'label' => 'โปรไฟล์'],
  ];
  ?>
      </main>

  <?php if ($showNav): ?>
  <!-- ── Bottom Navigation ────────────────────────────────────────────────── -->
  <nav class="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[448px] bg-white border-t border-[#eef2f6] flex z-[999] rounded-t-[20px] shadow-[0_-5px_20px_rgba(0,0,0,0.04)]"
       style="padding-bottom:env(safe-area-inset-bottom,0)">
    <?php foreach ($navItems as $item):
      $active = ($currentPage === $item['file']);
    ?>
    <a href="<?= $item['file'] ?>"
       class="flex-1 flex flex-col items-center gap-[3px] pt-2.5 pb-2 no-underline <?= $active ? 'text-[#0052CC]' : 'text-slate-400' ?>">
      <i class="<?= $item['icon'] ?> text-[18px] <?= $active ? '[filter:drop-shadow(0_0_4px_rgba(0,82,204,.35))]' : '' ?>"></i>
      <span class="text-[10px] <?= $active ? 'font-extrabold' : 'font-semibold' ?>"><?= $item['label'] ?></span>
      <?php if ($active): ?><span class="w-[18px] h-[3px] bg-[#0052CC] rounded-sm mt-px"></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <!-- bottom spacer so content isn't hidden behind nav -->
  <div class="h-16"></div>
  <?php endif; ?>

      <script>
        /* ── JS Error Tracker ─────────────────────────────────── */
        (function () {
          var ENDPOINT = '<?= htmlspecialchars($jsApiEndpoint, ENT_QUOTES) ?>';
          var MAX      = 10;   // สูงสุดกี่ error ต่อหน้า
          var sent     = 0;
          var seen     = {};   // dedup: key → true

          function send(data) {
            if (sent >= MAX) return;
            var key = (data.message + '|' + data.source).slice(0, 120);
            if (seen[key]) return;
            seen[key] = true;
            sent++;
            var blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
            if (navigator.sendBeacon) {
              navigator.sendBeacon(ENDPOINT, blob);
            } else {
              fetch(ENDPOINT, { method: 'POST', body: blob, keepalive: true }).catch(function () {});
            }
          }

          // 1. Runtime errors (window.onerror)
          window.onerror = function (msg, src, line, col, err) {
            send({
              level:   'error',
              message: String(msg),
              source:  (src || 'unknown') + ':' + line + ':' + col,
              stack:   err && err.stack ? err.stack : '',
              url:     location.href
            });
            return false;
          };

          // 2. Unhandled Promise rejections
          window.addEventListener('unhandledrejection', function (e) {
            var reason = e.reason;
            send({
              level:   'error',
              message: 'UnhandledRejection: ' + (reason instanceof Error ? reason.message : String(reason)),
              source:  'promise',
              stack:   reason instanceof Error ? (reason.stack || '') : '',
              url:     location.href
            });
          });

          // 3. console.error override
          var _ce = console.error.bind(console);
          console.error = function () {
            _ce.apply(console, arguments);
            var args = Array.prototype.slice.call(arguments);
            var msg  = args.map(function (a) {
              if (a instanceof Error) return a.message;
              try { return typeof a === 'object' ? JSON.stringify(a) : String(a); } catch (e) { return String(a); }
            }).join(' ');
            send({
              level:   'error',
              message: '[console.error] ' + msg,
              source:  'console',
              stack:   args[0] instanceof Error ? (args[0].stack || '') : '',
              url:     location.href
            });
          };
        })();
        /* ── End JS Error Tracker ─────────────────────────────── */

        document.addEventListener('DOMContentLoaded', () => {
          const loader = document.getElementById('page-loader');
          if (!loader) return;

          window.addEventListener('load', () => {
            setTimeout(() => {
              loader.classList.add('opacity-0');
              setTimeout(() => { loader.style.display = 'none'; }, 300);
            }, 400);
          });

          window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
              loader.classList.add('opacity-0');
              loader.style.display = 'none';
            }
          });

          window.addEventListener('beforeunload', () => {
            loader.style.display = 'flex';
            requestAnimationFrame(() => { loader.classList.remove('opacity-0'); });
          });
        });
      </script>
      </body>
  </html>
  <?php
}
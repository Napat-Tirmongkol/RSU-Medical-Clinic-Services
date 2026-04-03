<?php
// admin/includes/footer.php
$_scriptDir   = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$_depth       = max(0, substr_count(trim($_scriptDir, '/'), '/'));
$_jsEndpoint  = str_repeat('../', $_depth) . 'api/log_js_error.php';
?>
        </div>
    </main>

    <script>
    /* ── JS Error Tracker (admin) ─────────────────────────────── */
    (function () {
      var ENDPOINT = '<?= htmlspecialchars($_jsEndpoint, ENT_QUOTES) ?>';
      var MAX = 10, sent = 0, seen = {};
      function send(data) {
        if (sent >= MAX) return;
        var key = (data.message + '|' + data.source).slice(0, 120);
        if (seen[key]) return;
        seen[key] = true; sent++;
        var blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
        if (navigator.sendBeacon) { navigator.sendBeacon(ENDPOINT, blob); }
        else { fetch(ENDPOINT, { method: 'POST', body: blob, keepalive: true }).catch(function(){}); }
      }
      window.onerror = function (msg, src, line, col, err) {
        send({ level:'error', message:String(msg), source:(src||'unknown')+':'+line+':'+col, stack:err&&err.stack?err.stack:'', url:location.href });
        return false;
      };
      window.addEventListener('unhandledrejection', function (e) {
        var r = e.reason;
        send({ level:'error', message:'UnhandledRejection: '+(r instanceof Error?r.message:String(r)), source:'promise', stack:r instanceof Error?(r.stack||''):'', url:location.href });
      });
      var _ce = console.error.bind(console);
      console.error = function () {
        _ce.apply(console, arguments);
        var args = Array.prototype.slice.call(arguments);
        var msg = args.map(function(a){ if(a instanceof Error) return a.message; try{return typeof a==='object'?JSON.stringify(a):String(a);}catch(e){return String(a);} }).join(' ');
        send({ level:'error', message:'[console.error] '+msg, source:'console', stack:args[0] instanceof Error?(args[0].stack||''):'', url:location.href });
      };
    })();
    /* ── End JS Error Tracker ─────────────────────────────────── */
    </script>
</body>
</html>

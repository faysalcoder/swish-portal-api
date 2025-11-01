<?php
// public/reset_password.php (dev, defensive)
$token = $_GET['token'] ?? '';
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reset password</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:16px;max-width:720px} label{display:block;margin-top:8px}</style>
</head>
<body>
  <h3>Reset password</h3>
  <?php if (!$token): ?>
    <p>Missing token.</p>
  <?php else: ?>
    <form id="f">
      <input type="hidden" id="token" value="<?= htmlspecialchars($token) ?>" />
      <div><label>New password</label><input id="password" type="password" required minlength="6"></div>
      <div><label>Confirm password</label><input id="password2" type="password" required minlength="6"></div>
      <div style="margin-top:10px"><button type="submit">Set new password</button></div>
    </form>

    <div id="msg" style="margin-top:12px"></div>
    <pre id="debug" style="white-space:pre-wrap;background:#f7f7f7;padding:10px;border:1px solid #ddd;margin-top:12px;display:none;"></pre>

    <script>
    // Build API URL relative to current page so it works when app is in a subfolder
    function apiUrl(path) {
      // If reset_password.php is at /some/base/reset_password.php, we want /some/base/api/...
      // Use current location pathname directory (remove last segment)
      const pathParts = window.location.pathname.split('/');
      pathParts.pop(); // remove reset_password.php (or trailing empty)
      const base = pathParts.join('/') + '/'; // e.g. '/swish-portal-api/public/'
      return base + path.replace(/^\/+/, ''); // ensures no duplicate slashes
    }

    async function safeJson(res){
      const ct = res.headers.get('content-type') || '';
      if (ct.indexOf('application/json') !== -1) {
        return res.json();
      }
      const text = await res.text();
      return { __non_json: true, body: text, status: res.status, statusText: res.statusText };
    }

    document.getElementById('f').addEventListener('submit', async function(e){
      e.preventDefault();
      const token = document.getElementById('token').value;
      const p1 = document.getElementById('password').value;
      const p2 = document.getElementById('password2').value;
      const msg = document.getElementById('msg');
      const debug = document.getElementById('debug');
      debug.style.display='none';
      msg.innerText='';

      if (p1 !== p2) { msg.style.color='red'; msg.innerText = 'Passwords do not match'; return; }

      try {
        // NO leading slash — use relative path builder so subfolder works
        const url = apiUrl('api/v1/auth/reset-password');
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: token, password: p1 })
        });

        const j = await safeJson(res);

        if (j && j.__non_json) {
          debug.style.display = 'block';
          debug.textContent = 'Server returned non-JSON (' + j.status + ' ' + j.statusText + ")\n\n" + j.body.slice(0, 4000);
          msg.style.color = 'red';
          msg.innerText = 'Server returned non-JSON response. See debug box below for details.';
          return;
        }

        if (j.success) {
          msg.style.color='green';
          msg.innerText = j.message || 'Password updated';
        } else {
          msg.style.color='red';
          msg.innerText = j.message || 'Error';
          if (j.error) {
            debug.style.display='block';
            debug.textContent = 'Error details:\n' + JSON.stringify(j.error, null, 2);
          }
        }
      } catch (err) {
        msg.style.color='red';
        msg.innerText = 'Network error: ' + (err.message || err);
        debug.style.display = 'block';
        debug.textContent = String(err);
      }
    });
    </script>
  <?php endif; ?>
</body>
</html>

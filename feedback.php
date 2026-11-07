<?php
// "Write to the author" form, answered by the site itself so it works wherever r2fel.com opens.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
function out($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, array('ok' => false));
$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) out(400, array('ok' => false));
if (!empty($d['site'])) out(200, array('ok' => true)); // honeypot: a bot filled the hidden field
$msg = trim(isset($d['message']) ? (string)$d['message'] : '');
$who = trim(isset($d['contact']) ? (string)$d['contact'] : '');
if (mb_strlen($msg, 'UTF-8') < 3) out(400, array('ok' => false));
$msg = mb_substr($msg, 0, 4000, 'UTF-8');
$who = mb_substr($who, 0, 200, 'UTF-8');
$lang = (isset($d['lang']) && $d['lang'] === 'ru') ? 'ru' : 'en';

// At most 5 messages per address per hour; only a hash of the address is kept
$dir = dirname(__FILE__) . '/../feedback-data';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$f = $dir . '/rate-' . sha1($_SERVER['REMOTE_ADDR'] . 'r2fel');
$hits = array();
if (is_file($f)) foreach (explode("\n", trim(file_get_contents($f))) as $t) if ($t !== '' && (int)$t > time() - 3600) $hits[] = (int)$t;
if (count($hits) >= 5) out(429, array('ok' => false));
$hits[] = time();
@file_put_contents($f, implode("\n", $hits));

$line = date('Y-m-d H:i:s') . " UTC [" . $lang . "] " . ($who !== '' ? $who : '-') . "\n" . $msg . "\n\n";
@file_put_contents($dir . '/messages.txt', $line, FILE_APPEND);

$subject = '=?UTF-8?B?' . base64_encode('R2FEL HamLog: сообщение с сайта') . '?=';
$headers = "From: R2FEL HamLog <noreply@r2fel.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
if ($who !== '' && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $who)) $headers .= 'Reply-To: ' . $who . "\r\n";
$sent = @mail('r2fel@mail.ru', $subject, "Язык: $lang\nКонтакт: " . ($who !== '' ? $who : '—') . "\n\n" . $msg, $headers);

// Pass it on to our service, which puts a line in the owner's Telegram. The service counts five messages a day per
// address and from here every message has the server's address, so no more than four a day go on; the rest are in the mail.
$tg = false;
$cf = $dir . '/forward-' . date('Ymd');
$n = is_file($cf) ? (int)file_get_contents($cf) : 0;
if ($n < 4 && function_exists('curl_init')) {
    @file_put_contents($cf, $n + 1);
    $c = curl_init('https://r2fel-hamlog-updates.r2fel.workers.dev/feedback');
    curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => array('Content-Type: text/plain'),
        CURLOPT_POSTFIELDS => json_encode(array('message' => $msg, 'contact' => $who, 'lang' => $lang, 'site' => ''))));
    $r = curl_exec($c); $tg = ($r !== false && curl_getinfo($c, CURLINFO_HTTP_CODE) == 200); curl_close($c);
}
out(200, array('ok' => true, 'mail' => (bool)$sent, 'tg' => $tg));

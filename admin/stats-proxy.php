<?php
/* Прокси к сводке службы статистики.
 *
 * Зачем: служба живёт на workers.dev (Cloudflare), а из России этот адрес
 * не открывается ни с VPN, ни без (проверено 31.10.2026: соединение не
 * устанавливается). Сервер Beget службу достаёт за 0,3 с, поэтому админка
 * грузит сводку через этот файл, а не напрямую.
 *
 * Защита: папка admin/ закрыта Basic-паролем (.htaccess), как и вся админка.
 * Прокси ходит только на один адрес (белый список) и только по пути /s/…
 * PHP 5.6 на сервере — синтаксис без ?? и прочих новых конструкций.
 */
header('Cache-Control: no-store, max-age=0');

$HOST = 'r2fel-hamlog-updates.r2fel.workers.dev';
$u = isset($_GET['u']) ? trim($_GET['u']) : '';

$pattern = '#^https://' . preg_quote($HOST, '#') . '/s/[A-Za-z0-9_-]+$#';
if (!preg_match($pattern, $u)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px sans-serif;padding:16px;background:#17181a;color:#e8e8e8">'
       . 'Нужна ссылка вида <code>https://' . htmlspecialchars($HOST, ENT_QUOTES, 'UTF-8') . '/s/…</code>.'
       . '</body>';
    exit;
}

$ch = curl_init($u);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'R2FEL-admin-proxy/1.0');
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $code === 0 || $code >= 400) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    $why = $code === 0 ? 'сеть: ' . $err : 'код ' . $code;
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px sans-serif;padding:16px;background:#17181a;color:#e8e8e8">'
       . 'Служба статистики не ответила (' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . ').'
       . '<br>Нажмите «Обновить» через минуту; если не помогает — проверьте ссылку.'
       . '</body>';
    exit;
}

header('Content-Type: ' . ($type ? $type : 'text/html; charset=utf-8'));
echo $body;

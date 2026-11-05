<?php
// The admin page's helper on the site itself: reads and writes ../banner.json and the files in ../ads/.
// The whole admin/ folder is closed by a password (.htaccess, see scripts/publish-beget.sh); nothing here
// is reachable without it. PHP 5.6 is enough — nothing newer is used.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$root = dirname(dirname(__FILE__));
$cfg = $root . '/banner.json';
$ads = $root . '/ads';

function fail($code, $msg) { http_response_code($code); echo json_encode(array('error' => $msg)); exit; }
function ok($data) { echo json_encode($data); exit; }

$a = isset($_GET['a']) ? $_GET['a'] : '';

if ($a === 'read') {
  $t = is_file($cfg) ? file_get_contents($cfg) : '{}';
  $j = json_decode($t, true);
  ok(array('config' => $j ? $j : new stdClass()));
}

if ($a === 'write') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in) || !isset($in['phone']) || !isset($in['desktop'])) fail(400, 'bad config');
  $out = array();
  foreach (array('phone', 'desktop') as $k) {
    $b = $in[$k];
    $link = isset($b['link']) ? trim($b['link']) : '';
    if ($link !== '' && !preg_match('#^https?://#i', $link)) fail(400, 'bad link');
    $out[$k] = array(
      'on' => !empty($b['on']),
      'video' => isset($b['video']) ? (string)$b['video'] : '',
      'image' => isset($b['image']) ? (string)$b['image'] : '',
      'link' => $link,
      'seconds' => max(2, min(30, (int)(isset($b['seconds']) ? $b['seconds'] : 5))),
      'skipAfter' => max(0, min(30, (int)(isset($b['skipAfter']) ? $b['skipAfter'] : 3))),
      'skippable' => !isset($b['skippable']) || !empty($b['skippable'])
    );
  }
  $tmp = $cfg . '.tmp';
  if (file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) fail(500, 'cannot write');
  rename($tmp, $cfg);
  ok(array('ok' => true, 'config' => $out));
}

if ($a === 'upload') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');
  $key = isset($_GET['key']) ? $_GET['key'] : '';
  if ($key !== 'phone' && $key !== 'desktop') fail(400, 'bad key');
  if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail(400, 'no file or too big (' . (isset($_FILES['file']) ? $_FILES['file']['error'] : '-') . ')');
  $name = strtolower($_FILES['file']['name']);
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $allowed = array('mp4', 'webm', 'mov', 'jpg', 'jpeg', 'png', 'webp', 'gif');
  if (!in_array($ext, $allowed)) fail(400, 'file type not allowed');
  if ($_FILES['file']['size'] > 60 * 1024 * 1024) fail(400, 'too big');
  if (!is_dir($ads)) mkdir($ads, 0755, true);
  $file = $key . '-' . round(microtime(true) * 1000) . '.' . $ext;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $ads . '/' . $file)) fail(500, 'cannot save');
  chmod($ads . '/' . $file, 0644);
  ok(array('url' => 'https://r2fel.com/ads/' . $file, 'path' => 'ads/' . $file, 'video' => $ext === 'mp4' || $ext === 'webm' || $ext === 'mov'));
}

if ($a === 'delete') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');
  $in = json_decode(file_get_contents('php://input'), true);
  $p = is_array($in) && isset($in['path']) ? $in['path'] : '';
  if (!preg_match('#^ads/[a-z]+-[0-9]+\.[a-z0-9]+$#', $p)) fail(400, 'bad path');
  if (is_file($root . '/' . $p)) unlink($root . '/' . $p);
  ok(array('ok' => true));
}

fail(404, 'unknown');

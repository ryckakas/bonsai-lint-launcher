<?php

// The php -S router the tests download from. An absolute request target means the request came
// through it as a proxy, and it then answers as the origin would.

$root = $_SERVER['DOCUMENT_ROOT'];
$target = $_SERVER['REQUEST_URI'];
$path = (string) parse_url($target, PHP_URL_PATH);
file_put_contents($root . '/requests.log', json_encode([
    'target' => $target,
    'path' => $path,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
]) . "\n", FILE_APPEND | LOCK_EX);

[$route, $rest] = explode('/', ltrim($path, '/'), 2) + ['', ''];

if ($route === 'redirect') {
    [$hops, $rest] = explode('/', $rest, 2);
    header('Location: ' . ($hops > 1 ? '/redirect/' . ($hops - 1) . '/' . $rest : '/files/' . $rest), true, 302);
    return true;
}
if ($route === 'elsewhere') {
    $host = strpos($_SERVER['HTTP_HOST'], 'localhost') === 0 ? '127.0.0.1' : 'localhost';
    header('Location: http://' . $host . ':' . $_SERVER['SERVER_PORT'] . '/files/' . $rest, true, 302);
    return true;
}
if ($route === 'status') {
    http_response_code((int) explode('/', $rest, 2)[0]);
    echo 'status';
    return true;
}
$file = $root . '/files/' . $rest;
if (!is_file($file)) {
    http_response_code(404);
    echo 'not found';
    return true;
}
$size = filesize($file);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . ($route === 'short' ? $size + 100 : $size));
readfile($file);
return true;

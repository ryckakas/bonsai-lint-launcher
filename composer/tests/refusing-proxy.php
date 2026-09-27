<?php

// A proxy that refuses every CONNECT, as one with a wrong password does: php refusing-proxy.php <port file>

$server = stream_socket_server('tcp://127.0.0.1:0');
file_put_contents($argv[1], substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1));
while ($client = @stream_socket_accept($server, 30)) {
    while (($line = fgets($client)) !== false && trim($line) !== '') {
    }
    fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic\r\n\r\n");
    fclose($client);
}

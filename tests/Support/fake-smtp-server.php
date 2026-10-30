<?php

/**
 * A scripted SMTP server for tests: php fake-smtp-server.php <behaviour> <capture-file>
 *
 * Listens on 127.0.0.1 (a free port, printed as "PORT <n>"), serves several connections at once
 * and appends every accepted message to the capture file as a JSON line. Behaviours:
 *   ok               accept everything
 *   auth_fail        reject the login (535 5.7.8), echoing the client's last credential line
 *   quota            refuse MAIL FROM with Gmail's daily-limit error (550 5.4.5)
 *   relay_denied     refuse MAIL FROM: sender not owned by the login (553 5.7.1)
 *   rcpt_unknown     refuse the recipient (550 5.1.1)
 *   rate_4728        refuse at the end of DATA with Gmail's domain/IP rate limit (421 4.7.28)
 *   drop_after_data  close the connection after the end of DATA without replying
 */
[, $behaviour, $capture] = $argv + [null, 'ok', '/dev/null'];

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, 'PORT '.substr($name, strrpos($name, ':') + 1)."\n");
fflush(STDOUT);

$clients = [];
$accepted = 0;
$send = fn ($sock, string $reply) => @fwrite($sock, $reply."\r\n");

while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c['sock'];
    }
    $write = $except = null;
    if (@stream_select($read, $write, $except, null) === false) {
        break;
    }
    foreach ($read as $sock) {
        if ($sock === $server) {
            if ($conn = @stream_socket_accept($server, 5)) {
                $clients[(int) $conn] = ['sock' => $conn, 'buf' => '', 'state' => 'cmd', 'from' => null, 'rcpt' => []];
                $send($conn, '220 fake.test ESMTP ready');
            }

            continue;
        }

        $id = (int) $sock;
        $chunk = @fread($sock, 65536);
        if ($chunk === '' || $chunk === false) {
            @fclose($sock);
            unset($clients[$id]);

            continue;
        }
        $clients[$id]['buf'] .= $chunk;

        while (isset($clients[$id])) {
            $c = &$clients[$id];
            if ($c['state'] === 'data') {
                $end = strpos($c['buf'], "\r\n.\r\n");
                if ($end === false) {
                    break;
                }
                $data = substr($c['buf'], 0, $end);
                $c['buf'] = substr($c['buf'], $end + 5);
                $c['state'] = 'cmd';
                if ($behaviour === 'drop_after_data') {
                    @fclose($sock);
                    unset($clients[$id]);

                    break;
                }
                if ($behaviour === 'rate_4728') {
                    $send($sock, '421 4.7.28 Our system has detected an unusual rate of unsolicited mail originating from your IP address.');

                    continue;
                }
                file_put_contents($capture, json_encode(['from' => $c['from'], 'rcpt' => $c['rcpt'], 'data' => $data])."\n", FILE_APPEND | LOCK_EX);
                $send($sock, '250 2.0.0 OK queued as FAKE'.(++$accepted));
                $c['from'] = null;
                $c['rcpt'] = [];

                continue;
            }

            $eol = strpos($c['buf'], "\r\n");
            if ($eol === false) {
                break;
            }
            $line = substr($c['buf'], 0, $eol);
            $c['buf'] = substr($c['buf'], $eol + 2);
            $verb = strtoupper(strtok($line, ' :') ?: '');

            if (in_array($c['state'], ['auth_user', 'auth_pass', 'auth_plain'], true)) {
                if ($c['state'] === 'auth_user') {
                    $c['state'] = 'auth_pass';
                    $send($sock, '334 UGFzc3dvcmQ6');
                } else {
                    $c['state'] = 'cmd';
                    $send($sock, $behaviour === 'auth_fail' ? "535 5.7.8 Username and Password not accepted (received $line)" : '235 2.7.0 Accepted');
                }

                continue;
            }

            switch ($verb) {
                case 'EHLO':
                    $send($sock, "250-fake.test\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME");
                    break;
                case 'HELO':
                    $send($sock, '250 fake.test');
                    break;
                case 'AUTH':
                    $parts = explode(' ', $line);
                    if (strtoupper($parts[1] ?? '') === 'LOGIN') {
                        $c['state'] = 'auth_user';
                        $send($sock, '334 VXNlcm5hbWU6');
                    } elseif (isset($parts[2])) {
                        $send($sock, $behaviour === 'auth_fail' ? "535 5.7.8 Username and Password not accepted (received {$parts[2]})" : '235 2.7.0 Accepted');
                    } else {
                        $c['state'] = 'auth_plain';
                        $send($sock, '334 ');
                    }
                    break;
                case 'MAIL':
                    if ($behaviour === 'quota') {
                        $send($sock, '550 5.4.5 Daily user sending limit exceeded.');
                    } elseif ($behaviour === 'relay_denied') {
                        $send($sock, '553 5.7.1 Sender address rejected: not owned by user');
                    } else {
                        preg_match('/<([^>]*)>/', $line, $m);
                        $c['from'] = $m[1] ?? null;
                        $send($sock, '250 2.1.0 OK');
                    }
                    break;
                case 'RCPT':
                    if ($behaviour === 'rcpt_unknown') {
                        $send($sock, '550 5.1.1 The email account that you tried to reach does not exist.');
                    } else {
                        preg_match('/<([^>]*)>/', $line, $m);
                        $c['rcpt'][] = $m[1] ?? null;
                        $send($sock, '250 2.1.5 OK');
                    }
                    break;
                case 'DATA':
                    $c['state'] = 'data';
                    $send($sock, '354 Go ahead');
                    break;
                case 'RSET':
                    $c['from'] = null;
                    $c['rcpt'] = [];
                    $send($sock, '250 2.0.0 OK');
                    break;
                case 'NOOP':
                    $send($sock, '250 2.0.0 OK');
                    break;
                case 'QUIT':
                    $send($sock, '221 2.0.0 closing connection');
                    @fclose($sock);
                    unset($clients[$id]);
                    break;
                default:
                    $send($sock, '502 5.5.1 Unrecognized command');
            }
        }
        unset($c);
    }
}

<?php

namespace AdvancedPaging;

use RuntimeException;

/**
 * Protected dedicated AMI configuration. Only loopback AMI is supported.
 */
final class RuntimeConfig
{
    public static function read(string $path): array
    {
        $info = @lstat($path);
        if ($info === false || ($info['mode'] & 0170000) !== 0100000
            || ($info['mode'] & 0027) !== 0 || $info['size'] > 8192
            || (function_exists('posix_geteuid') && !in_array($info['uid'], [0, posix_geteuid()], true))) {
            throw new RuntimeException('Runtime credential file is missing or not protected.');
        }
        $config = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($config)) {
            throw new RuntimeException('Invalid runtime configuration.');
        }
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '5038';
        $username = $config['username'] ?? '';
        $secret = $config['secret'] ?? '';
        if (!in_array($host, ['127.0.0.1', '::1'], true)
            || !is_string($port) || !preg_match('/^[0-9]{1,5}$/D', $port)
            || (int) $port < 1 || (int) $port > 65535
            || !is_string($username) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $username)
            || !is_string($secret) || $secret === '' || strlen($secret) > 200
            || strpbrk($secret, "\r\n") !== false) {
            throw new RuntimeException('Invalid runtime configuration.');
        }
        return ['host' => $host, 'port' => (int) $port, 'username' => $username, 'secret' => $secret];
    }

    public static function connect(array $config): AmiConnection
    {
        $host = $config['host'] === '::1' ? '[::1]' : $config['host'];
        $socket = @stream_socket_client('tcp://' . $host . ':' . $config['port'], $errno, $error, 5);
        if (!$socket) {
            throw new RuntimeException('Cannot connect to local Asterisk manager.');
        }
        stream_set_timeout($socket, 5);
        $banner = fgets($socket);
        if ($banner === false || strpos($banner, 'Asterisk Call Manager/') !== 0) {
            fclose($socket);
            throw new RuntimeException('Invalid Asterisk manager banner.');
        }
        $ami = new AmiConnection($socket);
        try {
            $ami->request([
                'Action' => 'Login', 'Username' => $config['username'],
                'Secret' => $config['secret'], 'Events' => 'on',
            ]);
            return $ami;
        } catch (\Throwable $error) {
            $ami->close();
            throw new RuntimeException('Asterisk manager login failed.', 0, $error);
        }
    }
}

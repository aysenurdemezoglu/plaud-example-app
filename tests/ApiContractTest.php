<?php

declare(strict_types=1);

namespace PlaudExample\Tests;

use PHPUnit\Framework\TestCase;

final class ApiContractTest extends TestCase
{
    private static $serverProcess;
    private static array $serverPipes = [];
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::findAvailablePort();
        $command = sprintf(
            '"%s" -S 127.0.0.1:%d -t public',
            PHP_BINARY,
            self::$port
        );
        self::$serverProcess = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$serverPipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource(self::$serverProcess)) {
            self::fail('Could not start the PHP test server.');
        }

        $deadline = microtime(true) + 5;
        do {
            $connection = @fsockopen('127.0.0.1', self::$port, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $errorOutput = isset(self::$serverPipes[2]) ? stream_get_contents(self::$serverPipes[2]) : '';
        self::tearDownAfterClass();
        self::fail('PHP test server did not become ready. ' . trim($errorOutput));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        foreach (self::$serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::$serverPipes = [];
    }

    public function testRecordingsRequireConnection(): void
    {
        [$status, $body] = $this->request('GET', '/api/recordings');

        self::assertSame(401, $status);
        self::assertSame(['message' => 'Plaud connection required.'], json_decode($body, true));
    }

    public function testConnectValidatesRequiredFields(): void
    {
        [$status, $body] = $this->request('POST', '/api/connect', 'email=&password=');

        self::assertSame(422, $status);
        self::assertSame(['message' => 'Email and password are required.'], json_decode($body, true));
    }

    /** @return array{int, string} */
    private function request(string $method, string $path, string $body = ''): array
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return [$status, is_string($response) ? $response : ''];
    }

    private static function findAvailablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            self::fail("Could not find an available port: {$errorMessage}");
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr($address, ':'), 1);
    }
}

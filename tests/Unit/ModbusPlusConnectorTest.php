<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\IndustrialProtocols\ModbusPlus\Tests\Unit;

use Erikwang2013\IndustrialProtocols\Bridge\TcpGatewayBridge;
use Erikwang2013\IndustrialProtocols\Connection\ConnectionState;
use Erikwang2013\IndustrialProtocols\ModbusPlus\ModbusPlusProtocol;
use PHPUnit\Framework\TestCase;

class ModbusPlusConnectorTest extends TestCase
{
    public function testProtocolMetadata(): void
    {
        $p = new ModbusPlusProtocol();
        $this->assertSame('modbus-plus', $p->getName());
        $this->assertSame('1.1.1', $p->getVersion());
        $this->assertSame(0, $p->getDefaultPort());
        $this->assertSame(['bridge'], $p->getSupportedVariants());
    }

    public function testCreateConnectorRequiresBridge(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BridgeInterface');
        (new ModbusPlusProtocol())->createConnector([]);
    }

    public function testLifecycleAgainstFakeGateway(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:16170');
            echo "READY\n";
            flush();
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                while (($req = fread($client, 4096)) !== false && $req !== '') {
                    $cmdLen = unpack('v', substr($req, 0, 2))[1];
                    $cmd = substr($req, 2, $cmdLen);
                    $payloadLen = unpack('V', substr($req, 2 + $cmdLen, 4))[1];
                    $data = json_decode(substr($req, 6 + $cmdLen, $payloadLen), true);
                    if ($cmd === 'read') {
                        fwrite($client, 'value:' . $data['address']);
                    } else {
                        fwrite($client, 'ok:' . $data['value']);
                    }
                }
                fclose($client);
            }
            fclose($server);
STUB, ], [1 => ['pipe', 'w']], $pipes);

        fgets($pipes[1]);

        $connector = (new ModbusPlusProtocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', 16170, 2.0),
        ]);
        $connector->connect();
        $this->assertTrue($connector->isConnected());
        $this->assertSame(ConnectionState::HEALTHY, $connector->getHealth()->state);

        $this->assertSame(['40001' => 'value:40001'], $connector->read('40001'));
        $this->assertSame(['40001' => 'ok:1234'], $connector->write('40001', [1234]));

        $connector->disconnect();
        $this->assertFalse($connector->isConnected());
        $this->assertSame(ConnectionState::CLOSED, $connector->getHealth()->state);

        proc_close($proc);
    }

    public function testGatewayTimeout(): void
    {
        // Server accepts the connection but never replies
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:16171');
            echo "READY\n";
            flush();
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                sleep(3);
                fclose($client);
            }
            fclose($server);
STUB, ], [1 => ['pipe', 'w']], $pipes);

        fgets($pipes[1]);

        $connector = (new ModbusPlusProtocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', 16171, 1.0),
        ]);
        $connector->connect();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read timeout');
        $connector->read('40001');

        proc_close($proc);
    }

    public function testGatewayConnectionRefused(): void
    {
        $connector = (new ModbusPlusProtocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', 1, 1.0),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connect failed');
        $connector->connect();
    }
}

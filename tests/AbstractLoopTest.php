<?php

namespace React\Tests\EventLoop;

abstract class AbstractLoopTest extends TestCase
{
    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    private $tickTimeout;

    public function setUp()
    {
        // It's a timeout, don't set it too low. Travis and other CI systems are slow.
        $this->tickTimeout = 0.02;
        $this->loop = $this->createLoop();
    }

    abstract public function createLoop();

    public function createSocketPair()
    {
        $domain = (DIRECTORY_SEPARATOR === '\\') ? STREAM_PF_INET : STREAM_PF_UNIX;
        $sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        foreach ($sockets as $socket) {
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
        }

        return $sockets;
    }

    public function testAddReadStream()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableExactly(2));

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testAddReadStreamIgnoresSecondCallable()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableExactly(2));
        $this->loop->addReadStream($input, $this->expectCallableNever());

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testAddWriteStream()
    {
        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableExactly(2));
        $this->tickLoop($this->loop);
        $this->tickLoop($this->loop);
    }

    public function testAddWriteStreamIgnoresSecondCallable()
    {
        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableExactly(2));
        $this->loop->addWriteStream($input, $this->expectCallableNever());
        $this->tickLoop($this->loop);
        $this->tickLoop($this->loop);
    }

    public function testRemoveReadStreamInstantly()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableNever());
        $this->loop->removeReadStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveReadStreamAfterReading()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableOnce());

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        $this->loop->removeReadStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveWriteStreamInstantly()
    {
        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableNever());
        $this->loop->removeWriteStream($input);
        $this->tickLoop($this->loop);
    }

    public function testRemoveWriteStreamAfterWriting()
    {
        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableOnce());
        $this->tickLoop($this->loop);

        $this->loop->removeWriteStream($input);
        $this->tickLoop($this->loop);
    }

    public function testRemoveStreamInstantly()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableNever());
        $this->loop->addWriteStream($input, $this->expectCallableNever());
        $this->loop->removeStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveStreamForReadOnly()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableNever());
        $this->loop->addWriteStream($output, $this->expectCallableOnce());
        $this->loop->removeReadStream($input);

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveStreamForWriteOnly()
    {
        list ($input, $output) = $this->createSocketPair();

        fwrite($output, "foo\n");

        $this->loop->addReadStream($input, $this->expectCallableOnce());
        $this->loop->addWriteStream($output, $this->expectCallableNever());
        $this->loop->removeWriteStream($output);

        $this->tickLoop($this->loop);
    }

    public function testRemoveStream()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableOnce());
        $this->loop->addWriteStream($input, $this->expectCallableOnce());

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);

        $this->loop->removeStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveInvalid()
    {
        list ($stream) = $this->createSocketPair();

        // remove a valid stream from the event loop that was never added in the first place
        $this->loop->removeReadStream($stream);
        $this->loop->removeWriteStream($stream);
        $this->loop->removeStream($stream);
    }

    /** @test */
    public function emptyRunShouldSimplyReturn()
    {
        $this->assertRunFasterThan($this->tickTimeout);
    }

    /** @test */
    public function runShouldReturnWhenNoMoreFds()
    {
        list ($input, $output) = $this->createSocketPair();

        $loop = $this->loop;
        $this->loop->addReadStream($input, function ($stream) use ($loop) {
            $loop->removeStream($stream);
        });

        fwrite($output, "foo\n");

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    /** @test */
    public function stopShouldStopRunningLoop()
    {
        list ($input, $output) = $this->createSocketPair();

        $loop = $this->loop;
        $this->loop->addReadStream($input, function ($stream) use ($loop) {
            $loop->stop();
        });

        fwrite($output, "foo\n");

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    public function testStopShouldPreventRunFromBlocking()
    {
        $this->loop->addTimer(
            1,
            function () {
                $this->fail('Timer was executed.');
            }
        );

        $this->loop->futureTick(
            function () {
                $this->loop->stop();
            }
        );

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    public function testIgnoreRemovedCallback()
    {
        // two independent streams, both should be readable right away
        list ($input1, $output1) = $this->createSocketPair();
        list ($input2, $output2) = $this->createSocketPair();

        $called = false;

        $loop = $this->loop;
        $loop->addReadStream($input1, function ($stream) use (& $called, $loop, $input2) {
            // stream1 is readable, remove stream2 as well => this will invalidate its callback
            $loop->removeReadStream($stream);
            $loop->removeReadStream($input2);

            $called = true;
        });

        // this callback would have to be called as well, but the first stream already removed us
        $loop->addReadStream($input2, function () use (& $called) {
            if ($called) {
                $this->fail('Callback 2 must not be called after callback 1 was called');
            }
        });

        fwrite($output1, "foo\n");
        fwrite($output2, "foo\n");

        $loop->run();

        $this->assertTrue($called);
    }

    public function testFutureTickEventGeneratedByFutureTick()
    {
        $this->loop->futureTick(
            function () {
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    public function testFutureTick()
    {
        $called = false;

        $callback = function ($loop) use (&$called) {
            $this->assertSame($this->loop, $loop);
            $called = true;
        };

        $this->loop->futureTick($callback);

        $this->assertFalse($called);

        $this->tickLoop($this->loop);

        $this->assertTrue($called);
    }

    public function testFutureTickFiresBeforeIO()
    {
        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () {
                echo 'stream' . PHP_EOL;
            }
        );

        $this->loop->futureTick(
            function () {
                echo 'future-tick' . PHP_EOL;
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL . 'stream' . PHP_EOL);

        $this->tickLoop($this->loop);
    }

    public function testRecursiveFutureTick()
    {
        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () use ($stream) {
                echo 'stream' . PHP_EOL;
                $this->loop->removeWriteStream($stream);
            }
        );

        $this->loop->futureTick(
            function () {
                echo 'future-tick-1' . PHP_EOL;
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick-2' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick-1' . PHP_EOL . 'stream' . PHP_EOL . 'future-tick-2' . PHP_EOL);

        $this->loop->run();
    }

    public function testRunWaitsForFutureTickEvents()
    {
        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () use ($stream) {
                $this->loop->removeStream($stream);
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    public function testFutureTickEventGeneratedByTimer()
    {
        $this->loop->addTimer(
            0.001,
            function () {
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    private function assertRunFasterThan($maxInterval)
    {
        $start = microtime(true);

        $this->loop->run();

        $end = microtime(true);
        $interval = $end - $start;

        $this->assertLessThan($maxInterval, $interval);
    }
}

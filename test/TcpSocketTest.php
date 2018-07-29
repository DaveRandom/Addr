<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\delay;

class TcpSocketTest extends SocketTest
{
    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53");
    }

    public function testTimeout(): void
    {
        $this->expectException(Dns\TimeoutException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53", 0);
    }

    public function testInvalidUri(): void
    {
        $this->expectException(Dns\ResolutionException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.8.8");
    }

    public function testAfterConnectionTimedOut(): void
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        /** @var Message $result */
        $result = $socket->ask($question, 3000);

        $this->assertSame(MessageTypes::RESPONSE, $result->getType());

        // Google's DNS times out really fast
        delay(3000);

        $this->expectException(Dns\ResolutionException::class);
        $this->expectExceptionMessageRegExp("(Sending the request failed|Reading from the server failed)");

        $socket->ask($question, 3000);
    }
}

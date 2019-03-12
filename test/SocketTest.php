<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\PHPUnit\TestCase;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;

abstract class SocketTest extends TestCase
{
    abstract protected function connect(): Dns\Internal\Transport;

    public function testAsk(): void
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();
        $result = $socket->ask($question, 5000);

        $this->assertSame(MessageTypes::RESPONSE, $result->getType());
    }

    public function testGetLastActivity(): void
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        /** @var Dns\Internal\Transport $socket */
        $socket = $this->connect();

        $this->assertLessThan(time() + 1, $socket->getLastActivity());
    }
}

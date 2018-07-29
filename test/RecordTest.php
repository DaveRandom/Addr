<?php

namespace Amp\Dns\Test;

use Amp\Dns\Record;
use Amp\PHPUnit\TestCase;

class RecordTest extends TestCase
{
    public function testGetName(): void
    {
        $this->assertSame("A", Record::getName(Record::A));
    }

    public function testGetNameOnInvalidRecordType(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("65536 does not correspond to a valid record type (must be between 0 and 65535).");

        Record::getName(65536);
    }

    public function testGetNameOnUnknownRecordType(): void
    {
        $this->assertSame("unknown (1000)", Record::getName(1000));
    }
}

<?php

namespace Amp\Dns\Test;

use Amp\PHPUnit\AsyncTestCase;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Messages\Message;

class DecodeTest extends AsyncTestCase
{
    /**
     * Regression test for https://github.com/amphp/dns/issues/53 and other reported issues.
     */
    public function testDecodesEmptyDomains()
    {
        $message = \hex2bin("37ed818000010005000d000005676d61696c03636f6d00000f0001c00c000f000100000dff0020000a04616c74310d676d61696c2d736d74702d696e016c06676f6f676c65c012c00c000f000100000dff0009001404616c7432c02ec00c000f000100000dff0009002804616c7434c02ec00c000f000100000dff0009001e04616c7433c02ec00c000f000100000dff00040005c02e0000020001000026b50014016c0c726f6f742d73657276657273036e6574000000020001000026b500040163c0a30000020001000026b500040164c0a30000020001000026b50004016ac0a30000020001000026b500040162c0a30000020001000026b500040161c0a30000020001000026b500040167c0a30000020001000026b50004016bc0a30000020001000026b500040165c0a30000020001000026b50004016dc0a30000020001000026b500040169c0a30000020001000026b500040166c0a30000020001000026b500040168c0a3");

        $decoder = (new DecoderFactory)->create();
        $response = $decoder->decode($message);

        $this->assertInstanceOf(Message::class, $response);
    }
}

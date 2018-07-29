<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

$ip = "1.1.1.1";

try {
    pretty_print_records($ip, Dns\query($ip, Dns\Record::PTR));
} catch (Dns\ResolutionException $e) {
    pretty_print_error($ip, $e);
}

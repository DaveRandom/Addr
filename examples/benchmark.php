<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

print "Downloading top 500 domains..." . PHP_EOL;

$domains = file_get_contents("https://moz.com/top500/domains/csv");
$domains = array_map(function ($line) {
    return trim(explode(",", $line)[1], '"/');
}, array_filter(explode("\n", $domains)));

// Remove "URL" header
array_shift($domains);

print "Starting sequential queries..." . PHP_EOL . PHP_EOL;

$timings = [];

for ($i = 0; $i < 10; $i++) {
    $start = microtime(1);
    $domain = $domains[random_int(0, count($domains) - 1)];

    try {
        pretty_print_records($domain, Dns\resolve($domain));
    } catch (Dns\ResolutionException $e) {
        pretty_print_error($domain, $e);
    }

    $time = round(microtime(1) - $start, 2);
    $timings[] = $time;

    printf("%'-74s" . PHP_EOL . PHP_EOL, " in " . $time . " ms");
}

$averageTime = array_sum($timings) / count($timings);

print "{$averageTime} ms for an average query." . PHP_EOL;

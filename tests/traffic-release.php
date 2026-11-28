<?php
require __DIR__ . '/traffic-function-loader.php';
trafficLoadFunctions(dirname(__DIR__) . '/tools/deploy-traffic.php', array('releaseFpmSource'));
$token = 'fixture-token'; $source = releaseFpmSource(hash('sha256', $token));
$file = tempnam(sys_get_temp_dir(), 'traffic-release-test-');
try {
    file_put_contents($file, $source);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
    if ($code !== 0) { throw new RuntimeException('Generated runtime probe does not parse'); }
    foreach (array('wrong-token' => 404, $token => 200) as $header => $expected) {
        $wrapper = '<?php http_response_code(200); $_SERVER["HTTP_X_TRAFFIC_RELEASE"]=' . var_export($header, true)
            . '; register_shutdown_function(function(){echo "\nPROBE_STATUS:".http_response_code();}); require ' . var_export($file, true) . ';';
        $driver = tempnam(sys_get_temp_dir(), 'traffic-release-driver-');
        try {
            file_put_contents($driver, $wrapper); $lines = array();
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' 2>&1', $lines, $code);
            $output = implode("\n", $lines);
            if ($code !== 0 || strpos($output, 'PROBE_STATUS:' . $expected) === false) { throw new RuntimeException('Runtime probe authentication failed'); }
            if ($expected === 404 && strpos($output, 'opcache') !== false) { throw new RuntimeException('Unauthenticated diagnostics disclosed'); }
            if ($expected === 200 && strpos($output, '"ok":true') === false) { throw new RuntimeException('Authenticated runtime probe failed'); }
        } finally { unlink($driver); }
    }
    echo "Generated runtime probe syntax and authentication checks passed\n";
} finally { unlink($file); }

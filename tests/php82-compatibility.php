<?php
declare(strict_types=1);

// Run with: php8.2 -d allow_url_include=0 tests/php82-compatibility.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: {$description}\n";
}

$root = dirname(__DIR__);
require $root . '/assets/libs/google-lib/vendor/autoload.php';
require $root . '/assets/libs/php-rss/Source/Bhaktaraz/RSSGenerator/SimpleXMLElement.php';
require $root . '/assets/libs/2checkout/lib/Twocheckout.php';
require $root . '/assets/libs/s3-lib/vendor/aws/aws-crt-php/src/AWS/CRT/Internal/Encoding.php';
require $root . '/assets/libs/stripe/vendor/stripe/stripe-php/lib/Util/Util.php';
require $root . '/assets/libs/Paysera.php';
require $root . '/assets/libs/thumbncrop.inc.php';
require $root . '/assets/libs/getID3-1.9.14/getid3/getid3.php';

$secret = str_repeat('test-only-key-', 5);
$payload = ['sub' => 'php82-regression', 'iat' => time(), 'exp' => time() + 300];
$token = Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
$decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
check($decoded->sub === $payload['sub'], 'JWT signatures round-trip without PHP 8.2 deprecations');
try {
    Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key(str_repeat('wrong-key-', 8), 'HS256'));
    throw new RuntimeException('A token with the wrong key was accepted');
} catch (Firebase\JWT\SignatureInvalidException $expected) {
    echo "PASS: JWT rejects an incorrect signature key\n";
}

$xml = new Bhaktaraz\RSSGenerator\SimpleXMLElement('<rss/>');
$child = $xml->addChild('title', 'Health & wellness');
$xml->addChildCData('description', '<b>Example & details</b>');
check($child instanceof SimpleXMLElement && (string) $child === 'Health & wellness', 'RSS addChild preserves text and its compatible return type');
check(str_contains($xml->asXML(), '<![CDATA[<b>Example & details</b>]]>'), 'RSS CDATA remains unchanged');

$params = ['sid' => '1', 'total' => '12.00', 'order_number' => '42'];
$params['key'] = strtoupper(md5($secret . $params['sid'] . $params['order_number'] . $params['total']));
check(str_contains(json_encode(Twocheckout_Return::check($params, $secret)), 'Hash Matched'), 'Payment return verification accepts a valid synthetic signature');
$params['key'] = 'incorrect';
check(str_contains(json_encode(Twocheckout_Return::check($params, $secret)), 'Hash Mismatch'), 'Payment return verification rejects an invalid synthetic signature');
$notification = ['vendor_id' => '1', 'sale_id' => '42', 'invoice_id' => '7'];
$notification['md5_hash'] = strtoupper(md5('4217' . $secret));
check(str_contains(json_encode(Twocheckout_Notification::check($notification, $secret)), 'Hash Matched'), 'Payment notification verification preserves its argument order');

$encoded = AWS\CRT\Internal\Encoding::encodeString('Health-82');
check(AWS\CRT\Internal\Encoding::decodeString($encoded) === [9, 'Health-82'], 'AWS binary string encoding preserves the byte length');
check(Stripe\Util\Util::utf8("caf\xE9") === "caf\xC3\xA9", 'Stripe converts ISO-8859-1 text without utf8_encode');
check(Stripe\Util\Util::utf8("caf\xC3\xA9") === "caf\xC3\xA9", 'Stripe leaves existing UTF-8 text unchanged');

$parserClass = null;
foreach (get_declared_classes() as $candidate) {
    if (method_exists($candidate, 'parseHttpQuery')) {
        $parserClass = $candidate;
        break;
    }
}
check($parserClass !== null, 'Paysera query parser is available');
$parser = (new ReflectionClass($parserClass))->newInstanceWithoutConstructor();
check($parser->parseHttpQuery('name=Health+Clinic&amount=12.00') === ['name' => 'Health Clinic', 'amount' => '12.00'], 'Paysera query parsing works without removed magic-quotes functions');

$crop = new ThumbAndCrop();
$filename = new ReflectionMethod(ThumbAndCrop::class, 'nomefile');
check($filename->invoke($crop, '/tmp/photo.test.jpg', true) === 'photo.test.jpg', 'Thumbnail filenames support Unix paths');
check($filename->invoke($crop, 'C:\\photos\\photo.test.jpg', false) === 'photo.test', 'Thumbnail filenames support Windows paths without spliti');

$fixtureDirectory = sys_get_temp_dir() . '/php82-media-' . bin2hex(random_bytes(8));
mkdir($fixtureDirectory, 0700);
try {
    $sampleRate = 8000;
    $data = str_repeat("\x00\x00", $sampleRate);
    $wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
    $path = $fixtureDirectory . '/one-second.wav';
    file_put_contents($path, $wav);
    $info = (new getID3())->analyze($path);
    check(empty($info['error']) && ($info['audio']['sample_rate'] ?? null) === $sampleRate && abs(($info['playtime_seconds'] ?? 0) - 1.0) < 0.01, 'getID3 reads audio metadata through the existing analyze API');
    if (is_executable('/usr/bin/ffmpeg')) {
        $path = $fixtureDirectory . '/one-second.mp4';
        $process = proc_open(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=32x32:r=10:d=1', '-c:v', 'mpeg4', '-an', '-y', $path], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'Video regression fixture is generated: ' . trim($error));
        $info = (new getID3())->analyze($path);
        check(empty($info['error']) && ($info['video']['resolution_x'] ?? null) === 32 && ($info['playtime_seconds'] ?? 0) > 0, 'getID3 reads MP4 dimensions and duration used by video uploads');
    }
} finally {
    foreach (glob($fixtureDirectory . '/*') as $file) {
        unlink($file);
    }
    rmdir($fixtureDirectory);
}

echo "PHP 8.2 compatibility regression checks completed.\n";

<?php
header('Content-Type: text/plain; charset=utf-8');
echo "QR Fetch & Generation Test\n";
$qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode('https://example.test/validar_qr.php?token=testtoken');
echo "Google Chart URL: " . $qrUrl . "\n\n";
// Test cURL
if (function_exists('curl_init')) {
  $ch = curl_init($qrUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_USERAGENT, 'SVazquezMailer/1.0-test');
  $data = curl_exec($ch);
  $info = curl_getinfo($ch);
  $err = curl_error($ch);
  curl_close($ch);
  echo "cURL available: yes\n";
  echo "HTTP code: " . ($info['http_code'] ?? 'n/a') . "\n";
  echo "Content-Type: " . ($info['content_type'] ?? 'n/a') . "\n";
  echo "Bytes received: " . (is_string($data) ? strlen($data) : 0) . "\n";
  echo "cURL error: " . ($err ?: '(none)') . "\n\n";
} else {
  echo "cURL available: NO\n\n";
}

// Test allow_url_fopen
if (ini_get('allow_url_fopen')) {
  $ok = @file_get_contents($qrUrl);
  echo "allow_url_fopen: enabled\n";
  echo "file_get_contents bytes: " . (is_string($ok) ? strlen($ok) : 0) . "\n\n";
} else {
  echo "allow_url_fopen: disabled\n\n";
}

// Test chillerlan QR
echo "chillerlan present: ";
if (class_exists('\\chillerlan\\QRCode\\QRCode') && class_exists('\\chillerlan\\QRCode\\QROptions')) {
  echo "yes\n";
  try {
    $optsClass = '\\chillerlan\\QRCode\\QROptions';
    $qrClass = '\\chillerlan\\QRCode\\QRCode';
    $opts = new $optsClass();
    $qr = new $qrClass($opts);
    $png = $qr->render('https://example.test/validar_qr.php?token=testtoken');
    echo "chillerlan output bytes: " . strlen($png) . "\n";
  } catch (Throwable $e) {
    echo "chillerlan error: " . $e->getMessage() . "\n";
  }
} else {
  echo "no\n";
}

echo "\nDone. Open this file in your browser to see results.\n";

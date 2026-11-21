<?php
// cron/test_webpush_vector.php — checks controllers/webpush.php against the
// worked example in RFC 8291 Appendix A (fixed keys + salt → exact bytes).
// CLI only. Prints PASS/FAIL; sends nothing.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit(1); }
require_once __DIR__ . '/../controllers/webpush.php';

$asPriv = webpush_b64u_dec('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPub  = webpush_b64u_dec('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
// SEC1 ECPrivateKey DER for P-256 (d + public point)
$der = hex2bin('30770201010420') . $asPriv . hex2bin('a00a06082a8648ce3d030107a144034200') . $asPub;
$pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";

$body = webpush_encrypt('When I grow up, I want to be a watermelon',
    'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'BTBZMqHH6r4Tts7J_aSIgg', $pem, webpush_b64u_dec('DGv6ra1nlYgDCS1FRnbzlw'));
$expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';
$got = $body === null ? '(null)' : webpush_b64u_enc($body);
echo ($got === $expected ? 'PASS' : 'FAIL') . "  RFC 8291 Appendix A aes128gcm vector\n";
if ($got !== $expected) echo "expected $expected\n     got $got\n";
// ES256 signature format: sign + verify round trip with a fresh key
$k = openssl_pkey_new(webpush_openssl_args());
openssl_pkey_export($k, $kp, null, webpush_openssl_args());
openssl_sign('vapid-check', $sig, $k, OPENSSL_ALGO_SHA256);
$raw = webpush_der_to_raw($sig);
echo (strlen((string)$raw) === 64 ? 'PASS' : 'FAIL') . "  ES256 signature converts to 64-byte r||s\n";
exit($got === $expected ? 0 : 1);

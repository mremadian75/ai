#!/usr/bin/env php
<?php
/**
 * Future Island provider callback simulator.
 * Generates HMAC-signed provider callback requests for staging/local operator QA.
 * It never prints the raw secret and redacts signatures in dry-run output.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

function fi_sim_usage(): string {
    return "Usage: php bin/fi-provider-callback-simulate.php --endpoint=<url> --fixture=<json> --provider-key=<key> (--secret-env=<ENV>|--secret=<value>) --run-id=<id> [--provider-family=social_signal] [--dry-run] [--send] [--bad-signature] [--stale-timestamp] [--idempotency-key=<key>]\n";
}
function fi_sim_arg(array $opts, string $key, $default = '') { return array_key_exists($key, $opts) ? $opts[$key] : $default; }
function fi_sim_redact($value) {
    if (is_array($value)) { $out = []; foreach ($value as $k => $v) { $out[$k] = fi_sim_sensitive_key((string) $k) ? '[redacted]' : fi_sim_redact($v); } return $out; }
    $s = (string) $value;
    $s = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-[redacted]', $s);
    $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1[redacted]', $s);
    $s = preg_replace('/(token|api[_-]?key|secret|signature|password)=([^&\s]+)/i', '$1=[redacted]', $s);
    return $s;
}
function fi_sim_sensitive_key(string $key): bool { return (bool) preg_match('/secret|signature|token|api[_-]?key|password|cookie|authorization/i', $key); }
function fi_sim_json($value): string { $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return is_string($json) ? $json : '{}'; }
function fi_sim_shell_arg(string $s): string { return escapeshellarg($s); }
function fi_sim_missing(array $opts, array $keys): array { $missing = []; foreach ($keys as $key) { if (!isset($opts[$key]) || trim((string) $opts[$key]) === '') { $missing[] = $key; } } return $missing; }

$opts = getopt('', [
    'endpoint:', 'fixture:', 'provider-family::', 'provider-key:', 'secret-env::', 'secret::', 'run-id:',
    'provider-run-id::', 'idempotency-key::', 'timestamp::', 'dry-run', 'send', 'bad-signature',
    'stale-timestamp', 'replay', 'redacted-headers', 'help'
]);
if (isset($opts['help'])) { echo fi_sim_usage(); exit(0); }
$missing = fi_sim_missing($opts, ['endpoint', 'fixture', 'provider-key', 'run-id']);
if (!isset($opts['secret']) && !isset($opts['secret-env'])) { $missing[] = 'secret-env|secret'; }
if (!empty($missing)) { fwrite(STDERR, "Missing required argument(s): " . implode(', ', $missing) . "\n" . fi_sim_usage()); exit(2); }

$endpoint = trim((string) $opts['endpoint']);
$fixture = trim((string) $opts['fixture']);
$provider_family = trim((string) fi_sim_arg($opts, 'provider-family', 'social_signal'));
$provider_key = trim((string) $opts['provider-key']);
$run_id = max(0, (int) $opts['run-id']);
if ($endpoint === '' || !preg_match('/^https?:\/\//i', $endpoint)) { fwrite(STDERR, "Endpoint must be an http(s) URL.\n"); exit(2); }
if ($run_id <= 0) { fwrite(STDERR, "run-id must be a positive integer.\n"); exit(2); }
if (!is_readable($fixture)) { fwrite(STDERR, "Fixture not readable: {$fixture}\n"); exit(2); }
$fixture_raw = file_get_contents($fixture);
$data = json_decode((string) $fixture_raw, true);
if (!is_array($data)) { fwrite(STDERR, "Fixture is not valid JSON: {$fixture}\n"); exit(2); }
$secret = '';
$secret_source = 'inline';
if (isset($opts['secret-env'])) {
    $secret_source = 'env:' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $opts['secret-env']);
    $secret = (string) getenv((string) $opts['secret-env']);
} else { $secret = (string) $opts['secret']; }
if ($secret === '') { fwrite(STDERR, "Provider secret is empty ({$secret_source}).\n"); exit(2); }

$timestamp = isset($opts['timestamp']) ? (int) $opts['timestamp'] : time();
if (isset($opts['stale-timestamp'])) { $timestamp = time() - 3600; }
$idempotency = trim((string) fi_sim_arg($opts, 'idempotency-key', ''));
if ($idempotency === '') {
    $idempotency = isset($opts['replay']) ? 'fi-sim-replay-' . $provider_key : 'fi-sim-' . $provider_key . '-' . $run_id . '-' . gmdate('YmdHis') . '-' . substr(hash('sha256', $fixture_raw . microtime(true)), 0, 8);
}
$data['run_id'] = $run_id;
$data['provider_key'] = $provider_key;
if ($provider_family !== '') { $data['provider_family'] = $provider_family; }
if (!isset($data['provider_run_id'])) { $data['provider_run_id'] = trim((string) fi_sim_arg($opts, 'provider-run-id', 'fi-sim-' . substr(hash('sha256', $fixture), 0, 10))); }
$raw_body = fi_sim_json($data);
$signing_secret = isset($opts['bad-signature']) ? $secret . ':bad' : $secret;
$signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw_body, $signing_secret);
$headers = [
    'X-FI-Provider-Key' => $provider_key,
    'X-FI-Timestamp' => (string) $timestamp,
    'X-FI-Signature' => $signature,
    'X-FI-Idempotency-Key' => $idempotency,
    'Content-Type' => 'application/json',
];
$redacted_headers = $headers;
$redacted_headers['X-FI-Signature'] = 'sha256=[redacted]';
$redacted_payload = fi_sim_redact($data);
$curl_parts = ['curl', '-sS', '-X', 'POST'];
foreach ($redacted_headers as $k => $v) { $curl_parts[] = '-H'; $curl_parts[] = fi_sim_shell_arg($k . ': ' . $v); }
$curl_parts[] = '--data-binary';
$curl_parts[] = fi_sim_shell_arg(fi_sim_json($redacted_payload));
$curl_parts[] = fi_sim_shell_arg($endpoint);
$dry_run = isset($opts['dry-run']) || !isset($opts['send']);

if ($dry_run) {
    echo "Future Island provider callback simulator dry run\n";
    echo "Endpoint: {$endpoint}\n";
    echo "Provider family: {$provider_family}\n";
    echo "Provider key: {$provider_key}\n";
    echo "Run ID: {$run_id}\n";
    echo "Secret source: {$secret_source} (value redacted)\n";
    echo "Timestamp: {$timestamp}" . (isset($opts['stale-timestamp']) ? " (stale mode)" : "") . "\n";
    echo "Idempotency key: {$idempotency}\n";
    echo "Bad signature mode: " . (isset($opts['bad-signature']) ? 'yes' : 'no') . "\n";
    echo "Headers (redacted): " . fi_sim_json($redacted_headers) . "\n";
    echo "Payload preview (redacted): " . fi_sim_json($redacted_payload) . "\n";
    echo "Curl preview (signature redacted): " . implode(' ', $curl_parts) . "\n";
    exit(0);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "curl extension is unavailable. Re-run with --dry-run to print a redacted curl preview.\n");
    exit(3);
}
$ch = curl_init($endpoint);
$header_lines = [];
foreach ($headers as $k => $v) { $header_lines[] = $k . ': ' . $v; }
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $header_lines,
    CURLOPT_POSTFIELDS => $raw_body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($errno) { fwrite(STDERR, "Request failed: " . fi_sim_redact($error) . "\n"); exit(4); }
echo "HTTP {$status}\n";
echo fi_sim_redact((string) $response) . "\n";
exit($status >= 200 && $status < 300 ? 0 : 5);

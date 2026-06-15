<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$json = $root . '/integrations/examples/n8n/OPTIONAL_future-island-provider-callback-template.json';
$readme = $root . '/integrations/examples/n8n/README_OPTIONAL_N8N_PROVIDER_CALLBACK.md';
fi_spine_ok(is_file($json), 'optional n8n template json exists');
fi_spine_ok(is_file($readme), 'optional n8n readme exists');
fi_spine_ok(strpos(basename($json), 'OPTIONAL_') === 0, 'optional n8n json filename clearly labeled optional');
fi_spine_ok(strpos(basename($readme), 'OPTIONAL') !== false, 'optional n8n readme filename clearly labeled optional');
$data = json_decode(file_get_contents($json), true);
fi_spine_ok(is_array($data), 'optional n8n template parses');
fi_spine_ok(stripos((string)($data['name'] ?? ''), 'optional') !== false, 'workflow name says optional');
$node_names = array_map(static function($n){ return $n['name'] ?? ''; }, $data['nodes'] ?? []);
foreach (['Manual Trigger','Set Provider Payload','Generate Timestamp + Build Raw Body','HMAC SHA256 Signature','HTTP Request to Future Island','Response Logger'] as $name) {
    fi_spine_ok(in_array($name, $node_names, true), 'optional workflow contains node ' . $name);
}
$raw = file_get_contents($json) . file_get_contents($readme);
fi_spine_ok(strpos($raw, 'FI_PROVIDER_SECRET') !== false, 'workflow uses placeholder env secret');
fi_spine_ok(strpos($raw, 'Optional n8n example. Future Island does not require n8n.') !== false, 'readme states n8n is optional');
fi_spine_ok(strpos($raw, 'External tools never write directly to canonical tables') !== false || strpos($raw, 'must never write directly to canonical Future Island tables') !== false, 'readme prohibits direct DB writes');
fi_spine_ok(!preg_match('/sk-[A-Za-z0-9_\-]{12,}/', $raw), 'workflow contains no obvious OpenAI secret');
fi_spine_ok(!preg_match('/fi_[A-Za-z0-9]{24,}/', $raw), 'workflow contains no real Future Island provider secret');
fi_spine_ok(strpos(file_get_contents($readme), 'X-FI-Signature') !== false && strpos(file_get_contents($readme), 'timestamp + "." + raw_body') !== false, 'readme documents HMAC headers and base string');
fi_spine_finish('v0.9.0 optional n8n example pack checks');

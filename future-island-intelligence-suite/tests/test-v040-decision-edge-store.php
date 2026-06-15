<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-store.php';
require_once __DIR__ . '/../includes/class-ves-decision-edge-service.php';

VES_Decision_Edge_Store::create_table(true);
fi_spine_ok(!empty($GLOBALS['fi_dbdelta_sql'][0]) && strpos($GLOBALS['fi_dbdelta_sql'][0], 'ves_decision_edges') !== false, 'migration creates ves_decision_edges table');

$edge_id = VES_Decision_Edge_Service::add_edge(7, 11, 'signal', 21, 'insight', 31, 'supports', [
    'confidence_score' => 0.82,
    'evidence_refs' => ['evidence:5'],
    'metadata' => ['note' => 'manual link'],
]);
fi_spine_ok(is_int($edge_id) && $edge_id > 0, 'add_edge returns id');
$by_run = VES_Decision_Edge_Service::list_edges_for_run(7, 11);
fi_spine_ok(count($by_run) === 1 && $by_run[0]['from_type'] === 'signal' && $by_run[0]['to_type'] === 'insight', 'list_edges_for_run works');
fi_spine_ok($by_run[0]['confidence_score'] == 0.82 && $by_run[0]['evidence_refs'][0] === 'evidence:5', 'edge JSON refs round trip');
$incoming = VES_Decision_Edge_Service::list_edges_for_target(7, 'insight', 31);
fi_spine_ok(count($incoming) === 1 && $incoming[0]['relation_type'] === 'supports', 'list_edges_for_target scopes incoming edges');
$outgoing = VES_Decision_Edge_Service::list_edges_from_source(7, 'signal', 21);
fi_spine_ok(count($outgoing) === 1 && $outgoing[0]['to_id'] === 31, 'list_edges_from_source scopes outgoing edges');
fi_spine_ok(count(VES_Decision_Edge_Service::list_edges_for_run(8, 11)) === 0, 'workspace scope enforced for run edges');
fi_spine_ok(is_wp_error(VES_Decision_Edge_Service::add_edge(7, 11, '', 21, 'insight', 31, 'supports')), 'empty endpoint type rejected');
fi_spine_ok(is_wp_error(VES_Decision_Edge_Service::add_edge(7, 11, 'signal', 0, 'insight', 31, 'supports')), 'empty endpoint id rejected');
fi_spine_ok(is_wp_error(VES_Decision_Edge_Service::add_edge(0, 11, 'signal', 21, 'insight', 31, 'supports')), 'missing workspace rejected');

fi_spine_finish('v0.4.0 decision edge store checks');

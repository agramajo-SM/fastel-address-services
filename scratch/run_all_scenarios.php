<?php
require_once( dirname(__DIR__, 4) . '/wp-load.php' );

$sf = new Avalaunch_Salesforce();

// Let's define some test scenarios based on known data or logic
$scenarios = [
    [
        'name' => '1. Exact Match - PM Address (Apartment W148)',
        'street_number' => '11050',
        'street_keyword' => '700',
        'unit' => 'W148',
        'street_keyword2' => 'East',
        'place_id' => 'ChIJD_dummy_place_id', // Frontend sends a real looking one, but it won't match Salesforce's base64
        'expected' => true
    ],
    [
        'name' => '2. Exact Match - Single Family Home',
        // Need to use generic terms that might exist in the DB, e.g., 10058 S Beetdigger Blvd
        'street_number' => '10058',
        'street_keyword' => 'Beetdigger',
        'unit' => '',
        'street_keyword2' => '',
        'place_id' => '',
        'expected' => true // assuming this exists from previous scratch data
    ],
    [
        'name' => '3. Apartment with Unit U224',
        'street_number' => '11050',
        'street_keyword' => '700',
        'unit' => 'U224',
        'street_keyword2' => 'East',
        'place_id' => '',
        'expected' => true
    ],
    [
        'name' => '4. Non-existent Address (Should fail)',
        'street_number' => '99999',
        'street_keyword' => 'Nowhere',
        'unit' => '',
        'street_keyword2' => 'St',
        'place_id' => '',
        'expected' => false
    ],
    [
        'name' => '5. Existing Address but Wrong Unit (Should fail)',
        'street_number' => '11050',
        'street_keyword' => '700',
        'unit' => 'Z999',
        'street_keyword2' => 'East',
        'place_id' => '',
        'expected' => false
    ]
];

echo "Running Address Matching Scenarios...\n";
echo str_repeat("-", 50) . "\n";

foreach ($scenarios as $s) {
    echo "Scenario: {$s['name']}\n";
    echo "  Input: {$s['street_number']} {$s['street_keyword']} {$s['street_keyword2']} (Unit: {$s['unit']}) [PlaceID: {$s['place_id']}]\n";
    
    $result = $sf->get_services_by_address(
        $s['street_number'],
        $s['street_keyword'],
        $s['unit'],
        $s['street_keyword2'],
        $s['place_id']
    );

    if ( is_wp_error( $result ) ) {
        echo "  Result: ERROR - " . $result->get_error_message() . "\n";
        $success = false;
    } else {
        $has_coverage = $result['has_coverage'];
        echo "  Result: " . ($has_coverage ? "COVERAGE FOUND" : "NO COVERAGE") . "\n";
        if ( $has_coverage ) {
            echo "    Matched Record: {$result['name']} (Unit: {$result['unit']})\n";
        }
        $success = ($has_coverage === $s['expected']);
    }

    echo "  Status: " . ($success ? "✅ PASSED" : "❌ FAILED") . "\n";
    echo str_repeat("-", 50) . "\n";
}

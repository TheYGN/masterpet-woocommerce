<?php
/**
 * Cross-language check: the same literal vector as MasterPet's
 * scripts/woo/woo-auth-selfcheck.mts. Run: php tests/signature-vector.php
 * Exit 0 = all pass.
 *
 * @package MasterPet
 */

define( 'MASTERPET_CLI_TEST', true );
require __DIR__ . '/../includes/class-masterpet-signature.php';

$failures = 0;
function check( string $label, bool $cond ): void {
	global $failures;
	if ( $cond ) {
		echo "  ok  $label\n";
	} else {
		$failures++;
		echo "  FAIL $label\n";
	}
}

$secret = 'test-secret-0123456789';
$body   = '{"plugin_version":"1.0.0"}';

echo "signature-vector:\n";
check( 'body sha256 matches', hash( 'sha256', $body ) === '405227dfc4a216276daa93e2710f9fe70e67774b825e882b77c971e78c07c84b' );
check(
	'signature matches the MasterPet vector',
	MasterPet_Signature::sign( $secret, 'post', '/v1/heartbeat', '1760000000', 'abcdefghijklmnop0123', $body )
		=== '5b9109ec9867b97d38a25a21ef6040e9f262f457d98f1f2d201c99a6dc20811a'
);
check( 'code normalizes like MasterPet', MasterPet_Signature::normalize_code( 't-7kq2-rx9d-mvpa' ) === 'T7KQ2RX9DMVPA' );
check( 'code hash matches the MasterPet literal', hash( 'sha256', 'T7KQ2RX9DMVPA' ) === 'c90a699c5eb61aec843db288460ac88d5a5110fff690347bc48d0cab7063ed31' );
check( 'look-alikes are mapped', MasterPet_Signature::normalize_code( 'P-OIL0-0000-0000' ) === 'P011000000000' );
check( 'an unknown environment letter is refused', null === MasterPet_Signature::normalize_code( 'X-7KQ2-RX9D-MVPA' ) );
check( 'U is not a Crockford character', null === MasterPet_Signature::normalize_code( 'T-UUUU-RX9D-MVPA' ) );
check( 'T goes to the test server', MasterPet_Signature::server_for_code( 'T7KQ2RX9DMVPA' ) === 'https://api-dev.masterpet.app' );
check( 'P goes to production', MasterPet_Signature::server_for_code( 'P7KQ2RX9DMVPA' ) === 'https://api.masterpet.app' );
check( 'a nonce is 32 hex chars', (bool) preg_match( '/^[0-9a-f]{32}$/', MasterPet_Signature::new_nonce() ) );

if ( $failures ) {
	echo "signature-vector: $failures failed\n";
	exit( 1 );
}
echo "signature-vector: all passed\n";

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
	'signature matches the MasterPet vector (site id included)',
	MasterPet_Signature::sign( $secret, 'post', '/v1/heartbeat', '11111111-1111-4111-8111-111111111111', '1760000000', 'abcdefghijklmnop0123', $body )
		=== '89dc7974c326187cf9d0989d561ff6a6f8de4048c443b4f25cb6c41f02b76663'
);
check(
	'an uppercase site id signs the same',
	MasterPet_Signature::sign( $secret, 'POST', '/v1/heartbeat', '11111111-1111-4111-8111-111111111111', '1760000000', 'abcdefghijklmnop0123', $body )
		=== MasterPet_Signature::sign( $secret, 'POST', '/v1/heartbeat', strtoupper( '11111111-1111-4111-8111-111111111111' ), '1760000000', 'abcdefghijklmnop0123', $body )
);
check( 'code normalizes like MasterPet', MasterPet_Signature::normalize_code( 't-7kq2-rx9d-mvpa' ) === 'T7KQ2RX9DMVPA' );
check( 'code hash matches the MasterPet literal', hash( 'sha256', 'T7KQ2RX9DMVPA' ) === 'c90a699c5eb61aec843db288460ac88d5a5110fff690347bc48d0cab7063ed31' );
check( 'look-alikes are mapped', MasterPet_Signature::normalize_code( 'P-OIL0-0000-0000' ) === 'P011000000000' );
check( 'an unknown environment letter is refused', null === MasterPet_Signature::normalize_code( 'X-7KQ2-RX9D-MVPA' ) );
check( 'U is not a Crockford character', null === MasterPet_Signature::normalize_code( 'T-UUUU-RX9D-MVPA' ) );
check( 'T goes to the test server', MasterPet_Signature::server_for_code( 'T7KQ2RX9DMVPA' ) === 'https://api-dev.masterpet.app' );
check( 'P goes to production', MasterPet_Signature::server_for_code( 'P7KQ2RX9DMVPA' ) === 'https://api.masterpet.app' );
// Doorbell vectors: the same literals as MasterPet's scripts/woo/woo-doorbell-selfcheck.mts.
$site  = '3f0c2a8e-1b2d-4c5e-8f9a-0b1c2d3e4f50';
$token = '00112233445566778899aabbccddeeff';
$dsig  = '15b6d7cd2be80fc17e5f7b91b67f766f1d31f9804ddc5083789707cd54274898';
check( 'ack answer matches the MasterPet literal', MasterPet_Signature::answer( $secret, 'ack', $site, $token ) === '8884012d224743d85038637e80fe3a3950efbf5fa43b6670f5ec1c18b3954b2d' );
check( 'pong answer matches the MasterPet literal', MasterPet_Signature::answer( $secret, 'pong', $site, $token ) === 'fa954914b072cf1fdf084d5240f5e08f49ab67e2ee3373ebf8fd69f93c49f90a' );
check( 'a doorbell signed by MasterPet verifies', MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/doorbell', $site, '1760000000', $token, '{"reason":"changes"}', $dsig, 1760000100 ) );
check( 'an uppercase signature verifies', MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/doorbell', $site, '1760000000', $token, '{"reason":"changes"}', strtoupper( $dsig ), 1760000100 ) );
check( 'a doorbell older than 300 s is refused', ! MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/doorbell', $site, '1760000000', $token, '{"reason":"changes"}', $dsig, 1760000301 ) );
check( 'a changed body is refused', ! MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/doorbell', $site, '1760000000', $token, '{"reason":"link"}', $dsig, 1760000100 ) );
check( 'the ping route does not accept a doorbell signature', ! MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/ping', $site, '1760000000', $token, '{"reason":"changes"}', $dsig, 1760000100 ) );
check( 'another secret is refused', ! MasterPet_Signature::verify_inbound( 'other', 'POST', '/masterpet/v1/doorbell', $site, '1760000000', $token, '{"reason":"changes"}', $dsig, 1760000100 ) );
check( 'a short nonce is refused', ! MasterPet_Signature::verify_inbound( $secret, 'POST', '/masterpet/v1/doorbell', $site, '1760000000', 'short', '{"reason":"changes"}', $dsig, 1760000100 ) );
check( 'a nonce is 32 hex chars', (bool) preg_match( '/^[0-9a-f]{32}$/', MasterPet_Signature::new_nonce() ) );

if ( $failures ) {
	echo "signature-vector: $failures failed\n";
	exit( 1 );
}
echo "signature-vector: all passed\n";

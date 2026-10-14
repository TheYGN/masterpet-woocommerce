<?php
/**
 * Which GitHub release a site is offered. Run: php tests/updater-pick.php
 * Exit 0 = all pass.
 *
 * @package MasterPet
 */

define( 'MASTERPET_CLI_TEST', true );
require __DIR__ . '/../includes/class-masterpet-updater.php';

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

function rel( string $tag, bool $pre, array $extra = array() ): array {
	return array_merge(
		array(
			'tag_name'   => $tag,
			'prerelease' => $pre,
			'draft'      => false,
			'html_url'   => "https://github.com/TheYGN/masterpet-woocommerce/releases/tag/$tag",
			'assets'     => array(
				array(
					'name'                 => 'masterpet-woocommerce.zip',
					'browser_download_url' => "https://github.com/TheYGN/masterpet-woocommerce/releases/download/$tag/masterpet-woocommerce.zip",
				),
			),
		),
		$extra
	);
}

$list = array( rel( 'v0.8.0', true ), rel( 'v0.7.1', false ), rel( 'v0.7.0', false ) );

echo "updater-pick:\n";
$p = MasterPet_Updater::pick( $list, false );
check( 'a regular site gets the newest regular release', $p && '0.7.1' === $p['version'] && ! $p['prerelease'] );
$p = MasterPet_Updater::pick( $list, true );
check( 'a test site gets the pre-release', $p && '0.8.0' === $p['version'] && $p['prerelease'] );
check( 'the package is the release asset', $p && 'https://github.com/TheYGN/masterpet-woocommerce/releases/download/v0.8.0/masterpet-woocommerce.zip' === $p['package'] );

$p = MasterPet_Updater::pick( array( rel( 'v0.7.0', true ), rel( 'v0.7.2', false ) ), true );
check( 'a test site gets a newer regular release over an older pre-release', $p && '0.7.2' === $p['version'] );

$p = MasterPet_Updater::pick( array( rel( 'v0.9.0', false, array( 'draft' => true ) ), rel( 'v0.7.0', false ) ), true );
check( 'drafts are ignored', $p && '0.7.0' === $p['version'] );

$foreign = rel( 'v0.9.0', false );
$foreign['assets'][0]['browser_download_url'] = 'https://evil.example/masterpet-woocommerce.zip';
$p = MasterPet_Updater::pick( array( $foreign, rel( 'v0.7.0', false ) ), false );
check( 'a package outside this repository is ignored', $p && '0.7.0' === $p['version'] );

$p = MasterPet_Updater::pick( array( rel( 'v0.9.0', false, array( 'assets' => array() ) ) ), false );
check( 'a release without the zip is ignored', null === $p );

$p = MasterPet_Updater::pick( array( rel( 'nightly', false ), rel( 'v1.0.0-beta', false ) ), true );
check( 'tags that are not a version are ignored', null === $p );

check( 'no releases, nothing offered', null === MasterPet_Updater::pick( array(), true ) );
check( 'version comparison is numeric (0.10.0 > 0.9.0)', '0.10.0' === ( MasterPet_Updater::pick( array( rel( 'v0.9.0', false ), rel( 'v0.10.0', false ) ), false )['version'] ?? '' ) );

exit( $failures ? 1 : 0 );

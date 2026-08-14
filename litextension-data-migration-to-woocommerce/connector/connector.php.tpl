<?php
/**
 * LitExtension migration connector bridge.
 *
 * This file is generated from the template shipped inside the plugin when the
 * store owner installs the connector from the WordPress admin. It contains no
 * logic and no credentials: it loads WordPress and hands the request to
 * LitExtension\LitBridge, which lives in the plugin and is reviewed with it.
 *
 * Because the connector token is stored (hashed) in the WordPress options
 * table, rewriting this file does not grant access to anything, and
 * deactivating the plugin disables the endpoint.
 *
 * @package LitExtension
 */

// Swallow anything WordPress or another plugin may print during bootstrap so
// the migration engine always receives a clean response body.
ob_start();

$lecm_wp_load = '{{WP_LOAD_PATH}}';

if ( ! is_readable( $lecm_wp_load ) ) {
	ob_end_clean();
	header( 'Content-Type: text/plain; charset=utf-8' );
	http_response_code( 500 );
	echo 'WordPress could not be loaded from this location.';
	exit;
}

define( 'LECM_CONNECTOR_REQUEST', true );

require_once $lecm_wp_load;

if ( ! class_exists( 'LitExtension\LitBridge' ) ) {
	ob_end_clean();
	header( 'Content-Type: text/plain; charset=utf-8' );
	http_response_code( 503 );
	echo 'The LitExtension migration plugin is not active on this site.';
	exit;
}

LitExtension\LitBridge::handle();

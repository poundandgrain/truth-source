<?php
/**
 * Truth Source
 *
 * Plugin Name: Truth Source
 *
 * Identifies the current source of truth in a multi-environment development schema
 */

if ( is_admin() ) {
	// If the plugin was already loaded from somewhere else do not load again
	if ( defined( 'WP_SOT_LOADED' ) ) {
		return;
	}

	// Load the SOT dependencies
	require_once 'truth-source-admin.php';

	if ( class_exists( Truth_Source::class ) ) {
		Truth_Source::get_instance();
	}
	define( 'WP_SOT_LOADED', true );
}
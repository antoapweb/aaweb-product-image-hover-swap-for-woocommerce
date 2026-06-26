<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uninstall cleanup for AAWEB Product Image Hover Swap for Woocommerce.
 *
 * @package AAWEB_Hover_Swap
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aaweb_hover_swap_options' );
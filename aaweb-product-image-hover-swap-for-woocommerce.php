<?php
/**
 * Plugin Name: AAWEB Product Image Hover Swap for WooCommerce
 * Plugin URI: https://antoapweb.gr/aaweb-product-image-hover-swap-for-woocommerce/
 * Description: Adds a second-image hover swap effect to WooCommerce and product card loops, including Elementor, ShopEngine and block-based catalogs.
 * Version: 1.3.9
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: AAWEB - Apostolou Antonios
 * Author URI: https://antoapweb.gr
 * Text Domain: aaweb-product-image-hover-swap-for-woocommerce
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AAWEB_Hover_Swap
 */

defined( 'ABSPATH' ) || exit;

final class AAWEB_Universal_Woo_Hover_Swap {

	const VERSION      = '1.3.9';
	const OPTION_NAME  = 'aaweb_hover_swap_options';
	const NONCE_ACTION = 'aaweb_hover_swap_nonce_action';

	private static array $second_image_map = array();

	public static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_product' );
	}

	public static function activate(): void {
		if ( self::is_woocommerce_active() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return;
		}

		deactivate_plugins( plugin_basename( __FILE__ ) );

		wp_die(
			esc_html__( 'AAWEB Product Image Hover Swap for WooCommerce requires WooCommerce to be installed and active.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			esc_html__( 'Plugin Activation Error', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			array( 'back_link' => true )
		);
	}

	public static function maybe_show_woocommerce_notice(): void {
		if ( self::is_woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'AAWEB Product Image Hover Swap for WooCommerce requires WooCommerce to be installed and active.', 'aaweb-product-image-hover-swap-for-woocommerce' );
		echo '</p></div>';
	}

	public static function boot(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_woocommerce_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_aaweb_hover_swap_reset_defaults', array( __CLASS__, 'reset_defaults' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'hook_store_second_image_id' ), 9 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'hook_print_second_image' ), 11 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'hook_cleanup_second_image_id' ), 12 );

		add_action( 'wp_ajax_aaweb_hover_swap_second_image', array( __CLASS__, 'ajax_second_image' ) );
		add_action( 'wp_ajax_nopriv_aaweb_hover_swap_second_image', array( __CLASS__, 'ajax_second_image' ) );
	}

	public static function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=aaweb-product-image-hover-swap-for-woocommerce' ) ),
			esc_html__( 'Settings', 'aaweb-product-image-hover-swap-for-woocommerce' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	private static function capability(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function defaults(): array {
		return array(
			'enabled'            => 1,
			'object_fit'         => 'contain',
			'transition_ms'      => 250,

			'selector_item'      => 'li.product, .wc-block-grid__product, .wp-block-woocommerce-product-template li, .elementor-widget-woocommerce-products li.product, .elementor-widget-wc-products li.product, .shopengine-single-product-item',
			'selector_link'      => 'a.woocommerce-LoopProduct-link, a.woocommerce-loop-product__link, .wc-block-grid__product-link, a[href*="/product/"], a[href*="?product="], .shopengine-single-product-item .product-thumb a',
			'selector_img'       => 'img',

			'enable_hook_inject' => 1,
			'hook_img_size'      => 'medium_large',
			'gallery_index'      => 0,

			'enable_ajax_dom'    => 1,
			'dom_observer'       => 1,
			'ajax_timeout_ms'    => 8000,
			'ajax_cache'         => 1,
			'ajax_concurrency'   => 4,
		);
	}

	public static function get_options(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return self::normalize_options( $saved );
	}

	private static function normalize_options( array $saved ): array {
		$defaults = self::defaults();
		$options  = array_merge( $defaults, $saved );

		$options['enabled']            = empty( $options['enabled'] ) ? 0 : 1;
		$options['object_fit']         = 'cover' === ( $options['object_fit'] ?? '' ) ? 'cover' : $defaults['object_fit'];
		$options['transition_ms']      = self::positive_int_or_default( $options['transition_ms'] ?? null, $defaults['transition_ms'], 0 );

		$options['selector_item']      = self::selector_with_defaults( (string) ( $options['selector_item'] ?? '' ), $defaults['selector_item'] );
		$options['selector_link']      = self::selector_with_defaults( (string) ( $options['selector_link'] ?? '' ), $defaults['selector_link'] );
		$options['selector_img']       = self::selector_with_defaults( (string) ( $options['selector_img'] ?? '' ), $defaults['selector_img'] );

		$options['hook_img_size']      = self::image_size_or_default( $options['hook_img_size'] ?? '', $defaults['hook_img_size'] );
		$options['gallery_index']      = self::positive_int_or_default( $options['gallery_index'] ?? null, $defaults['gallery_index'], 0 );

		// Internal behavior: one stable frontend flow, no user-facing legacy toggles.
		$options['enable_hook_inject'] = 1;
		$options['enable_ajax_dom']    = 1;
		$options['dom_observer']       = 1;
		$options['ajax_timeout_ms']    = $defaults['ajax_timeout_ms'];
		$options['ajax_cache']         = 1;
		$options['ajax_concurrency']   = $defaults['ajax_concurrency'];

		return $options;
	}

	private static function positive_int_or_default( $value, int $default, int $minimum ): int {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		$value = absint( $value );

		if ( $value < $minimum ) {
			return $default;
		}

		return $value;
	}

	private static function image_size_or_default( $value, string $default ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return '' !== $value ? $value : $default;
	}

	private static function selector_with_defaults( string $selector, string $default ): string {
		$selector = self::sanitize_selector( $selector, '' );

		if ( '' === $selector ) {
			return $default;
		}

		$selector_parts = array();

		foreach ( explode( ',', $default . ',' . $selector ) as $part ) {
			$part = trim( $part );

			if ( '' === $part || in_array( $part, $selector_parts, true ) ) {
				continue;
			}

			$selector_parts[] = $part;
		}

		return implode( ', ', $selector_parts );
	}

	private static function append_selector_part( string $selector, string $part ): string {
		$selector_parts = array_map( 'trim', explode( ',', $selector ) );

		if ( in_array( $part, $selector_parts, true ) ) {
			return $selector;
		}

		$selector_parts[] = $part;

		return implode( ', ', array_filter( $selector_parts ) );
	}

	public static function admin_menu(): void {
		$capability = self::capability();

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				esc_html__( 'AAWEB Hover Swap', 'aaweb-product-image-hover-swap-for-woocommerce' ),
				esc_html__( 'AAWEB Hover Swap', 'aaweb-product-image-hover-swap-for-woocommerce' ),
				$capability,
				'aaweb-product-image-hover-swap-for-woocommerce',
				array( __CLASS__, 'settings_page' )
			);

			return;
		}

		add_options_page(
			esc_html__( 'AAWEB Hover Swap', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			esc_html__( 'AAWEB Hover Swap', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			$capability,
			'aaweb-product-image-hover-swap-for-woocommerce',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'aaweb_hover_swap_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'aaweb_hover_swap_main',
			esc_html__( 'General Settings', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			array( __CLASS__, 'settings_section_intro' ),
			'aaweb-product-image-hover-swap-for-woocommerce'
		);

		add_settings_section(
			'aaweb_hover_swap_advanced',
			esc_html__( 'Advanced Settings', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			array( __CLASS__, 'settings_section_advanced_intro' ),
			'aaweb-product-image-hover-swap-for-woocommerce'
		);

		$general_fields = array(
			'enabled'       => array( __( 'Enabled', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'checkbox' ),
			'hook_img_size' => array(
				__( 'Hover image size', 'aaweb-product-image-hover-swap-for-woocommerce' ),
				'select',
				array(
					'medium_large'          => __( 'Medium Large (Recommended)', 'aaweb-product-image-hover-swap-for-woocommerce' ),
					'woocommerce_thumbnail' => __( 'WooCommerce Thumbnail', 'aaweb-product-image-hover-swap-for-woocommerce' ),
					'medium'                => __( 'Medium', 'aaweb-product-image-hover-swap-for-woocommerce' ),
					'large'                 => __( 'Large', 'aaweb-product-image-hover-swap-for-woocommerce' ),
					'full'                  => __( 'Full Size', 'aaweb-product-image-hover-swap-for-woocommerce' ),
				),
			),
			'object_fit'    => array( __( 'Object fit', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'select', array( 'contain' => 'contain', 'cover' => 'cover' ) ),
			'transition_ms' => array( __( 'Transition duration in ms', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'number' ),
		);

		$advanced_fields = array(
			'selector_item' => array( __( 'Product card selector', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'text' ),
			'selector_link' => array( __( 'Product link selector', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'text' ),
			'selector_img'  => array( __( 'Product image selector', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'text' ),
			'gallery_index' => array( __( 'Gallery image index', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'number' ),
		);

		foreach ( $general_fields as $key => $field ) {
			self::add_settings_field( $key, $field, 'aaweb_hover_swap_main' );
		}

		foreach ( $advanced_fields as $key => $field ) {
			self::add_settings_field( $key, $field, 'aaweb_hover_swap_advanced' );
		}
	}

	private static function add_settings_field( string $key, array $field, string $section ): void {
		add_settings_field(
			'aaweb_hover_swap_' . $key,
			esc_html( $field[0] ),
			array( __CLASS__, 'render_field' ),
			'aaweb-product-image-hover-swap-for-woocommerce',
			$section,
			array(
				'key'     => $key,
				'type'    => $field[1],
				'choices' => $field[2] ?? array(),
			)
		);
	}

	public static function settings_section_intro(): void {
		echo '<p>' . esc_html__( 'Universal second-image hover swap for WooCommerce, Elementor product grids, ShopEngine product lists, WooCommerce blocks and compatible product card loops.', 'aaweb-product-image-hover-swap-for-woocommerce' ) . '</p>';
	}

	public static function settings_section_advanced_intro(): void {
		echo '<p>' . esc_html__( 'Use these only when a theme or builder has a custom product card structure.', 'aaweb-product-image-hover-swap-for-woocommerce' ) . '</p>';
	}

	public static function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$output = array(
			'enabled'       => empty( $input['enabled'] ) ? 0 : 1,
			'object_fit'    => isset( $input['object_fit'] ) && 'cover' === $input['object_fit'] ? 'cover' : 'contain',
			'transition_ms' => $input['transition_ms'] ?? '',
			'hook_img_size' => $input['hook_img_size'] ?? '',
			'selector_item' => $input['selector_item'] ?? '',
			'selector_link' => $input['selector_link'] ?? '',
			'selector_img'  => $input['selector_img'] ?? '',
			'gallery_index' => $input['gallery_index'] ?? '',
		);

		return self::normalize_options( $output );
	}

	private static function sanitize_selector( $value, string $fallback ): string {
		$value = is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		$value = trim( $value );

		if ( '' === $value ) {
			return $fallback;
		}

		$value = preg_replace( '/[^a-zA-Z0-9\-\_\.\#\,\:\>\+\~\s\[\]\=\"\*]/', '', $value );
		return trim( (string) $value );
	}

	public static function render_field( array $args ): void {
		$options = self::get_options();
		$key     = sanitize_key( $args['key'] );
		$type    = sanitize_key( $args['type'] );
		$name    = self::OPTION_NAME . '[' . $key . ']';
		$value   = $options[ $key ] ?? '';

		if ( 'checkbox' === $type ) {
			printf(
				'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
				esc_attr( $name ),
				checked( 1, (int) $value, false ),
				esc_html__( 'Yes', 'aaweb-product-image-hover-swap-for-woocommerce' )
			);

			$help = self::field_help( $key );

			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}

			return;
		}

		if ( 'select' === $type ) {
			$choices = is_array( $args['choices'] ) ? $args['choices'] : array();

			echo '<select name="' . esc_attr( $name ) . '">';

			foreach ( $choices as $choice_value => $choice_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $choice_value ),
					selected( $value, $choice_value, false ),
					esc_html( $choice_label )
				);
			}

			echo '</select>';

			$help = self::field_help( $key );

			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}

			return;
		}

		$input_type = 'number' === $type ? 'number' : 'text';

		printf(
			'<input type="%1$s" name="%2$s" value="%3$s" class="regular-text" %4$s>',
			esc_attr( $input_type ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			'number' === $input_type ? 'step="1" min="0"' : ''
		);

		$help = self::field_help( $key );

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	private static function field_help( string $key ): string {
		$help = array(
			'enabled'       => __( 'Turns the hover swap effect on or off.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'hook_img_size' => __( 'Choose the image size used for the hover image. Medium Large is recommended for the best balance between quality and performance.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'object_fit'    => __( 'Controls how the second image fits inside the product thumbnail area.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'transition_ms' => __( 'Animation duration in milliseconds. Leave empty to use the default.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'selector_item' => __( 'Advanced: custom product card selectors are added after the built-in default selectors.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'selector_link' => __( 'Advanced: custom product link selectors are added after the built-in default selectors.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'selector_img'  => __( 'Advanced: custom image selectors are added after the built-in default selector.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
			'gallery_index' => __( 'Zero-based gallery image index. 0 means the first product gallery image.', 'aaweb-product-image-hover-swap-for-woocommerce' ),
		);

		return isset( $help[ $key ] ) ? (string) $help[ $key ] : '';
	}

	public static function reset_defaults(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to reset these settings.', 'aaweb-product-image-hover-swap-for-woocommerce' ) );
		}

		check_admin_referer( 'aaweb_hover_swap_reset_defaults' );

		delete_option( self::OPTION_NAME );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aaweb-product-image-hover-swap-for-woocommerce',
					'settings-reset'  => 'true',
				),
				admin_url( self::is_woocommerce_active() ? 'admin.php' : 'options-general.php' )
			)
		);
		exit;
	}

	public static function settings_page(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'aaweb-product-image-hover-swap-for-woocommerce' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AAWEB Product Image Hover Swap for WooCommerce', 'aaweb-product-image-hover-swap-for-woocommerce' ) . '</h1>';
		echo '<p><strong>AAWEB</strong> — Apostolou Antonios — <a href="' . esc_url( 'https://antoapweb.gr' ) . '" target="_blank" rel="noopener noreferrer">antoapweb.gr</a></p>';
		echo '<form method="post" action="options.php">';

		settings_fields( 'aaweb_hover_swap_group' );
		do_settings_sections( 'aaweb-product-image-hover-swap-for-woocommerce' );
		submit_button( esc_html__( 'Save Settings', 'aaweb-product-image-hover-swap-for-woocommerce' ) );

		echo '</form>';

		echo '<hr>';
		echo '<h2>' . esc_html__( 'Reset Defaults', 'aaweb-product-image-hover-swap-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Restore the built-in default selectors and numeric values. This is useful if a custom setting was changed by mistake.', 'aaweb-product-image-hover-swap-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aaweb_hover_swap_reset_defaults">';
		wp_nonce_field( 'aaweb_hover_swap_reset_defaults' );
		submit_button( esc_html__( 'Reset to Defaults', 'aaweb-product-image-hover-swap-for-woocommerce' ), 'secondary' );
		echo '</form>';

		echo '</div>';
	}

	public static function enqueue_frontend_assets(): void {
		if ( is_admin() || ! self::is_woocommerce_active() ) {
			return;
		}

		$options = self::get_options();

		if ( empty( $options['enabled'] ) ) {
			return;
		}

		wp_register_style( 'aaweb-product-image-hover-swap-for-woocommerce', false, array(), self::VERSION );
		wp_enqueue_style( 'aaweb-product-image-hover-swap-for-woocommerce' );
		wp_add_inline_style( 'aaweb-product-image-hover-swap-for-woocommerce', self::get_frontend_css( $options ) );

		wp_register_script( 'aaweb-product-image-hover-swap-for-woocommerce', false, array(), self::VERSION, true );
		wp_enqueue_script( 'aaweb-product-image-hover-swap-for-woocommerce' );

		$config = array(
			'item'    => $options['selector_item'],
			'link'    => $options['selector_link'],
			'img'     => $options['selector_img'],
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'timeout' => absint( $options['ajax_timeout_ms'] ),
			'conc'    => absint( $options['ajax_concurrency'] ),
		);

		wp_add_inline_script(
			'aaweb-product-image-hover-swap-for-woocommerce',
			'window.AAWEBHoverSwapConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		wp_add_inline_script( 'aaweb-product-image-hover-swap-for-woocommerce', self::get_frontend_js() );
	}

	private static function get_frontend_css( array $options ): string {
		$fit          = 'cover' === $options['object_fit'] ? 'cover' : 'contain';
		$transition   = absint( $options['transition_ms'] );
		$item         = self::css_selector_for_output( $options['selector_item'] );
		$link         = self::css_selector_for_output( $options['selector_link'] );
		$base = "
img.aaweb-hs-secondary{
	display:none !important;
	opacity:0;
	pointer-events:none;
}
.aaweb-hs-imgwrap{
	position:relative;
	display:block;
	line-height:0;
	z-index:0;
	overflow:hidden;
}
.aaweb-hs-imgwrap > img{
	display:block !important;
	width:100%;
	height:auto;
	position:relative;
	z-index:0;
}
.aaweb-hs-imgwrap > img:nth-of-type(2){
	position:absolute;
	top:0;
	left:0;
	width:100%;
	height:100%;
	object-fit:{$fit};
	opacity:0;
	pointer-events:none;
	z-index:1;
	transition:opacity {$transition}ms ease;
}
{$item} a.woocommerce-LoopProduct-link > :not(.aaweb-hs-imgwrap),
{$item} a.woocommerce-loop-product__link > :not(.aaweb-hs-imgwrap),
{$item} .wc-block-grid__product-link > :not(.aaweb-hs-imgwrap){
	position:relative;
	z-index:5;
}
";

		$hover = "
{$item}:hover .aaweb-hs-imgwrap > img:nth-of-type(2){opacity:1;}
{$item} {$link}:hover .aaweb-hs-imgwrap > img:nth-of-type(2){opacity:1;}
/* Stable WooCommerce fallback for default shop/category loops. */
.woocommerce ul.products li.product:hover .aaweb-hs-imgwrap > img.aaweb-hs-secondary,
.woocommerce-page ul.products li.product:hover .aaweb-hs-imgwrap > img.aaweb-hs-secondary,
ul.products li.product:hover .aaweb-hs-imgwrap > img.aaweb-hs-secondary{opacity:1;}
";

		return $base . "
	/* Hover effects apply only on devices with a real hover-capable pointer. */
	@media (hover:hover) and (pointer:fine){
		{$hover}
	}
	";
	}

	private static function css_selector_for_output( string $selector ): string {
		$selector = self::sanitize_selector( $selector, '' );
		return '' !== $selector ? $selector : 'li.product';
	}

	public static function hook_store_second_image_id(): void {
		$options = self::get_options();

		if ( empty( $options['enabled'] ) || is_admin() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$gallery = $product->get_gallery_image_ids();

		if ( empty( $gallery ) ) {
			return;
		}

		$index = absint( $options['gallery_index'] );

		if ( empty( $gallery[ $index ] ) ) {
			return;
		}

		self::$second_image_map[ $product->get_id() ] = absint( $gallery[ $index ] );
	}

	public static function hook_print_second_image(): void {
		$options = self::get_options();

		if ( empty( $options['enabled'] ) || is_admin() ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( empty( self::$second_image_map[ $product_id ] ) ) {
			return;
		}

		$second_id = absint( self::$second_image_map[ $product_id ] );
		$size      = sanitize_key( $options['hook_img_size'] ?: 'medium_large' );

		echo wp_get_attachment_image(
			$second_id,
			$size,
			false,
			array(
				'class'    => 'aaweb-hs-secondary',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'alt'      => wp_strip_all_tags( get_the_title( $product_id ) ),
			)
		);
	}

	public static function hook_cleanup_second_image_id(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		unset( self::$second_image_map[ $product->get_id() ] );
	}

	public static function ajax_second_image(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'reason' => 'missing_product_id' ), 400 );
		}

		if ( ! self::is_woocommerce_active() ) {
			wp_send_json_error( array( 'reason' => 'woocommerce_not_available' ), 400 );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( array( 'reason' => 'product_not_found' ), 200 );
		}

		$options = self::get_options();
		$index   = absint( $options['gallery_index'] );
		$gallery = $product->get_gallery_image_ids();

		if ( empty( $gallery ) || empty( $gallery[ $index ] ) ) {
			wp_send_json_error( array( 'reason' => 'second_image_not_found' ), 200 );
		}

		$size = sanitize_key( $options['hook_img_size'] ?: 'medium_large' );
		$url  = wp_get_attachment_image_url( absint( $gallery[ $index ] ), $size );

		if ( ! $url ) {
			wp_send_json_error( array( 'reason' => 'image_url_not_found' ), 200 );
		}

		wp_send_json_success(
			array(
				'url' => esc_url_raw( $url ),
			)
		);
	}

	private static function get_frontend_js(): string {
		return <<<'JS'
(function(){
	'use strict';

	const CFG = window.AAWEBHoverSwapConfig || {};

	if (!CFG.item || !CFG.link || !CFG.img) {
		return;
	}

	const memory = new Map();
	let queue = [];
	let running = 0;

	function qsa(root, selector) {
		try {
			return Array.from(root.querySelectorAll(selector));
		} catch (error) {
			return [];
		}
	}

	function extractProductId(card) {
		const className = card.className || '';
		let match = String(className).match(/\bpost-(\d+)\b/);

		if (match) {
			return match[1];
		}

		const dataId =
			card.getAttribute('data-product_id') ||
			card.getAttribute('data-productid') ||
			card.getAttribute('data-product-id');

		if (dataId && /^\d+$/.test(dataId)) {
			return dataId;
		}

		const productButton = card.querySelector('[data-product_id], [data-product-id]');
		if (productButton) {
			const buttonId =
				productButton.getAttribute('data-product_id') ||
				productButton.getAttribute('data-product-id');

			if (buttonId && /^\d+$/.test(buttonId)) {
				return buttonId;
			}
		}

		const addToCart = card.querySelector('a[href*="add-to-cart="], button[name="add-to-cart"]');

		if (addToCart) {
			const href = addToCart.getAttribute('href') || '';
			match = href.match(/add-to-cart=(\d+)/);

			if (match) {
				return match[1];
			}

			const value = addToCart.getAttribute('value');
			if (value && /^\d+$/.test(value)) {
				return value;
			}
		}

		return null;
	}

	function ensureImageWrapper(link) {
		if (link.querySelector('.aaweb-hs-imgwrap')) {
			return;
		}

		const firstImage = link.querySelector(CFG.img || 'img.wp-post-image, img.attachment-woocommerce_thumbnail, img');

		if (!firstImage) {
			return;
		}

		let secondImage = link.querySelector('img.aaweb-hs-secondary');

		if (!secondImage) {
			const images = Array.from(link.querySelectorAll('img'));
			if (images.length >= 2) {
				secondImage = images[1];
			}
		}

		if (!secondImage || firstImage === secondImage) {
			return;
		}

		const wrapper = document.createElement('span');
		wrapper.className = 'aaweb-hs-imgwrap';

		firstImage.parentNode.insertBefore(wrapper, firstImage);
		wrapper.appendChild(firstImage);
		wrapper.appendChild(secondImage);
	}

	function scanWrap(root) {
		qsa(root, CFG.item).forEach(function(card){
			const link = card.querySelector(CFG.link);
			if (!link) {
				return;
			}

			ensureImageWrapper(link);
		});
	}

	function fetchSecondImage(productId) {
		const url = CFG.ajaxUrl +
			'?action=aaweb_hover_swap_second_image' +
			'&id=' + encodeURIComponent(productId) +
			'&nonce=' + encodeURIComponent(CFG.nonce || '');

		const controller = new AbortController();
		const timer = setTimeout(function(){
			controller.abort();
		}, parseInt(CFG.timeout, 10) || 8000);

		return fetch(url, {
			credentials: 'same-origin',
			signal: controller.signal
		})
			.then(function(response){
				return response.json();
			})
			.then(function(json){
				clearTimeout(timer);

				if (!json || !json.success || !json.data || !json.data.url) {
					return null;
				}

				return json.data.url;
			})
			.catch(function(){
				clearTimeout(timer);
				return null;
			});
	}

	function enqueue(productId, link) {
		if (memory.has(productId)) {
			injectSecondImage(link, memory.get(productId));
			return;
		}

		queue.push({
			productId: productId,
			link: link
		});

		pump();
	}

	function pump() {
		const concurrency = Math.max(1, Math.min(12, parseInt(CFG.conc, 10) || 4));

		while (running < concurrency && queue.length) {
			const job = queue.shift();
			running++;

			fetchSecondImage(job.productId)
				.then(function(url){
					if (url) {
						memory.set(job.productId, url);
						injectSecondImage(job.link, url);
					}
				})
				.finally(function(){
					running--;
					pump();
				});
		}
	}

	function injectSecondImage(link, url) {
		const images = qsa(link, 'img');

		if (images.length >= 2) {
			ensureImageWrapper(link);
			return;
		}

		const firstImage = images[0];

		if (!firstImage) {
			return;
		}

		const secondImage = document.createElement('img');
		secondImage.src = url;
		secondImage.className = 'aaweb-hs-secondary';
		secondImage.loading = 'lazy';
		secondImage.decoding = 'async';
		secondImage.alt = firstImage.getAttribute('alt') || '';

		firstImage.insertAdjacentElement('afterend', secondImage);
		ensureImageWrapper(link);
	}

	function scanAjax(root) {
		qsa(root, CFG.item).forEach(function(card){
			if (card.dataset.aawebHsAjaxDone === '1') {
				return;
			}

			const link = card.querySelector(CFG.link);

			if (!link) {
				return;
			}

			const images = qsa(link, 'img');

			if (images.length !== 1) {
				card.dataset.aawebHsAjaxDone = '1';

				if (images.length >= 2) {
					ensureImageWrapper(link);
				}

				return;
			}

			const productId = extractProductId(card);

			if (!productId) {
				card.dataset.aawebHsAjaxDone = '1';
				return;
			}

			card.dataset.aawebHsAjaxDone = '1';
			enqueue(productId, link);
		});
	}

	function scanAll(root) {
		scanWrap(root);
		scanAjax(root);
	}

	function boot() {
		scanAll(document);

		if (window.MutationObserver) {
			const observer = new MutationObserver(function(mutations){
				mutations.forEach(function(mutation){
					if (!mutation.addedNodes) {
						return;
					}

					mutation.addedNodes.forEach(function(node){
						if (!(node instanceof HTMLElement)) {
							return;
						}

						scanAll(node);
					});
				});
			});

			observer.observe(document.documentElement, {
				childList: true,
				subtree: true
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
JS;
	}
}

register_activation_hook( __FILE__, array( 'AAWEB_Universal_Woo_Hover_Swap', 'activate' ) );

AAWEB_Universal_Woo_Hover_Swap::boot();
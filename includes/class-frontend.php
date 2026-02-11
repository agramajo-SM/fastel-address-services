<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Frontend {

	public function __construct() {
		add_shortcode( 'avalaunch_address_search', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_avalaunch_get_services', array( $this, 'ajax_get_services' ) );
		add_action( 'wp_ajax_nopriv_avalaunch_get_services', array( $this, 'ajax_get_services' ) );
	}

	public function enqueue_scripts() {
		$options = get_option( 'avalaunch_options' );
		$google_api_key = isset( $options['google_maps_api_key'] ) ? $options['google_maps_api_key'] : '';

		wp_enqueue_script(
			'avalaunch-frontend-js',
			AVALAUNCH_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			'1.0.5',
			true
		);

		wp_localize_script( 'avalaunch-frontend-js', 'avalaunch_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'avalaunch_search_nonce' )
		) );

		if ( ! empty( $google_api_key ) ) {
			wp_enqueue_script(
				'google-maps-places',
				'https://maps.googleapis.com/maps/api/js?key=' . esc_attr( $google_api_key ) . '&libraries=places&loading=async&callback=initAvalaunchMap',
				array( 'avalaunch-frontend-js' ),
				null,
				true
			);
		}

		wp_enqueue_style(
			'avalaunch-frontend-css',
			AVALAUNCH_PLUGIN_URL . 'assets/css/style.css',
			array(),
			'1.1.1'
		);
	}

	public function render_shortcode( $atts ) {
		ob_start();
		?>
		<div id="avalaunch-address-search-container">
			<label for="avalaunch-address-input">Search for your address:</label>
			<input type="text" id="avalaunch-address-input" placeholder="Start typing your address..." autocomplete="off">
			<div id="avalaunch-search-results"></div>
			<div id="avalaunch-services-results" style="display:none;"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_get_services() {
		check_ajax_referer( 'avalaunch_search_nonce', 'nonce' );

		$address_data = isset( $_POST['address_data'] ) ? $_POST['address_data'] : array();

		if ( empty( $address_data ) ) {
			wp_send_json_error( 'No address data provided' );
		}

		$salesforce = new Avalaunch_Salesforce();
		$services = $salesforce->get_services_by_address( $address_data );

		if ( is_wp_error( $services ) ) {
			wp_send_json_error( $services->get_error_message() );
		}

		wp_send_json_success( $services );
	}
}

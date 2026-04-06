<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Frontend {

	public function __construct() {
		add_shortcode( 'avalaunch_address_search', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_avalaunch_get_services',        array( $this, 'ajax_get_services' ) );
		add_action( 'wp_ajax_nopriv_avalaunch_get_services', array( $this, 'ajax_get_services' ) );
		add_action( 'wp_ajax_avalaunch_create_lead',         array( $this, 'ajax_create_lead' ) );
		add_action( 'wp_ajax_nopriv_avalaunch_create_lead',  array( $this, 'ajax_create_lead' ) );
	}

	public function enqueue_scripts() {
		$options        = get_option( 'avalaunch_options' );
		$google_api_key = isset( $options['google_maps_api_key'] ) ? $options['google_maps_api_key'] : '';

		wp_enqueue_script(
			'avalaunch-frontend-js',
			AVALAUNCH_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			time(), // Versión por tiempo para evitar caché en desarrollo
			true
		);

		wp_localize_script( 'avalaunch-frontend-js', 'avalaunch_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'avalaunch_search_nonce' ),
		) );

		if ( ! empty( $google_api_key ) ) {
			wp_enqueue_script(
				'google-maps-places',
				'https://maps.googleapis.com/maps/api/js?key=' . esc_attr( $google_api_key ) . '&libraries=places&v=weekly&loading=async&callback=initAvalaunchMap',
				array( 'avalaunch-frontend-js' ),
				null,
				true
			);
		}

		wp_enqueue_style(
			'avalaunch-frontend-css',
			AVALAUNCH_PLUGIN_URL . 'assets/css/style.css',
			array(),
			time()
		);
	}

	public function render_shortcode( $atts ) {
		ob_start();
		?>
		<div id="avalaunch-address-search-wrapper">

			<div class="aas-card" id="aas-search-card">
				<p class="aas-section-label">CHECK FIBER AVAILABILITY:</p>
				<div class="aas-search-row">
					<div id="avalaunch-place-container">
						<input type="text" id="avalaunch-address-input" placeholder="Enter your address...">
					</div>
					<button id="aas-search-btn" type="button">Check Coverage</button>
				</div>
			</div>

			<div class="aas-card" id="aas-results-card" style="display:none;">
				<div id="avalaunch-services-results">
					</div>
				<div class="aas-results-footer">
					<a href="#" id="aas-clear-results">Search another address</a>
				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_get_services() {
		check_ajax_referer( 'avalaunch_search_nonce', 'nonce' );

		$lat = isset( $_POST['lat'] ) ? floatval( $_POST['lat'] ) : null;
		$lng = isset( $_POST['lng'] ) ? floatval( $_POST['lng'] ) : null;

		if ( is_null( $lat ) || is_null( $lng ) ) {
			wp_send_json_error( array( 'message' => 'Coordinates are required to check coverage.' ) );
		}

		$salesforce = new Avalaunch_Salesforce();
		$result     = $salesforce->get_services_by_coordinates( $lat, $lng );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	public function ajax_create_lead() {
		check_ajax_referer( 'avalaunch_search_nonce', 'nonce' );

		$email   = isset( $_POST['email'] )   ? sanitize_email( $_POST['email'] )         : '';
		$address = isset( $_POST['address'] ) ? sanitize_text_field( $_POST['address'] )  : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
		}

		if ( empty( $address ) ) {
			wp_send_json_error( array( 'message' => 'Address is missing.' ) );
		}

		$salesforce = new Avalaunch_Salesforce();
		$result     = $salesforce->create_lead( $email, $address );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Thank you! We will notify you when service becomes available.' ) );
	}
}
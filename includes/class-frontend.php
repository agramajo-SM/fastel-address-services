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
		$options        = get_option( 'avalaunch_options' );
		$google_api_key = isset( $options['google_maps_api_key'] ) ? $options['google_maps_api_key'] : '';

		wp_enqueue_script(
			'avalaunch-frontend-js',
			AVALAUNCH_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			'2.5.0',
			true
		);

		wp_localize_script( 'avalaunch-frontend-js', 'avalaunch_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'avalaunch_search_nonce' )
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
			'2.5.0'
		);
	}

	public function render_shortcode( $atts ) {
		ob_start();
		?>
		<div id="avalaunch-address-search-wrapper">

			<!-- ① Search Card -->
			<div class="aas-card" id="aas-search-card">
				<p class="aas-section-label">SEARCH FOR YOUR ADDRESS:</p>
				<div class="aas-search-row">
					<div id="avalaunch-place-container">
						<!-- input injected by JS -->
					</div>
					<div class="aas-radius-group">
						<label class="aas-radius-label" for="avalaunch-radius">Search within:</label>
						<select id="avalaunch-radius">
							<option value="5">5 miles</option>
							<option value="10">10 miles</option>
							<option value="25">25 miles</option>
							<option value="50" selected>50 miles</option>
							<option value="100">100 miles</option>
						</select>
					</div>
					<button id="aas-search-btn" type="button">Search</button>
				</div>
			</div>

			<!-- ② Results Card (hidden until results arrive) -->
			<div class="aas-card" id="aas-results-card" style="display:none;">
				<div class="aas-results-header">
					<span id="aas-results-count"></span>
					<a href="#" id="aas-clear-results">Clear Results</a>
				</div>
				<div id="avalaunch-services-results"></div>
			</div>

			<!-- ③ Map Card -->
			<div class="aas-card aas-map-card" id="aas-map-card">
				<svg class="aas-map-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
					<line x1="8" y1="2" x2="8" y2="18"/>
					<line x1="16" y1="6" x2="16" y2="22"/>
				</svg>
				<p class="aas-map-label">Map View Loading...</p>
			</div>

			<!-- ④ Account Details Modal (hidden, populated by JS) -->
			<div id="aas-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="aas-modal-title" style="display:none;">
				<div id="aas-modal">
					<div id="aas-modal-header">
						<div>
							<p id="aas-modal-type"></p>
							<h2 id="aas-modal-title"></h2>
						</div>
						<button id="aas-modal-close" aria-label="Close">&times;</button>
					</div>

					<div id="aas-modal-body">

						<!-- Distance pill -->
						<div id="aas-modal-distance-row"></div>

						<!-- Contact -->
						<div class="aas-modal-section">
							<h3 class="aas-modal-section-title">Contact</h3>
							<div class="aas-modal-grid">
								<div class="aas-modal-field" id="aas-modal-field-phone" style="display:none;">
									<span class="aas-modal-field-label">Phone</span>
									<span class="aas-modal-field-value" id="aas-modal-phone"></span>
								</div>
								<div class="aas-modal-field" id="aas-modal-field-website" style="display:none;">
									<span class="aas-modal-field-label">Website</span>
									<a class="aas-modal-field-value aas-modal-link" id="aas-modal-website" href="#" target="_blank" rel="noopener"></a>
								</div>
							</div>
						</div>

						<!-- Address -->
						<div class="aas-modal-section">
							<h3 class="aas-modal-section-title">Billing Address</h3>
							<div class="aas-modal-grid">
								<div class="aas-modal-field" id="aas-modal-field-street" style="display:none;">
									<span class="aas-modal-field-label">Street</span>
									<span class="aas-modal-field-value" id="aas-modal-street"></span>
								</div>
								<div class="aas-modal-field" id="aas-modal-field-city">
									<span class="aas-modal-field-label">City / State</span>
									<span class="aas-modal-field-value" id="aas-modal-citystate"></span>
								</div>
								<div class="aas-modal-field" id="aas-modal-field-zip" style="display:none;">
									<span class="aas-modal-field-label">ZIP</span>
									<span class="aas-modal-field-value" id="aas-modal-zip"></span>
								</div>
								<div class="aas-modal-field" id="aas-modal-field-country" style="display:none;">
									<span class="aas-modal-field-label">Country</span>
									<span class="aas-modal-field-value" id="aas-modal-country"></span>
								</div>
							</div>
						</div>

						<!-- Company -->
						<div class="aas-modal-section" id="aas-modal-company-section">
							<h3 class="aas-modal-section-title">Company</h3>
							<div class="aas-modal-grid">
								<div class="aas-modal-field" id="aas-modal-field-industry" style="display:none;">
									<span class="aas-modal-field-label">Industry</span>
									<span class="aas-modal-field-value" id="aas-modal-industry"></span>
								</div>
								<div class="aas-modal-field" id="aas-modal-field-employees" style="display:none;">
									<span class="aas-modal-field-label">Employees</span>
									<span class="aas-modal-field-value" id="aas-modal-employees"></span>
								</div>
							</div>
						</div>

					</div><!-- /#aas-modal-body -->

				</div><!-- /#aas-modal -->
			</div><!-- /#aas-modal-overlay -->

		</div>

		<?php
		return ob_get_clean();
	}

	public function ajax_get_services() {
		check_ajax_referer( 'avalaunch_search_nonce', 'nonce' );

		$address_data = isset( $_POST['address_data'] ) ? $_POST['address_data'] : array();
		$lat          = isset( $_POST['lat'] )          ? floatval( $_POST['lat'] )  : null;
		$lng          = isset( $_POST['lng'] )          ? floatval( $_POST['lng'] )  : null;
		$radius_miles = isset( $_POST['radius_miles'] ) ? intval( $_POST['radius_miles'] ) : 25;

		// Validate radius to allowed values
		$allowed_radii = array( 5, 10, 25, 50, 100 );
		if ( ! in_array( $radius_miles, $allowed_radii ) ) {
			$radius_miles = 25;
		}

		$salesforce = new Avalaunch_Salesforce();

		if ( ! is_null( $lat ) && ! is_null( $lng ) ) {
			$services = $salesforce->get_services_by_location( $lat, $lng, $radius_miles );
		} elseif ( ! empty( $address_data['postal_code'] ) ) {
			$services = $salesforce->get_services_by_address( $address_data );
		} else {
			wp_send_json_error( array( 'message' => 'No location data provided.' ) );
			return;
		}

		if ( is_wp_error( $services ) ) {
			wp_send_json_error( array( 'message' => $services->get_error_message() ) );
		}

		wp_send_json_success( $services );
	}
}

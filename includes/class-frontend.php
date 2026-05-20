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
					<input type="text" id="avalaunch-unit-input" class="aas-unit-input" placeholder="Unit / Apt" aria-label="Unit or apartment number (optional)">
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

			<!-- Contact Modal -->
			<div id="aas-contact-modal-overlay" style="display:none;">
				<div id="aas-contact-modal">

					<!-- Close -->
					<button id="aas-contact-modal-close" aria-label="Close">&times;</button>

					<!-- Top Badge -->
					<div class="aas-cm-badge">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
						Fiber Available at Your Address
					</div>

					<!-- Headline -->
					<h2 class="aas-cm-title">Ready to Get Started?</h2>
					<p class="aas-cm-subtitle">Our team is standing by to connect you. Reach out through any of the options below.</p>

					<!-- Divider -->
					<div class="aas-cm-divider"></div>

					<!-- Contact Options -->
					<div class="aas-cm-grid">

						<!-- Phone card -->
						<div class="aas-cm-option">
							<div class="aas-cm-icon-wrap aas-cm-icon-blue">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
								</svg>
							</div>
							<div class="aas-cm-option-body">
								<span class="aas-cm-option-label">Call Us</span>
								<a href="tel:8013223278" class="aas-cm-option-primary">801-322-3278 <span class="aas-cm-option-tag">Local</span></a>
								<a href="tel:8556327835" class="aas-cm-option-secondary">855-632-7835 <span class="aas-cm-option-tag">Toll-Free</span></a>
							</div>
							<a href="tel:8013223278" class="aas-cm-cta-btn">Call Now</a>
						</div>

						<!-- Email card -->
						<div class="aas-cm-option">
							<div class="aas-cm-icon-wrap aas-cm-icon-blue">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
								</svg>
							</div>
							<div class="aas-cm-option-body">
								<span class="aas-cm-option-label">Email Us</span>
								<a href="mailto:customerservice@fastel.com" class="aas-cm-option-primary" style="font-size:14px;">customerservice@fastel.com</a>
								<!-- <span class="aas-cm-option-secondary" style="font-size:12px; opacity:.6;">We reply within 1 business day</span> -->
							</div>
							<a href="mailto:customerservice@fastel.com" class="aas-cm-cta-btn">Send Email</a>
						</div>

					</div>

					<!-- Footer note -->
					<!-- <p class="aas-cm-footer-note">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
						Mon – Fri, 8 AM – 6 PM MT
					</p> -->

				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_get_services() {
		check_ajax_referer( 'avalaunch_search_nonce', 'nonce' );

		$street_number   = isset( $_POST['street_number'] )   ? sanitize_text_field( $_POST['street_number'] )   : '';
		$street_keyword  = isset( $_POST['street_keyword'] )  ? sanitize_text_field( $_POST['street_keyword'] )  : '';
		$street_keyword2 = isset( $_POST['street_keyword2'] ) ? sanitize_text_field( $_POST['street_keyword2'] ) : '';
		$unit            = isset( $_POST['unit'] )            ? sanitize_text_field( $_POST['unit'] )            : '';
		$place_id        = isset( $_POST['place_id'] )        ? sanitize_text_field( $_POST['place_id'] )        : '';

		if ( empty( $street_number ) || empty( $street_keyword ) ) {
			wp_send_json_error( array( 'message' => 'A valid street address is required.' ) );
		}

		$salesforce = new Avalaunch_Salesforce();
		$result     = $salesforce->get_services_by_address( $street_number, $street_keyword, $unit, $street_keyword2, $place_id );

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
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Salesforce {

	private $token_transient_key = 'avalaunch_sf_access_token';

	public function get_services_by_location( $lat, $lng, $radius_miles = 25 ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		$lat   = floatval( $lat );
		$lng   = floatval( $lng );
		$radius = intval( $radius_miles );

		$query = sprintf(
			"SELECT Id, Name, BillingCity, BillingState, BillingPostalCode, BillingLatitude, BillingLongitude " .
			"FROM Account " .
			"WHERE DISTANCE(BillingAddress, GEOLOCATION(%f, %f), 'mi') < %d " .
			"ORDER BY DISTANCE(BillingAddress, GEOLOCATION(%f, %f), 'mi') ASC " .
			"LIMIT 50",
			$lat, $lng, $radius,
			$lat, $lng
		);

		$url = $instance_url . '/services/data/v57.0/query/?q=' . urlencode( $query );

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data[0]['errorCode'] ) ) {
			return new WP_Error( 'sf_api_error', $data[0]['message'] );
		}

		if ( empty( $data['records'] ) ) {
			return array();
		}

		$accounts = array();
		foreach ( $data['records'] as $record ) {
			// Calculate distance for display
			$dist = null;
			if ( ! empty( $record['BillingLatitude'] ) && ! empty( $record['BillingLongitude'] ) ) {
				$dist = $this->haversine_distance( $lat, $lng, $record['BillingLatitude'], $record['BillingLongitude'] );
			}

			$accounts[] = array(
				'Id'                => $record['Id'],
				'Name'              => $record['Name'],
				'BillingCity'       => isset( $record['BillingCity'] ) ? $record['BillingCity'] : '',
				'BillingState'      => isset( $record['BillingState'] ) ? $record['BillingState'] : '',
				'BillingPostalCode' => isset( $record['BillingPostalCode'] ) ? $record['BillingPostalCode'] : '',
				'BillingLatitude'   => isset( $record['BillingLatitude'] ) ? floatval( $record['BillingLatitude'] ) : null,
				'BillingLongitude'  => isset( $record['BillingLongitude'] ) ? floatval( $record['BillingLongitude'] ) : null,
				'distance_miles'    => $dist ? round( $dist, 1 ) : null,
			);
		}

		return $accounts;
	}

	/**
	 * Haversine formula to calculate distance between two lat/lng points in miles.
	 */
	private function haversine_distance( $lat1, $lng1, $lat2, $lng2 ) {
		$earth_radius = 3958.8; // miles
		$dLat = deg2rad( $lat2 - $lat1 );
		$dLng = deg2rad( $lng2 - $lng1 );
		$a = sin( $dLat / 2 ) * sin( $dLat / 2 ) +
		     cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
		     sin( $dLng / 2 ) * sin( $dLng / 2 );
		return $earth_radius * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	public function get_services_by_address( $address_data ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$options = get_option( 'avalaunch_options' );
		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		if ( empty( $address_data['postal_code'] ) ) {
			return new WP_Error( 'sf_missing_zip', 'Postal code is missing from address data.' );
		}

		// Query Accounts by BillingPostalCode
		$query = sprintf(
			"SELECT Id, Name, BillingCity, BillingState, BillingPostalCode FROM Account WHERE BillingPostalCode = '%s'",
			esc_sql( $address_data['postal_code'] )
		);
		
		// Encode the query
		$url = $instance_url . '/services/data/v57.0/query/?q=' . urlencode( $query );

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data[0]['errorCode'] ) ) {
			return new WP_Error( 'sf_api_error', $data[0]['message'] );
		}
		
		// If no results, return empty array
		if ( empty( $data['records'] ) ) {
			return array();
		}
		
		// Process records to return a list of accounts
		$accounts = array();
		foreach( $data['records'] as $record ) {
			$accounts[] = array(
				'Id' => $record['Id'],
				'Name' => $record['Name'],
				'BillingCity' => isset($record['BillingCity']) ? $record['BillingCity'] : '',
				'BillingState' => isset($record['BillingState']) ? $record['BillingState'] : '',
				'BillingPostalCode' => isset($record['BillingPostalCode']) ? $record['BillingPostalCode'] : '',
			);
		}

		return $accounts;
	}

	public function test_connection() {
		// Clear existing transient to force a fresh token request for the test
		delete_transient( $this->token_transient_key );
		
		$response = $this->request_token();

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'debug'   => array(
					'status'  => 'WP Error',
					'headers' => array(),
					'body'    => $response->get_error_message(),
				),
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$headers       = wp_remote_retrieve_headers( $response )->getAll();
		$data          = json_decode( $response_body, true );

		if ( 200 !== $response_code ) {
			return array(
				'success' => false,
				'message' => 'Salesforce Error (' . $response_code . ')',
				'debug'   => array(
					'status'  => $response_code,
					'headers' => $headers,
					'body'    => $response_body,
				),
			);
		}

		if ( isset( $data['access_token'] ) ) {
			return array(
				'success' => true,
				'message' => 'Connection successful.',
				'debug'   => array(
					'status'  => $response_code,
					'headers' => $headers,
					'body'    => $response_body,
				),
			);
		}

		return array(
			'success' => false,
			'message' => 'Unknown specific error.',
			'debug'   => array(
				'status'  => $response_code,
				'headers' => $headers,
				'body'    => $response_body,
			),
		);
	}

	private function get_access_token() {
		$token = get_transient( $this->token_transient_key );

		if ( $token ) {
			return $token;
		}

		$response = $this->request_token();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = null;

		if ( ! empty( $response_body ) ) {
			$data = json_decode( $response_body, true );
		}

		if ( 200 !== $response_code ) {
			$error_message = 'Salesforce Error (' . $response_code . ')';
			
			if ( isset( $data['error'] ) ) {
				$error_message .= ': ' . $data['error'];
			}
			
			if ( isset( $data['error_description'] ) ) {
				$error_message .= ' - ' . $data['error_description'];
			} elseif ( is_array( $data ) && isset( $data[0]['message'] ) ) {
				// Sometimes errors come as array of objects
				$error_message .= ': ' . $data[0]['message'];
			} elseif ( ! $data && ! empty( $response_body ) ) {
				// If not JSON, append start of body
				$error_message .= ': ' . substr( strip_tags( $response_body ), 0, 100 ) . '...';
			}

			return new WP_Error( 'sf_auth_failed', $error_message );
		}

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'sf_auth_error', $data['error_description'] );
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'sf_no_token', 'No access token received from Salesforce.' );
		}

		$access_token = $data['access_token'];
		$instance_url = $data['instance_url'];

		// Cache token for 1 hour (minimize API calls)
		set_transient( $this->token_transient_key, $access_token, 3600 );
		set_transient( 'avalaunch_sf_instance_url', $instance_url, 3600 );

		return $access_token;
	}

	private function request_token() {
		$options = get_option( 'avalaunch_options' );
		
		if ( ! empty( $options['sf_custom_login_url'] ) ) {
			$token_url = $options['sf_custom_login_url'];
		} else {
			// Fallback to standard production URL if custom URL is empty
			$token_url = 'https://login.salesforce.com/services/oauth2/token';
		}

		$body = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $options['sf_client_id'],
			'client_secret' => $options['sf_client_secret'],
		);

		return wp_remote_post( $token_url, array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body' => $body,
		) );
	}
}

<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_API_Client {

	private const TOKEN_URL  = 'https://www.eagleagent.com.au/api/v3/token';
	private const GRAPHQL_URL = 'https://www.eagleagent.com.au/api/v3/graphql';

	public const QUERY = <<<'GRAPHQL'
query SyncProperties($limit: Int!, $offset: Int!, $listingTypes: [ListingTypeEnum!], $statuses: [PropertyStatusEnum!]) {
  properties(
    limit: $limit, offset: $offset,
    listingType: $listingTypes, status: $statuses
  ) {
    totalCount
    nodes {
      id reaId headline description formattedAddress
      streetNo street unit municipality country lotNo latitude longitude
      price advertisedPrice showPrice saleOrLease listingType status propertyType
      daysOnMarket featured thumbnailSquare videoUrl onlineTour1Url onlineTour2Url
      bookInspectionLink brochureTitle
      landSize
      agencyReference externalPropertyTree
      auctionDatetime listingExpiryDate letDate soldDate rentalDateAvailable
      soldPrice soldDisplay offMarketAt createdAt updatedAt activeAt withdrawnAt
      numberOfWebsiteViews numEnquiries numOffers
      customFields { key name fieldType value allowedValues }
      images { id position url }
      floorplans { id position url }
      agents { id name title email phone mobile avatarUrl office { id name } }
      office { id name }
      address { formattedFullAddress }
      listingDetails {
        ... on ResidentialSale { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes propertyType status establishedOrDevelopment energyEfficiencyRating heatingCoolingFeatures indoorFeatures outdoorFeatures ecoFriendlyFeatures }
        ... on ResidentialRental { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes propertyType status rentalPerWeek rentalPerMonth bond holidayRental heatingCoolingFeatures indoorFeatures outdoorFeatures ecoFriendlyFeatures }
        ... on Commercial { commercialPropertyType commercialListingType floorArea leaseExpiryDate leaseTerm outgoings tax tenancy zoning occupancyTitle parkingComments warehouseArea officeArea psmPaMin psmPaMax returnOnInvestment totalCarSpaces price status }
        ... on Rural { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes propertyType status carryingCapacity fencing irrigation annualRainfall soilTypes councilRates improvements price }
        ... on Land { price status crossOver frontage leftDepth rightDepth rearDepth }
        ... on Business { businessName propertyType status price saleOrTender tenderDate terms floorArea }
      }
    }
  }
}
GRAPHQL;

	// ---------------------------------------------------------------------
	// Credentials
	// ---------------------------------------------------------------------

	public static function get_client_id(): string {
		return (string) get_option( MES_OPTION_CLIENT_ID, '' );
	}

	public static function get_client_secret(): string {
		$stored = (string) get_option( MES_OPTION_CLIENT_SECRET, '' );
		if ( '' === $stored ) {
			return '';
		}

		$decrypted = self::decrypt( $stored );
		if ( null === $decrypted ) {
			return ''; // corrupted value; treat as missing
		}

		return $decrypted;
	}

	public static function has_credentials(): bool {
		return self::get_client_id() !== '' && self::get_client_secret() !== '';
	}

	public static function save_credentials( string $clientId, string $clientSecret ): void {
		update_option( MES_OPTION_CLIENT_ID, sanitize_text_field( $clientId ), false );

		if ( $clientSecret !== '' ) {
			update_option( MES_OPTION_CLIENT_SECRET, self::encrypt( $clientSecret ), false );
		}

		// Never keep a token issued for credentials that may just have changed.
		delete_transient( MES_TRANSIENT_TOKEN );
	}

	// ---------------------------------------------------------------------
	// Encryption at rest (AES-256-CBC with AUTH_KEY / AUTH_SALT)
	// ---------------------------------------------------------------------

	private static function encrypt( string $plain ): string {
		if ( function_exists( 'openssl_encrypt' ) && defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ) {
			$key = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
			$iv  = random_bytes( 16 );
			$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, 0, $iv );

			return 'enc:' . base64_encode( $iv ) . ':' . $cipher;
		}

		return 'plain:' . $plain;
	}

	private static function decrypt( string $stored ): ?string {
		if ( strpos( $stored, 'enc:' ) === 0 ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}

			$parts = explode( ':', $stored, 3 );
			if ( count( $parts ) !== 3 ) {
				return null;
			}

			$key  = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
			$iv   = base64_decode( $parts[1], true );
			$text = openssl_decrypt( $parts[2], 'aes-256-cbc', $key, 0, $iv );

			return is_string( $text ) ? $text : null;
		}

		if ( strpos( $stored, 'plain:' ) === 0 ) {
			return substr( $stored, 6 );
		}

		return null;
	}

	// ---------------------------------------------------------------------
	// Token
	// ---------------------------------------------------------------------

	public function get_token() {
		$cached = get_transient( MES_TRANSIENT_TOKEN );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		if ( ! self::has_credentials() ) {
			return new WP_Error( 'mes_no_credentials', 'Client ID/Secret are not configured.' );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 12,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::get_client_id() . ':' . self::get_client_secret(),
				],
				'body'    => '{}',
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mes_token_transport',
				'The site could not reach Eagle to obtain a token: ' . $response->get_error_message(),
				[ 'retryable' => true, 'retry_after' => 2 ]
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $status || $status >= 500 ) {
			return new WP_Error(
				'mes_token_unavailable',
				sprintf( 'Eagle token service is temporarily unavailable (HTTP %d).', $status ),
				[ 'retryable' => true, 'retry_after' => $this->retry_after( $response ) ]
			);
		}

		if ( 200 === $status && ! is_array( $body ) ) {
			return new WP_Error(
				'mes_token_invalid_response',
				'Eagle returned an invalid token response.',
				[ 'retryable' => true, 'retry_after' => 2 ]
			);
		}

		if ( 200 !== $status || empty( $body['data']['token']['token'] ) ) {
			return new WP_Error(
				'mes_token_failed',
				sprintf( 'Token request failed (HTTP %d). Check your credentials.', $status ),
				[ 'retryable' => false, 'status' => $status ]
			);
		}

		$token      = $body['data']['token']['token'];
		$expiresAt  = (int) ( $body['data']['token']['expiresAt'] ?? ( time() + 3600 ) );
		$expiresIn  = max( 60, $expiresAt - time() - 60 );
		set_transient( MES_TRANSIENT_TOKEN, $token, $expiresIn );

		return $token;
	}

	// ---------------------------------------------------------------------
	// GraphQL
	// ---------------------------------------------------------------------

	public function graphql( string $query, array $variables = [] ) {
		$token = $this->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->post_graphql( $token, $query, $variables );

		if ( is_wp_error( $response ) ) {
			$code = $response->get_error_code();
			if ( 'mes_unauthorized' === $code ) {
				// Token may have expired mid-run; refresh once and retry.
				delete_transient( MES_TRANSIENT_TOKEN );
				$token = $this->get_token();
				if ( is_wp_error( $token ) ) {
					return $token;
				}

				$response = $this->post_graphql( $token, $query, $variables );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'mes_invalid_json',
				'Eagle returned an invalid JSON response.',
				[ 'retryable' => true, 'retry_after' => 2 ]
			);
		}

		if ( ! empty( $body['errors'] ) ) {
			$message = is_array( $body['errors'] ) ? wp_json_encode( $body['errors'] ) : 'Unknown GraphQL error';
			return new WP_Error( 'mes_graphql_error', $message );
		}

		if ( ! array_key_exists( 'data', $body ) || ! is_array( $body['data'] ) ) {
			return new WP_Error(
				'mes_missing_data',
				'Eagle response did not contain GraphQL data.',
				[ 'retryable' => true, 'retry_after' => 2 ]
			);
		}

		return $body['data'] ?? [];
	}

	private function post_graphql( string $token, string $query, array $variables ) {
		$response = wp_remote_post(
			self::GRAPHQL_URL,
			[
				// Keep one HTTP attempt bounded. The AJAX poll loop owns retries,
				// which preserves the current listing checkpoint between attempts.
				'timeout' => 12,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'Origin'        => 'https://www.eagleagent.com.au',
				],
				'body'    => wp_json_encode(
					[
						'query'     => $query,
						'variables' => $variables,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mes_graphql_transport',
				'The site could not reach Eagle: ' . $response->get_error_message(),
				[ 'retryable' => true, 'retry_after' => 2 ]
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status ) {
			return new WP_Error( 'mes_unauthorized', 'Unauthorized. Token may have expired.' );
		}

		if ( $status < 200 || $status >= 300 ) {
			$retryable = 429 === $status || $status >= 500;
			return new WP_Error(
				'mes_http_failed',
				sprintf( 'Eagle GraphQL request failed (HTTP %d).', $status ),
				[
					'status'      => $status,
					'retryable'   => $retryable,
					'retry_after' => $retryable ? $this->retry_after( $response ) : 0,
				]
			);
		}

		return $response;
	}

	/**
	 * Bounded Retry-After value from an Eagle HTTP response.
	 */
	private function retry_after( $response ): int {
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		return is_numeric( $value ) ? max( 1, min( 30, (int) $value ) ) : 2;
	}

	// ---------------------------------------------------------------------
	// Properties
	// ---------------------------------------------------------------------

	/**
	 * Fetch one page of properties.
	 *
	 * @return array|WP_Error ['totalCount' => int, 'nodes' => array]
	 */
	public function fetch_properties_page( int $limit, int $offset, array $listingTypes = [], array $statuses = [] ) {
		$data = $this->graphql(
			self::QUERY,
			[
				'limit'        => $limit,
				'offset'       => $offset,
				'listingTypes' => $listingTypes ?: null,
				'statuses'     => $statuses ?: null,
			]
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$props = $data['properties'] ?? [];
		if ( ! is_array( $props ) || ! isset( $props['nodes'] ) || ! is_array( $props['nodes'] ) ) {
			return new WP_Error( 'mes_invalid_properties', 'Eagle response did not contain a valid properties page.' );
		}

		return [
			'totalCount' => (int) ( $props['totalCount'] ?? 0 ),
			'nodes'      => $props['nodes'] ?? [],
		];
	}

	// ---------------------------------------------------------------------
	// Counting
	// ---------------------------------------------------------------------

	private const COUNT_QUERY = <<<'GRAPHQL'
query CountProperties($limit: Int!, $offset: Int!) {
  properties(limit: $limit, offset: $offset) {
    totalCount
    nodes { id status }
  }
}
GRAPHQL;

	/**
	 * Exact number of importable listings available via the API.
	 *
	 * totalCount is unreliable, so the count is derived by walking
	 * ids-only pages until an incomplete page is returned. Draft
	 * listings are excluded, matching the import (which skips them).
	 * Returns 0 when the first request fails (caller falls back to a
	 * stored total).
	 */
	public function count_properties(): int {
		$count  = 0;
		$offset = 0;
		$limit  = 50; // API caps page size at 50 nodes; totalCount is not a global total.

		while ( true ) {
			$data = $this->graphql(
				self::COUNT_QUERY,
				[
					'limit'  => $limit,
					'offset' => $offset,
				]
			);

			if ( is_wp_error( $data ) ) {
				return $offset > 0 ? $count : 0;
			}

			$nodes = $data['properties']['nodes'] ?? [];
			foreach ( $nodes as $node ) {
				if ( 'DRAFT' === (string) ( $node['status'] ?? '' ) ) {
					continue;
				}
				$count++;
			}

			if ( count( $nodes ) < $limit ) {
				break;
			}

			$offset += count( $nodes );
		}

		return $count;
	}
}

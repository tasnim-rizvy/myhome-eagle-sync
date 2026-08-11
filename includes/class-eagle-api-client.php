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
      bookInspectionLink brochureTitle keyLocation keyNumber alarmCode smsCode internalNotes
      agencyReference externalPropertyTree
      auctionDatetime listingExpiryDate letDate soldDate rentalDateAvailable
      soldPrice soldDisplay offMarketAt createdAt updatedAt activeAt withdrawnAt
      numberOfWebsiteViews numEnquiries numOffers
      customFields { key name fieldType value allowedValues }
      images { id position url }
      floorplans { id position url }
      agents { id name title email phone mobile office { id name } }
      office { id name }
      vendors { contact { id fullName company mobilePhone } }
      address { formattedFullAddress }
      listingDetails {
        ... on ResidentialSale { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes houseSizeUnits propertyType status establishedOrDevelopment energyEfficiencyRating }
        ... on ResidentialRental { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes houseSizeUnits propertyType status rentalPerWeek rentalPerMonth bond holidayRental }
        ... on Commercial { commercialPropertyType commercialListingType floorArea floorAreaUnits leaseExpiryDate leaseTerm outgoings tax tenancy zoning occupancyTitle parkingComments warehouseArea officeArea psmPaMin psmPaMax returnOnInvestment totalCarSpaces price status }
        ... on Rural { bedrooms bathrooms ensuites toilets livingAreas garageSpaces carportSpaces openCarSpaces houseSizes houseSizeUnits propertyType status carryingCapacity fencing irrigation annualRainfall soilTypes councilRates improvements price }
        ... on Land { price status crossOver frontage leftDepth rightDepth rearDepth }
        ... on Business { businessName propertyType status price saleOrTender tenderDate terms floorArea floorAreaUnits }
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
				'timeout' => 30,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::get_client_id() . ':' . self::get_client_secret(),
				],
				'body'    => '{}',
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body['data']['token']['token'] ) ) {
			return new WP_Error( 'mes_token_failed', sprintf( 'Token request failed (HTTP %d). Check your credentials.', $status ) );
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

		if ( ! empty( $body['errors'] ) ) {
			$message = is_array( $body['errors'] ) ? wp_json_encode( $body['errors'] ) : 'Unknown GraphQL error';
			return new WP_Error( 'mes_graphql_error', $message );
		}

		return $body['data'] ?? [];
	}

	private function post_graphql( string $token, string $query, array $variables ) {
		$attempts = 0;

		while ( $attempts < 3 ) {
			$attempts++;

			$response = wp_remote_post(
				self::GRAPHQL_URL,
				[
					'timeout' => 120,
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
				if ( $attempts < 3 ) {
					sleep( 2 * $attempts );
					continue;
				}
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );

			if ( 429 === $status && $attempts < 3 ) {
				sleep( 3 * $attempts );
				continue;
			}

			if ( 401 === $status ) {
				return new WP_Error( 'mes_unauthorized', 'Unauthorized. Token may have expired.' );
			}

			if ( $status >= 500 && $attempts < 3 ) {
				sleep( 3 * $attempts );
				continue;
			}

			return $response;
		}

		return new WP_Error( 'mes_http_failed', 'GraphQL request failed after retries.' );
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
    nodes { id }
  }
}
GRAPHQL;

	/**
	 * Exact number of listings available via the API.
	 *
	 * totalCount is unreliable, so the count is derived by walking
	 * ids-only pages until an incomplete page is returned. Returns 0
	 * when the first request fails (caller falls back to a stored total).
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
			$count += count( $nodes );

			if ( count( $nodes ) < $limit ) {
				break;
			}

			$offset += count( $nodes );
		}

		return $count;
	}
}

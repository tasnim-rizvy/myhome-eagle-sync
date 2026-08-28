<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Eagle API keys onto MyHome fields.
 *
 * Existing myhome_field posts are matched by slug first.  When no match is
 * found, a new field is created automatically so every piece of data the
 * Eagle API provides is imported.
 */
class Eagle_Field_Manager {

	public const DEFAULT_CURRENCY = 'AUD';
	private const FIELD_MAP_CACHE = 'mes_eagle_field_map_v5';

	/**
	 * Eagle keys whose name differs from the MyHome field slug.
	 *
	 * @var array<string,string>
	 */
	private const SLUG_ALIASES = [
		'houseSizes'     => 'property-size',
		'landSize'       => 'land-size',
		'garageSpaces'   => 'vehicle-spaces',
		'totalCarSpaces' => 'vehicle-spaces',
		'listingType'    => 'offer-type',
		'saleOrLease'    => 'offer-type',
		'propertyType'   => 'property-type',
	];

	/**
	 * Static reference table: eagle data key => [label, MyHome field type].
	 * Fields that don't exist on the site will be created automatically.
	 */
	public static function field_definitions(): array {
		return [
			// Identity / address (text) ------------------------------------
			'reaId'                   => [ __( 'REA ID', 'myhome-eagle-sync' ), 'text' ],
			'headline'                => [ __( 'Headline', 'myhome-eagle-sync' ), 'text' ],
			'description'             => [ __( 'Description', 'myhome-eagle-sync' ), 'text' ],
			'formattedAddress'        => [ __( 'Formatted Address', 'myhome-eagle-sync' ), 'text' ],
			'formattedFullAddress'    => [ __( 'Full Address', 'myhome-eagle-sync' ), 'text' ],
			'streetNo'                => [ __( 'Street Number', 'myhome-eagle-sync' ), 'text' ],
			'street'                  => [ __( 'Street Name', 'myhome-eagle-sync' ), 'text' ],
			'unit'                    => [ __( 'Unit', 'myhome-eagle-sync' ), 'text' ],
			'municipality'            => [ __( 'Suburb', 'myhome-eagle-sync' ), 'text' ],
			'country'                 => [ __( 'Country', 'myhome-eagle-sync' ), 'text' ],
			'lotNo'                   => [ __( 'Lot Number', 'myhome-eagle-sync' ), 'text' ],

			// Numbers -------------------------------------------------------
			'bedrooms'                => [ __( 'Bedrooms', 'myhome-eagle-sync' ), 'number' ],
			'bathrooms'               => [ __( 'Bathrooms', 'myhome-eagle-sync' ), 'number' ],
			'ensuites'                => [ __( 'Ensuites', 'myhome-eagle-sync' ), 'number' ],
			'toilets'                 => [ __( 'Toilets', 'myhome-eagle-sync' ), 'number' ],
			'livingAreas'             => [ __( 'Living Areas', 'myhome-eagle-sync' ), 'number' ],
			'garageSpaces'            => [ __( 'Garage Spaces', 'myhome-eagle-sync' ), 'number' ],
			'carportSpaces'           => [ __( 'Carport Spaces', 'myhome-eagle-sync' ), 'number' ],
			'openCarSpaces'           => [ __( 'Open Car Spaces', 'myhome-eagle-sync' ), 'number' ],
			'houseSizes'              => [ __( 'House Size', 'myhome-eagle-sync' ), 'number' ],
			'landSize'                => [ __( 'Land Size', 'myhome-eagle-sync' ), 'number' ],
			'floorArea'               => [ __( 'Floor Area', 'myhome-eagle-sync' ), 'number' ],
			'warehouseArea'           => [ __( 'Warehouse Area', 'myhome-eagle-sync' ), 'number' ],
			'officeArea'              => [ __( 'Office Area', 'myhome-eagle-sync' ), 'number' ],
			'totalCarSpaces'          => [ __( 'Total Car Spaces', 'myhome-eagle-sync' ), 'number' ],
			'psmPaMin'                => [ __( 'PSM Per Annum Min', 'myhome-eagle-sync' ), 'number' ],
			'psmPaMax'                => [ __( 'PSM Per Annum Max', 'myhome-eagle-sync' ), 'number' ],
			'daysOnMarket'            => [ __( 'Days On Market', 'myhome-eagle-sync' ), 'number' ],
			'numOffers'               => [ __( 'Number of Offers', 'myhome-eagle-sync' ), 'number' ],
			'numberOfWebsiteViews'    => [ __( 'Website Views', 'myhome-eagle-sync' ), 'number' ],
			'numEnquiries'            => [ __( 'Number of Enquiries', 'myhome-eagle-sync' ), 'number' ],
			'frontage'                => [ __( 'Frontage', 'myhome-eagle-sync' ), 'number' ],
			'leftDepth'               => [ __( 'Left Depth', 'myhome-eagle-sync' ), 'number' ],
			'rightDepth'              => [ __( 'Right Depth', 'myhome-eagle-sync' ), 'number' ],
			'rearDepth'               => [ __( 'Rear Depth', 'myhome-eagle-sync' ), 'number' ],

			// Prices --------------------------------------------------------
			'price'                   => [ __( 'Price', 'myhome-eagle-sync' ), 'price' ],
			'advertisedPrice'         => [ __( 'Advertised Price', 'myhome-eagle-sync' ), 'text' ],
			'soldPrice'               => [ __( 'Sold Price', 'myhome-eagle-sync' ), 'price' ],
			'rentalPerWeek'           => [ __( 'Rental Per Week', 'myhome-eagle-sync' ), 'price' ],
			'rentalPerMonth'          => [ __( 'Rental Per Month', 'myhome-eagle-sync' ), 'price' ],
			'bond'                    => [ __( 'Bond', 'myhome-eagle-sync' ), 'price' ],
			'commercialRentalPerAnnum'=> [ __( 'Rental Per Annum', 'myhome-eagle-sync' ), 'price' ],

			// Taxonomies ----------------------------------------------------
			'propertyType'            => [ __( 'Property Type', 'myhome-eagle-sync' ), 'taxonomy' ],
			'listingType'             => [ __( 'Listing Type', 'myhome-eagle-sync' ), 'taxonomy' ],
			'commercialPropertyType'  => [ __( 'Commercial Property Type', 'myhome-eagle-sync' ), 'taxonomy' ],
			'commercialListingType'   => [ __( 'Commercial Listing Type', 'myhome-eagle-sync' ), 'taxonomy' ],
			'heatingCoolingFeatures'  => [ __( 'Heating / Cooling Features', 'myhome-eagle-sync' ), 'taxonomy' ],
			'indoorFeatures'          => [ __( 'Indoor Features', 'myhome-eagle-sync' ), 'taxonomy' ],
			'outdoorFeatures'         => [ __( 'Outdoor Features', 'myhome-eagle-sync' ), 'taxonomy' ],
			'ecoFriendlyFeatures'     => [ __( 'Eco Friendly Features', 'myhome-eagle-sync' ), 'taxonomy' ],
			'allowances'              => [ __( 'Allowances', 'myhome-eagle-sync' ), 'taxonomy' ],

			// Display flags (text) ------------------------------------------
			'showPrice'               => [ __( 'Show Price', 'myhome-eagle-sync' ), 'text' ],
			'featured'                => [ __( 'Featured', 'myhome-eagle-sync' ), 'text' ],
			'soldDisplay'             => [ __( 'Sold Display', 'myhome-eagle-sync' ), 'text' ],
			'thumbnailSquare'         => [ __( 'Thumbnail URL', 'myhome-eagle-sync' ), 'text' ],

			// Dates (as text) ----------------------------------------------
			'auctionDatetime'         => [ __( 'Auction Date', 'myhome-eagle-sync' ), 'text' ],
			'listingExpiryDate'       => [ __( 'Listing Expiry Date', 'myhome-eagle-sync' ), 'text' ],
			'letDate'                 => [ __( 'Let Date', 'myhome-eagle-sync' ), 'text' ],
			'soldDate'                => [ __( 'Sold Date', 'myhome-eagle-sync' ), 'text' ],
			'rentalDateAvailable'     => [ __( 'Date Available', 'myhome-eagle-sync' ), 'text' ],
			'createdAt'               => [ __( 'Created Date', 'myhome-eagle-sync' ), 'text' ],
			'activeAt'                => [ __( 'Active Date', 'myhome-eagle-sync' ), 'text' ],
			'withdrawnAt'             => [ __( 'Withdrawn Date', 'myhome-eagle-sync' ), 'text' ],
			'offMarketAt'             => [ __( 'Off Market Date', 'myhome-eagle-sync' ), 'text' ],

			// Text ----------------------------------------------------------
			'agencyReference'         => [ __( 'Agency Reference', 'myhome-eagle-sync' ), 'text' ],
			'brochureTitle'           => [ __( 'Brochure Title', 'myhome-eagle-sync' ), 'text' ],
			'externalPropertyTree'    => [ __( 'External Property Tree', 'myhome-eagle-sync' ), 'text' ],
			'energyEfficiencyRating'  => [ __( 'Energy Efficiency Rating', 'myhome-eagle-sync' ), 'text' ],
			'businessName'            => [ __( 'Business Name', 'myhome-eagle-sync' ), 'text' ],
			'leaseTerm'               => [ __( 'Lease Term', 'myhome-eagle-sync' ), 'text' ],
			'outgoings'               => [ __( 'Outgoings', 'myhome-eagle-sync' ), 'text' ],
			'tax'                     => [ __( 'Tax', 'myhome-eagle-sync' ), 'text' ],
			'tenancy'                 => [ __( 'Tenancy', 'myhome-eagle-sync' ), 'text' ],
			'zoning'                  => [ __( 'Zoning', 'myhome-eagle-sync' ), 'text' ],
			'occupancyTitle'          => [ __( 'Occupancy Title', 'myhome-eagle-sync' ), 'text' ],
			'parkingComments'         => [ __( 'Parking Comments', 'myhome-eagle-sync' ), 'text' ],

			// Parsed address fields ------------------------------------------
			'suburb'                  => [ __( 'Suburb', 'myhome-eagle-sync' ), 'taxonomy' ],
			'state'                   => [ __( 'State', 'myhome-eagle-sync' ), 'text' ],
			'postcode'                => [ __( 'Postcode', 'myhome-eagle-sync' ), 'text' ],
			'fencing'                 => [ __( 'Fencing', 'myhome-eagle-sync' ), 'text' ],
			'irrigation'              => [ __( 'Irrigation', 'myhome-eagle-sync' ), 'text' ],
			'annualRainfall'          => [ __( 'Annual Rainfall', 'myhome-eagle-sync' ), 'text' ],
			'soilTypes'               => [ __( 'Soil Types', 'myhome-eagle-sync' ), 'text' ],
			'carryingCapacity'        => [ __( 'Carrying Capacity', 'myhome-eagle-sync' ), 'text' ],
			'councilRates'            => [ __( 'Council Rates', 'myhome-eagle-sync' ), 'text' ],
			'improvements'            => [ __( 'Improvements', 'myhome-eagle-sync' ), 'text' ],
			'videoUrl'                => [ __( 'Video URL', 'myhome-eagle-sync' ), 'text' ],
			'onlineTour1Url'          => [ __( 'Online Tour URL', 'myhome-eagle-sync' ), 'text' ],
			'onlineTour2Url'          => [ __( 'Online Tour URL 2', 'myhome-eagle-sync' ), 'text' ],
			'bookInspectionLink'      => [ __( 'Book Inspection Link', 'myhome-eagle-sync' ), 'text' ],

			'establishedOrDevelopment'=> [ __( 'Established / Development', 'myhome-eagle-sync' ), 'text' ],
			'holidayRental'           => [ __( 'Holiday Rental', 'myhome-eagle-sync' ), 'text' ],

			'leaseExpiryDate'         => [ __( 'Lease Expiry Date', 'myhome-eagle-sync' ), 'text' ],
			'returnOnInvestment'      => [ __( 'Return on Investment', 'myhome-eagle-sync' ), 'text' ],
			'crossOver'               => [ __( 'Cross Over', 'myhome-eagle-sync' ), 'text' ],
			'saleOrTender'            => [ __( 'Sale or Tender', 'myhome-eagle-sync' ), 'text' ],
			'tenderDate'              => [ __( 'Tender Date', 'myhome-eagle-sync' ), 'text' ],
			'terms'                   => [ __( 'Terms', 'myhome-eagle-sync' ), 'text' ],
			'vendors'                 => [ __( 'Vendors', 'myhome-eagle-sync' ), 'text' ],

			// Special -------------------------------------------------------
			'gallery'                 => [ __( 'Gallery', 'myhome-eagle-sync' ), 'gallery' ],
			'floorplans'              => [ __( 'Floorplans', 'myhome-eagle-sync' ), 'gallery' ],
			'location'                => [ __( 'Location', 'myhome-eagle-sync' ), 'location' ],
		];
	}

	// ---------------------------------------------------------------------
	// Field creation
	// ---------------------------------------------------------------------

	/**
	 * Create a myhome_field post for an Eagle key that has no existing match.
	 *
	 * @return int The new field ID (post ID), or 0 on failure.
	 */
	public function create_field( string $eagleKey, string $label, string $type ): int {
		$slug = self::key_to_slug( $eagleKey );

		$postId = wp_insert_post(
			[
				'post_type'   => 'myhome_field',
				'post_status' => 'publish',
				'post_title'  => $label,
			],
			true
		);

		if ( is_wp_error( $postId ) || ! $postId ) {
			Eagle_Logger::error( 'Failed to create field for ' . $eagleKey . ': ' . ( is_wp_error( $postId ) ? $postId->get_error_message() : 'insert failed' ) );
			return 0;
		}

		update_post_meta( $postId, 'type', $type );
		update_post_meta( $postId, 'slug', $slug );
		update_post_meta( $postId, '_mes_eagle_field_for_key', $eagleKey );

		$multiSelectKeys = [
			'heatingCoolingFeatures', 'indoorFeatures', 'outdoorFeatures',
			'ecoFriendlyFeatures', 'allowances',
		];
		if ( 'taxonomy' === $type && in_array( $eagleKey, $multiSelectKeys, true ) ) {
			update_post_meta( $postId, 'multiple_values', '1' );
		}

		Eagle_Logger::log( sprintf( 'Created field "%s" (id %d, type %s) for Eagle key %s.', $label, $postId, $type, $eagleKey ) );

		return (int) $postId;
	}

	/**
	 * Convert an Eagle camelCase key to a hyphenated slug.
	 */
	private static function key_to_slug( string $key ): string {
		$slug = preg_replace( '/([A-Z])/', '-$1', $key );
		$slug = strtolower( trim( $slug, '-' ) );
		return preg_replace( '/-+/', '-', $slug );
	}

	// ---------------------------------------------------------------------
	// Field map (existing + auto-created fields)
	// ---------------------------------------------------------------------

	/**
	 * Build the eagle key => MyHome field id map.
	 *
	 * The match is made on the field slug (with a few aliases for keys whose
	 * names differ). When several existing fields share a slug (demo
	 * duplicates), the field that already holds values in the current
	 * listings wins; ties resolve to the lowest field id.
	 *
	 * If no existing field matches, one is created automatically.
	 */
	public function get_field_map(): array {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$persistent = get_transient( self::FIELD_MAP_CACHE );
		if ( is_array( $persistent ) ) {
			return $cached = $persistent;
		}

		$fields = $this->existing_fields();
		if ( empty( $fields ) ) {
			$cached = [];
			set_transient( self::FIELD_MAP_CACHE, $cached, HOUR_IN_SECONDS );
			return $cached;
		}

		$usage = $this->usage_ranking( $fields );

		usort(
			$fields,
			static function ( array $a, array $b ) use ( $usage ): int {
				$sa = $usage[ $a['id'] ] ?? 0;
				$sb = $usage[ $b['id'] ] ?? 0;
				if ( $sa !== $sb ) {
					return $sa > $sb ? -1 : 1;
				}

				return $a['id'] <=> $b['id'];
			}
		);

		$map = [];

		$gallery = $this->first_match( $fields, static fn( array $f ) => 'gallery' === $f['type'] );
		if ( ! $gallery ) {
			// No gallery field exists — create one.
			$newId = $this->create_field( 'gallery', __( 'Gallery', 'myhome-eagle-sync' ), 'gallery' );
			if ( $newId > 0 ) {
				$gallery = [ 'id' => $newId, 'type' => 'gallery', 'slug' => 'gallery', 'title' => 'Gallery' ];
				$fields[] = $gallery;
			}
		}
		if ( $gallery ) {
			$map['gallery']    = $gallery['id'];
			$map['floorplans'] = $gallery['id'];
		}

		$location = $this->first_match( $fields, static fn( array $f ) => 'location' === $f['type'] );
		if ( ! $location ) {
			// No location field exists — create one.
			$newId = $this->create_field( 'location', __( 'Location', 'myhome-eagle-sync' ), 'location' );
			if ( $newId > 0 ) {
				$location = [ 'id' => $newId, 'type' => 'location', 'slug' => 'location', 'title' => 'Location' ];
				$fields[] = $location;
			}
		}
		if ( $location ) {
			$map['location'] = $location['id'];
		}

		foreach ( self::field_definitions() as $key => $definition ) {
			if ( in_array( $key, [ 'gallery', 'floorplans', 'location' ], true ) ) {
				continue;
			}

			$label = $definition[0];
			$type  = $definition[1];
			$field = null;

			if ( in_array( $key, [ 'videoUrl', 'onlineTour1Url', 'onlineTour2Url' ], true ) ) {
				$pattern = false !== strpos( $key, 'video' ) ? 'video' : 'tour';
				$field   = $this->first_match(
					$fields,
					static fn( array $f ) => 'embed' === $f['type'] && false !== stripos( $f['title'], $pattern )
				);
			} elseif ( $key !== 'status' ) {
				$slug  = self::SLUG_ALIASES[ $key ] ?? self::key_to_slug( $key );
				$field = $this->first_match( $fields, static fn( array $f ) => $f['slug'] === $slug );
			}

			if ( $field ) {
				$map[ $key ] = $field['id'];
			} elseif ( $key !== 'status' ) {
				// No existing field found — create one automatically.
				$newId = $this->create_field( $key, $label, $type );
				if ( $newId > 0 ) {
					$map[ $key ] = $newId;
					// Add to the in-memory list so subsequent keys with the
					// same slug reuse this field instead of creating another.
					$fields[] = [
						'id'    => $newId,
						'type'  => $type,
						'slug'  => self::key_to_slug( $key ),
						'title' => $label,
					];
				}
			}
		}

		$cached = $map;
		set_transient( self::FIELD_MAP_CACHE, $cached, 6 * HOUR_IN_SECONDS );
		return $cached;
	}

	public function get_field_id( string $key ): int {
		$map = $this->get_field_map();

		return (int) ( $map[ $key ] ?? 0 );
	}

	/**
	 * Stable fingerprint of the current field map.
	 *
	 * Included in the per-listing data version so that when the field map
	 * changes (new auto-created fields, re-mapped slugs, etc.) every listing
	 * is re-imported on the next sync, even when the API updatedAt is
	 * unchanged. Without this, newly added fields stay empty on listings
	 * that are otherwise considered "current".
	 */
	public function map_version(): string {
		$map = $this->get_field_map();
		ksort( $map );

		return substr( hash( 'sha256', wp_json_encode( $map ) ), 0, 12 );
	}

	/**
	 * True when at least one MyHome field is available for the import.
	 */
	public function is_ready(): bool {
		return count( $this->get_field_map() ) > 0;
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>> sorted ascending by id
	 */
	private function existing_fields(): array {
		$posts = get_posts(
			[
				'post_type'      => 'myhome_field',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$fields = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$type = (string) get_post_meta( $post->ID, 'type', true );
			if ( '' === $type ) {
				$type = 'text';
			}

			$fields[] = [
				'id'    => (int) $post->ID,
				'type'  => $type,
				'slug'  => (string) get_post_meta( $post->ID, 'slug', true ),
				'title' => $post->post_title,
			];
		}

		return $fields;
	}

	/**
	 * How much each field is already used by the current listings
	 * (value metas + term relationships). Used as a tie-breaker so the
	 * import targets the field the theme actually displays.
	 *
	 * @param  array $fields
	 * @return array<int,int> field id => usage count
	 */
	private function usage_ranking( array $fields ): array {
		global $wpdb;

		$usage = [];

		foreach ( $fields as $field ) {
			$usage[ $field['id'] ] = 0;
		}

		$pattern = '^myhome_(' . implode( '|', array_map( static fn( $f ) => (int) $f['id'], $fields ) ) . ')(_|$)';

		$metaRows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, COUNT(*) AS c FROM {$wpdb->postmeta} WHERE meta_key REGEXP %s GROUP BY meta_key",
				$pattern
			)
		);

		foreach ( $metaRows as $row ) {
			if ( preg_match( '/^myhome_(\d+)/', $row->meta_key, $m ) ) {
				$id           = (int) $m[1];
				$usage[ $id ] = ( $usage[ $id ] ?? 0 ) + (int) $row->c;
			}
		}

		$taxonomies = array_map( static fn( $f ) => 'myhome_' . $f['id'], $fields );
		$taxList    = implode( "','", array_map( 'esc_sql', $taxonomies ) );

		$termRows = $wpdb->get_results(
			"SELECT tt.taxonomy, COUNT(*) AS c FROM {$wpdb->term_relationships} r
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
				WHERE tt.taxonomy IN ('{$taxList}')
				GROUP BY tt.taxonomy"
		);

		foreach ( $termRows as $row ) {
			if ( preg_match( '/^myhome_(\d+)$/', $row->taxonomy, $m ) ) {
				$id           = (int) $m[1];
				$usage[ $id ] = ( $usage[ $id ] ?? 0 ) + (int) $row->c;
			}
		}

		return $usage;
	}

	/**
	 * First matching field from the usage-sorted list.
	 *
	 * @param  array    $fields
	 * @param  callable $match
	 * @return array|null
	 */
	private function first_match( array $fields, callable $match ): ?array {
		foreach ( $fields as $field ) {
			if ( $match( $field ) ) {
				return $field;
			}
		}

		return null;
	}
}

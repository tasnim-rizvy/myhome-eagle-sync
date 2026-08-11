<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eagle_Sync_Engine {

	private Eagle_API_Client $client;
	private Eagle_Field_Manager $fields;

	public const PROPERTY_META = '_mes_eagle_property_id';
	public const STATUS_META = '_mes_eagle_status';
	public const IMAGE_META = '_mes_eagle_image_id';
	public const AGENTS_META = '_mes_eagle_agents';
	public const OFFICE_META = '_mes_eagle_office';
	public const CUSTOM_FIELDS_META = '_mes_eagle_custom_fields';

	// Eagle status => WP post status (keep everything visible per plan decision).
	private const STATUS_MAP = [
		'ACTIVE'       => 'publish',
		'UNDER_OFFER'  => 'publish',
		'DRAFT'        => 'draft',
		'WITHDRAWN'    => 'publish',
		'OFF_MARKET'   => 'publish',
		'SOLD'         => 'publish',
		'LEASED'       => 'publish',
		'DELETED'      => 'publish',
	];

	public function __construct() {
		$this->client = new Eagle_API_Client();
		$this->fields = new Eagle_Field_Manager();
	}

	// ---------------------------------------------------------------------
	// Sync state
	// ---------------------------------------------------------------------

	public static function get_state(): array {
		$state = get_option( MES_OPTION_SYNC_STATE, [] );
		return is_array( $state ) ? $state : [];
	}

	public static function set_state( array $state ): void {
		update_option( MES_OPTION_SYNC_STATE, $state, false );
	}

	public static function reset_state(): void {
		delete_option( MES_OPTION_SYNC_STATE );
		delete_option( MES_OPTION_SYNC_SUMMARY );
	}

	// ---------------------------------------------------------------------
	// Batch processing
	// ---------------------------------------------------------------------

	/**
	 * Process one batch (BATCH_SIZE listings) of the import.
	 *
	 * @return array [phase, progress, finished]
	 */
	public function process_batch(): array {
		$state = self::get_state();
		$batch = (int) apply_filters( 'mes_batch_size', 1 );

		if ( empty( $state['phase'] ) || 'running' !== $state['phase'] ) {
			// Start a fresh run; seed the total with an exact API count so the
			// progress can be shown as "processed/total" from the first batch.
			$last = get_option( MES_OPTION_SYNC_SUMMARY, [] );

			$counted = $this->client->count_properties();
			if ( 0 === $counted ) {
				// Count failed; fall back to the previous run's count.
				$counted = (int) ( is_array( $last ) ? ( $last['processed'] ?? 0 ) : 0 );
			}

			$state = [
				'phase'      => 'running',
				'offset'     => 0,
				'total'      => $counted,
				'processed'  => 0,
				'created'    => 0,
				'updated'    => 0,
				'skipped'    => 0,
				'failed'     => 0,
				'last_error' => '',
				'started_at' => current_time( 'mysql' ),
			];
			Eagle_Logger::log( sprintf( 'Sync started (batch size %d), total listings: %d.', $batch, $counted ) );
		}

		$page = $this->client->fetch_properties_page( $batch, $state['offset'] );
		if ( is_wp_error( $page ) ) {
			$state['phase'] = 'error';
			$state['last_error'] = $page->get_error_message();
			self::set_state( $state );
			return [ 'phase' => 'error', 'message' => $state['last_error'] ];
		}

		$state['total'] = max( $state['total'], (int) ( $page['totalCount'] ?? 0 ) );

		if ( empty( $page['nodes'] ) && 0 === $state['processed'] ) {
			// Empty result right away: nothing to import.
			$state['phase'] = 'done';
			self::set_state( $state );
			Eagle_Logger::log( 'Sync finished: nothing to import.' );
			return [ 'phase' => 'done', 'processed' => 0 ];
		}

		foreach ( $page['nodes'] as $node ) {
			$result = $this->upsert_property( $node );
			$state['processed']++;
			switch ( $result ) {
				case 'created':
					$state['created']++;
					break;
				case 'updated':
					$state['updated']++;
					break;
				case 'skipped':
					$state['skipped']++;
					break;
				default:
					$state['failed']++;
					$state['last_error'] = 'Failed property: ' . ( $node['id'] ?? 'unknown' ) . ' (' . $result . ')';
					Eagle_Logger::error( $state['last_error'] );
			}
		}

		$state['offset'] += count( $page['nodes'] );

		// Keep the progress estimate moving even while the run is ongoing.
		$state['total'] = max( (int) $state['total'], $state['processed'] );

		// The API sometimes reports an unreliable totalCount, so completion is
		// decided by the page: when it returns fewer items than requested, the
		// last page has been processed.
		if ( count( $page['nodes'] ) < $batch ) {
			$state['phase'] = 'done';
			$state['total'] = $state['processed'];
			self::set_state( $state );
			update_option(
				MES_OPTION_SYNC_SUMMARY,
				[
					'processed' => $state['processed'],
					'created'   => $state['created'],
					'updated'   => $state['updated'],
					'skipped'   => $state['skipped'],
					'failed'    => $state['failed'],
					'finished'  => current_time( 'mysql' ),
					'duration'  => human_time_diff( strtotime( $state['started_at'] ), current_time( 'timestamp' ) ),
				],
				false
			);
			Eagle_Logger::log(
				sprintf(
					'Sync finished: %d processed (%d created, %d updated, %d skipped, %d failed).',
					$state['processed'],
					$state['created'],
					$state['updated'],
					$state['skipped'],
					$state['failed']
				)
			);
			return [ 'phase' => 'done', 'processed' => $state['processed'] ];
		}

		self::set_state( $state );

		return [
			'phase'     => 'running',
			'processed' => $state['processed'],
			'total'     => $state['total'],
			'offset'    => $state['offset'],
		];
	}

	// ---------------------------------------------------------------------
	// Upsert
	// ---------------------------------------------------------------------

	/**
	 * Create or update one listing post.
	 *
	 * @return string created|updated|skipped|error message
	 */
	private function upsert_property( array $node ): string {
		$propertyId = (string) ( $node['id'] ?? '' );
		if ( $propertyId === '' ) {
			return 'missing id';
		}

		$data = $this->flatten_node( $node );

		// Never import draft listings; count them as skipped.
		if ( 'DRAFT' === (string) ( $data['status'] ?? '' ) ) {
			return 'skipped';
		}

		$existing = get_posts(
			[
				'post_type'      => 'myhome_listing',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => self::PROPERTY_META,
				'meta_value'     => $propertyId,
				'fields'         => 'ids',
			]
		);

		$postId = ! empty( $existing ) ? (int) $existing[0] : 0;

		// Unique slug: {address}-{eagle id} so the permalink ends with the
		// API id, e.g. /listing/21-mcguigan-drive-1741646/.
		$slug = sanitize_title( $this->make_title( $data ) . '-' . $propertyId );

		if ( 0 === $postId ) {
			$postId = wp_insert_post(
				[
					'post_type'   => 'myhome_listing',
					'post_status' => $this->map_status( $data['status'] ),
					'post_title'  => $this->make_title( $data ),
					'post_name'   => $slug,
					'post_author' => $this->import_author_id(),
				],
				true
			);
			if ( is_wp_error( $postId ) || ! $postId ) {
				return is_wp_error( $postId ) ? $postId->get_error_message() : 'insert failed';
			}
			update_post_meta( $postId, self::PROPERTY_META, $propertyId );
			$created = true;
		} else {
			wp_update_post(
				[
					'ID'          => $postId,
					'post_status' => $this->map_status( $data['status'] ),
					'post_title'  => $this->make_title( $data ),
					'post_name'   => $slug,
				]
			);
			$created = false;
		}

		// Raw status for the "flag" (visible in admin and useful for filters).
		update_post_meta( $postId, self::STATUS_META, (string) ( $data['status'] ?? '' ) );

		$errors = [];

		if ( ! $this->write_fields( $postId, $data ) ) {
			$errors[] = 'field write failed';
		}

		if ( ! $this->write_gallery( $postId, $data['images'], 'gallery' ) ) {
			$errors[] = 'gallery failed';
		}

		if ( ! empty( $data['floorplans'] ) ) {
			$this->write_gallery( $postId, $data['floorplans'], 'floorplans' );
		}

		$this->write_agents( $postId, $data );
		$this->write_custom_fields( $postId, $data );

		if ( ! empty( $errors ) ) {
			return implode( ', ', $errors );
		}

		return $created ? 'created' : 'updated';
	}

	// ---------------------------------------------------------------------
	// Field writers
	// ---------------------------------------------------------------------

	private function write_fields( int $postId, array $data ): bool {
		$map = $this->fields->get_field_map();

		foreach ( $map as $key => $fieldId ) {
			if ( ! isset( $data[ $key ] ) || $data[ $key ] === null || $data[ $key ] === '' ) {
				continue;
			}

			try {
				$field = tdf_field_factory()->create( $fieldId );
			} catch ( Exception $e ) {
				continue;
			}

			if ( ! $field ) {
				continue;
			}

			$value = $data[ $key ];

			switch ( $field->getType() ) {
				case 'price':
					$field->setValue( $this->listing_model( $postId ), $this->price_value( $value, $fieldId ) );
					break;

				case 'number':
					$field->setValue( $this->listing_model( $postId ), (string) (float) $value );
					break;

				case 'location':
					$field->setValue(
						$this->listing_model( $postId ),
						[
							'lat'     => (string) ( $value['lat'] ?? '' ),
							'lng'     => (string) ( $value['lng'] ?? '' ),
							'address' => (string) ( $value['address'] ?? '' ),
						]
					);
					break;

				case 'embed':
					$field->setValue(
						$this->listing_model( $postId ),
						[
							'url'   => is_scalar( $value ) ? (string) $value : '',
							'embed' => '',
						]
					);
					break;

				case 'taxonomy':
					$termIds = $this->ensure_terms( (string) $field->getKey(), $value );
					if ( ! empty( $termIds ) ) {
						$field->setValue( $this->listing_model( $postId ), $termIds );
					}
					break;

				default:
					$field->setValue( $this->listing_model( $postId ), is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
			}
		}

		return true;
	}

	/**
	 * Price value array for the field, keyed per currency (only AUD configured).
	 */
	private function price_value( $raw, int $fieldId ): array {
		$value  = [];
		$termId = $this->currency_term_id( 'AUD' );
		if ( $termId ) {
			$key = 'myhome_' . $fieldId . '_myhome_' . $termId;
			$value[ $key ] = number_format( (float) $raw, 0, '.', ',' );
		}
		return $value;
	}

	/**
	 * Ensure taxonomy terms exist; returns term IDs.
	 *
	 * @param string $taxonomy
	 * @param mixed  $value string or array of strings
	 */
	private function ensure_terms( string $taxonomy, $value ): array {
		$names = is_array( $value ) ? $value : [ $value ];
		$ids   = [];

		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( $name === '' ) {
				continue;
			}

			$term = term_exists( $name, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $name, $taxonomy );
			}

			if ( ! is_wp_error( $term ) && ! empty( $term['term_id'] ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}

		return $ids;
	}

	/**
	 * Sideload images into the gallery field (dedupe by eagle image id).
	 */
	private function write_gallery( int $postId, array $images, string $fieldKey ): bool {
		if ( empty( $images ) ) {
			return true;
		}

		$fieldId = $this->fields->get_field_id( $fieldKey );
		if ( ! $fieldId ) {
			return true;
		}

		$attachmentIds = [];
		$eagleIds      = [];

		foreach ( $images as $image ) {
			$eagleId = (string) ( $image['id'] ?? '' );
			$url     = (string) ( $image['url'] ?? '' );

			if ( $url === '' ) {
				continue;
			}

			// Reuse already-downloaded image.
			$existing = $eagleId !== ''
				? get_posts(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'meta_key'       => self::IMAGE_META,
						'meta_value'     => $eagleId,
						'fields'         => 'ids',
					]
				)
				: [];

			if ( ! empty( $existing ) ) {
				$attachmentIds[] = (int) $existing[0];
				$eagleIds[]      = $eagleId;
				continue;
			}

			$attId = $this->sideload_image( $url, $postId );
			if ( $attId ) {
				if ( $eagleId !== '' ) {
					update_post_meta( $attId, self::IMAGE_META, $eagleId );
				}
				$attachmentIds[] = $attId;
				$eagleIds[]      = $eagleId;
			}
		}

		if ( empty( $attachmentIds ) ) {
			return true;
		}

		// Update the field and the featured image if not set yet.
		$field = tdf_field_factory()->create( $fieldId );
		if ( $field ) {
			$field->setValue( $this->listing_model( $postId ), $attachmentIds );
		}

		if ( ! has_post_thumbnail( $postId ) ) {
			set_post_thumbnail( $postId, $attachmentIds[0] );
		}

		update_post_meta( $postId, '_mes_eagle_' . $fieldKey . '_ids', $eagleIds );

		return true;
	}

	private function sideload_image( string $url, int $postId ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attId = media_sideload_image( $url, $postId, null, 'id' );

		if ( is_wp_error( $attId ) ) {
			Eagle_Logger::error( 'Image sideload failed for ' . $url . ': ' . $attId->get_error_message() );
			return false;
		}

		return (int) $attId;
	}

	private function write_agents( int $postId, array $data ): void {
		$agents = array_values(
			array_filter(
				array_map(
					static function ( $agent ) {
						$name = trim( (string) ( $agent['name'] ?? '' ) );
						if ( $name === '' ) {
							return null;
						}

						$office = $agent['office'] ?? '';
						if ( is_array( $office ) ) {
							$office = $office['name'] ?? '';
						}

						return [
							'name'    => $name,
							'title'   => (string) ( $agent['title'] ?? '' ),
							'email'   => (string) ( $agent['email'] ?? '' ),
							'phone'   => (string) ( $agent['phone'] ?? '' ),
							'mobile'  => (string) ( $agent['mobile'] ?? '' ),
							'office'  => (string) $office,
						];
					},
					$data['agents'] ?? []
				)
			)
		);

		$office = $data['office'] ?? '';
		if ( is_array( $office ) ) {
			$office = $office['name'] ?? '';
		}

		update_post_meta( $postId, self::AGENTS_META, $agents );
		update_post_meta( $postId, self::OFFICE_META, (string) $office );
	}

	private function write_custom_fields( int $postId, array $data ): void {
		$map = $this->fields->get_field_map();
		$raw = [];

		foreach ( $data['customFields'] ?? [] as $cf ) {
			$name = isset( $cf['name'] ) ? (string) $cf['name'] : '';
			$val  = $cf['value'] ?? null;

			$key = 'custom_' . sanitize_key( (string) ( $cf['key'] ?? '' ) );
			$fieldId = $this->fields->get_field_id( $key );
			if ( $fieldId && isset( $map[ $key ] ) && $val !== null && $val !== '' ) {
				update_post_meta( $postId, 'myhome_' . $fieldId, is_scalar( $val ) ? (string) $val : wp_json_encode( $val ) );
			}

			$raw[ $name ] = $val;
		}

		update_post_meta( $postId, self::CUSTOM_FIELDS_META, $raw );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Merge the listingDetails union fragment into the node root.
	 */
	private function flatten_node( array $node ): array {
		$data = $node;
		unset( $data['listingDetails'] );

		if ( isset( $node['listingDetails'] ) && is_array( $node['listingDetails'] ) ) {
			$data = array_merge( $data, $node['listingDetails'] );
		}

		$data['images']     = $node['images'] ?? [];
		$data['floorplans'] = $node['floorplans'] ?? [];
		$data['customFields'] = $node['customFields'] ?? [];
		$data['office']     = $node['office'] ?? '';

		if ( isset( $node['location'] ) && is_array( $node['location'] ) ) {
			$loc             = $node['location'];
			$data['location'] = [
				'lat'     => (string) ( $loc['latitude'] ?? '' ),
				'lng'     => (string) ( $loc['longitude'] ?? '' ),
				'address' => (string) ( $node['formattedAddress'] ?? '' ),
			];
		}

		return $data;
	}

	private function make_title( array $data ): string {
		$parts = array_filter(
			[
				(string) ( $data['streetNo'] ?? '' ),
				(string) ( $data['street'] ?? '' ),
				(string) ( $data['municipality'] ?? '' ),
			],
			static fn( $p ) => trim( $p ) !== ''
		);

		return implode( ' ', $parts ) !== ''
			? implode( ' ', $parts )
			: (string) ( $data['headline'] ?? ( $data['formattedAddress'] ?? 'Eagle Listing' ) );
	}

	private function map_status( $status ): string {
		$key = (string) ( $status ?? '' );
		return self::STATUS_MAP[ $key ] ?? 'draft';
	}

	private function currency_term_id( string $code ): int {
		$taxonomy = 'myhome_currency';
		$terms    = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name'       => $code,
				'fields'     => 'ids',
			]
		);

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return (int) $terms[0];
		}

		// Fallback: first term in the taxonomy.
		$all = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ] );
		if ( is_array( $all ) && ! empty( $all ) ) {
			return (int) $all[0];
		}

		return 0;
	}

	private function listing_model( int $postId ) {
		return tdf_model_factory()->create( $postId );
	}

	private function import_author_id(): int {
		$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		return ! empty( $admin ) ? (int) $admin[0] : 1;
	}
}

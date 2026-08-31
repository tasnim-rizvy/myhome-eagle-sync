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
	public const AGENT_PHOTO_META = '_mes_eagle_agent_photo_id';
	public const IMAGE_ATTEMPTS_META = '_mes_eagle_image_attempts';
	public const IMAGE_ATTEMPTS_VERSION_META = '_mes_eagle_image_attempts_version';
	public const DATA_VERSION_META = '_mes_eagle_data_version';

	// Per-request work budgets (all filterable). A single HTTP request never
	// downloads or resizes more than these, so one image-heavy listing spans
	// several polls instead of overrunning the proxy timeout or PHP memory
	// limit (the cause of the 503s on ~100 MB listings).
	private const DEFAULT_IMAGES_PER_REQUEST = 1;
	private const DEFAULT_BYTES_PER_REQUEST  = 20971520; // 20 MB.
	private const DEFAULT_TIME_BUDGET        = 20;        // Seconds of new work per request.
	private const DEFAULT_MAX_IMAGE_ATTEMPTS = 3;
	private const IMAGE_ATTEMPTS_VERSION     = '2';

	private int $request_deadline   = 0;
	private int $images_remaining   = 0;
	private int $bytes_remaining    = 0;
	private int $max_image_attempts = 3;

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

		// Bound the work this request may do so it always finishes well within
		// the server's request/memory limits, however small and unknown they are.
		$this->request_deadline   = time() + max( 5, (int) apply_filters( 'mes_request_time_budget', self::DEFAULT_TIME_BUDGET ) );
		$this->images_remaining   = max( 1, (int) apply_filters( 'mes_images_per_request', self::DEFAULT_IMAGES_PER_REQUEST ) );
		$this->bytes_remaining    = max( 1048576, (int) apply_filters( 'mes_bytes_per_request', self::DEFAULT_BYTES_PER_REQUEST ) );
		$this->max_image_attempts = max( 1, (int) apply_filters( 'mes_max_image_attempts', self::DEFAULT_MAX_IMAGE_ATTEMPTS ) );

		// One batch at a time: if another request (e.g. a second tab or a
		// poll overlapping a slow request) is still working, report current
		// progress and let the poll loop retry instead of importing twice.
		if ( 'running' === ( $state['phase'] ?? '' ) && (int) ( $state['busy_until'] ?? 0 ) > time() ) {
			return [
				'phase'        => 'running',
				'retry_after'  => 2,
				'processed'    => (int) ( $state['processed'] ?? 0 ),
				'total'        => (int) ( $state['total'] ?? 0 ),
				'offset'       => (int) ( $state['offset'] ?? 0 ),
				'images_done'  => (int) ( $state['current_images_done'] ?? 0 ),
				'images_total' => (int) ( $state['current_images_total'] ?? 0 ),
			];
		}

		if ( empty( $state['phase'] ) || 'running' !== $state['phase'] ) {
			// Start immediately. A separate pre-count doubled API work and could
			// exhaust the host timeout before the first listing was checkpointed.
			// The first real page supplies totalCount; the previous run is only a
			// temporary progress estimate until then.
			$last = get_option( MES_OPTION_SYNC_SUMMARY, [] );
			$counted = (int) ( is_array( $last ) ? ( $last['processed'] ?? 0 ) : 0 );

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

		// Mark this request as the active worker so overlapping requests
		// report progress instead of importing the same batch twice. The lease
		// outlives one bounded request and still frees on its own if the request
		// is killed before its normal cleanup path.
		$state['busy_until'] = time() + 75;
		self::set_state( $state );

		$page = $this->client->fetch_properties_page( $batch, $state['offset'] );
		if ( is_wp_error( $page ) ) {
			$state['last_error'] = $page->get_error_message();
			$errorData = $page->get_error_data();
			$retryable = is_array( $errorData ) && ! empty( $errorData['retryable'] );
			$failures  = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;
			unset( $state['busy_until'] );

			if ( $retryable && $failures <= 6 ) {
				$retryAfter = max( 1, min( 30, (int) ( $errorData['retry_after'] ?? ( 2 ** min( $failures, 4 ) ) ) ) );
				$state['consecutive_failures'] = $failures;
				self::set_state( $state );
				Eagle_Logger::error( 'Temporary Eagle API error; checkpoint retained: ' . $state['last_error'] );

				return [
					'phase'       => 'running',
					'message'     => 'Eagle is temporarily unavailable. Retrying without losing progress…',
					'retry_after' => $retryAfter,
					'processed'   => (int) ( $state['processed'] ?? 0 ),
					'total'       => (int) ( $state['total'] ?? 0 ),
					'offset'      => (int) ( $state['offset'] ?? 0 ),
				];
			}

			$state['phase'] = 'error';
			self::set_state( $state );
			return [ 'phase' => 'error', 'message' => $state['last_error'] ];
		}

		unset( $state['consecutive_failures'] );

		$state['total'] = max( $state['total'], (int) ( $page['totalCount'] ?? 0 ) );

		if ( empty( $page['nodes'] ) && 0 === $state['processed'] ) {
			// Empty result right away: nothing to import.
			$state['phase'] = 'done';
			unset( $state['busy_until'] );
			self::set_state( $state );
			Eagle_Logger::log( 'Sync finished: nothing to import.' );
			return [ 'phase' => 'done', 'processed' => 0 ];
		}

		$nodeCount   = count( $page['nodes'] );
		$completed   = 0;
		$stalled     = false;
		$imagesDone  = 0;
		$imagesTotal = 0;

		foreach ( $page['nodes'] as $node ) {
			$result = $this->upsert_property( $node );

			if ( ! $result['gallery_complete'] ) {
				// This listing still has images to import. Leave the offset on
				// it so the next poll resumes here (dedup skips finished
				// images) instead of forcing a whole huge gallery through one
				// request.
				$stalled     = true;
				$imagesDone  = (int) $result['images_done'];
				$imagesTotal = (int) $result['images_total'];
				break;
			}

			$state['processed']++;
			$completed++;

			switch ( $result['status'] ) {
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
					$state['last_error'] = 'Failed property: ' . ( $node['id'] ?? 'unknown' ) . ' (' . $result['status'] . ')';
					Eagle_Logger::error( $state['last_error'] );
			}

			// Stop cleanly once this request's time budget is spent; the next
			// poll resumes at the advanced offset.
			if ( time() >= $this->request_deadline ) {
				break;
			}
		}

		$state['offset'] += $completed;

		// Surface sub-listing image progress while a large gallery imports.
		if ( $stalled ) {
			$state['current_images_done']  = $imagesDone;
			$state['current_images_total'] = $imagesTotal;
		} else {
			unset( $state['current_images_done'], $state['current_images_total'] );
		}

		// Keep the progress estimate moving even while the run is ongoing.
		$state['total'] = max( (int) $state['total'], $state['processed'] );

		// Completion: the whole page was consumed (no listing left mid-import)
		// and it was a short page (fewer nodes than requested = last page). The
		// API's totalCount is unreliable, so the page length decides.
		$page_finished = ( ! $stalled ) && ( $completed === $nodeCount );

		if ( $page_finished && $nodeCount < $batch ) {
			$state['phase'] = 'done';
			$state['total'] = $state['processed'];
			unset( $state['busy_until'] );
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

		// Batch finished; release the lock so the next poll can continue.
		unset( $state['busy_until'] );
		self::set_state( $state );

		return [
			'phase'        => 'running',
			'processed'    => $state['processed'],
			'total'        => $state['total'],
			'offset'       => $state['offset'],
			'images_done'  => (int) ( $state['current_images_done'] ?? 0 ),
			'images_total' => (int) ( $state['current_images_total'] ?? 0 ),
		];
	}

	// ---------------------------------------------------------------------
	// Upsert
	// ---------------------------------------------------------------------

	/**
	 * Create or update one listing post.
	 *
	 * Galleries are imported in bounded chunks, so a listing may need several
	 * calls before it is fully done. The caller advances past the listing only
	 * once 'gallery_complete' is true.
	 *
	 * @return array{status:string,gallery_complete:bool,images_done:int,images_total:int}
	 */
	private function upsert_property( array $node ): array {
		$propertyId = (string) ( $node['id'] ?? '' );
		if ( $propertyId === '' ) {
			return $this->upsert_result( 'missing id' );
		}

		$data = $this->flatten_node( $node );

		// Never import draft listings; count them as skipped.
		if ( 'DRAFT' === (string) ( $data['status'] ?? '' ) ) {
			return $this->upsert_result( 'skipped' );
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
		$sourceVersion = (string) ( $data['updatedAt'] ?? '' );
		if ( '' === $sourceVersion ) {
			$sourceVersion = hash(
				'sha256',
				wp_json_encode(
					[
						'id'          => $propertyId,
						'status'      => $data['status'] ?? '',
						'headline'    => $data['headline'] ?? '',
						'description' => $data['description'] ?? '',
						'price'       => $data['price'] ?? '',
					]
				)
			);
		}
		$dataVersion = MES_VERSION . ':' . $sourceVersion . ':' . $this->fields->map_version();
		$dataCurrent = $postId > 0 && hash_equals( (string) get_post_meta( $postId, self::DATA_VERSION_META, true ), $dataVersion );

		// Unique slug: {address}-{eagle id} so the permalink ends with the
		// API id, e.g. /listing/21-mcguigan-drive-1741646/.
		$slug = sanitize_title( $this->make_title( $data ) . '-' . $propertyId );

		if ( 0 === $postId ) {
$postId = wp_insert_post(
			[
				'post_type'    => 'myhome_listing',
				'post_status'  => $this->map_status( $data['status'] ),
				'post_title'   => $this->make_title( $data ),
				'post_name'    => $slug,
				'post_author'  => $this->import_author_id(),
				'post_content' => (string) ( $data['description'] ?? '' ),
				'post_excerpt' => (string) ( $data['description'] ?? '' ),
			],
			true
		);
			if ( is_wp_error( $postId ) || ! $postId ) {
				return $this->upsert_result( is_wp_error( $postId ) ? $postId->get_error_message() : 'insert failed' );
			}
			update_post_meta( $postId, self::PROPERTY_META, $propertyId );
			$created = true;
		} elseif ( ! $dataCurrent ) {
wp_update_post(
			[
				'ID'           => $postId,
				'post_status'  => $this->map_status( $data['status'] ),
				'post_title'   => $this->make_title( $data ),
				'post_name'    => $slug,
				'post_content' => (string) ( $data['description'] ?? '' ),
				'post_excerpt' => (string) ( $data['description'] ?? '' ),
			]
		);
			$created = false;
		} else {
			$created = false;
		}

		$errors = [];

		if ( ! $dataCurrent ) {
			// These save hooks can be expensive on MyHome. Run them once per Eagle
			// updatedAt value; media-only polls below then do only media work.
			update_post_meta( $postId, self::STATUS_META, (string) ( $data['status'] ?? '' ) );

			if ( ! $this->write_fields( $postId, $data ) ) {
				$errors[] = 'field write failed';
			}

			$this->write_agents( $postId, $data );
			$this->write_agent_photo( $postId, $data );
			$this->write_custom_fields( $postId, $data );
			update_post_meta( $postId, self::DATA_VERSION_META, $dataVersion );
		}

		// Import galleries in bounded chunks; a large listing therefore spans
		// several polls rather than one oversized request. MyHome installations
		// commonly expose one gallery field for both photos and floorplans. Write
		// it once when both keys map to that field, otherwise the second setter
		// overwrites the first gallery.
		$galleryFieldId    = $this->fields->get_field_id( 'gallery' );
		$floorplansFieldId = $this->fields->get_field_id( 'floorplans' );
		if ( $galleryFieldId > 0 && $galleryFieldId === $floorplansFieldId ) {
			$combined   = array_values( array_merge( $data['images'], $data['floorplans'] ) );
			$gallery    = $this->write_gallery( $postId, $combined, 'gallery' );
			$floorplans = [ 'complete' => true, 'done' => 0, 'total' => 0 ];
		} else {
			$gallery    = $this->write_gallery( $postId, $data['images'], 'gallery' );
			$floorplans = $this->write_gallery( $postId, $data['floorplans'], 'floorplans' );
		}

		$status = $created ? 'created' : 'updated';
		if ( ! empty( $errors ) ) {
			$status = implode( ', ', $errors );
		}

		return $this->upsert_result(
			$status,
			$gallery['complete'] && $floorplans['complete'],
			$gallery['done'] + $floorplans['done'],
			$gallery['total'] + $floorplans['total']
		);
	}

	/**
	 * Build the structured result returned by upsert_property().
	 */
	private function upsert_result( string $status, bool $galleryComplete = true, int $done = 0, int $total = 0 ): array {
		return [
			'status'           => $status,
			'gallery_complete' => $galleryComplete,
			'images_done'      => $done,
			'images_total'     => $total,
		];
	}

	// ---------------------------------------------------------------------
	// Field writers
	// ---------------------------------------------------------------------

	private function write_fields( int $postId, array $data ): bool {
		$map = $this->fields->get_field_map();
		// The Eagle key's expected type. Using this (rather than the MyHome
		// field's possibly stale cached type) keeps storage deterministic even
		// when a pre-existing field was created with the wrong type (e.g. a
		// "postcode" field stored as taxonomy instead of text).
		$defTypes = [];
		foreach ( Eagle_Field_Manager::field_definitions() as $dk => $def ) {
			$defTypes[ $dk ] = $def[1];
		}
		$success = true;

		foreach ( $map as $key => $fieldId ) {
			// These structured values are handled by write_gallery(), not by the
			// generic scalar field path below.
			if ( in_array( $key, [ 'gallery', 'floorplans', 'agentPhoto' ], true ) ) {
				continue;
			}

			if ( ! isset( $data[ $key ] ) || $data[ $key ] === null || $data[ $key ] === '' ) {
				continue;
			}

			try {
				$field = tdf_field_factory()->create( $fieldId );
			} catch ( Throwable $e ) {
				$success = false;
				Eagle_Logger::error(
					sprintf( 'Field creation failed for %s on listing %d: %s', $key, $postId, $e->getMessage() )
				);
				continue;
			}

			if ( ! $field ) {
				continue;
			}

			$value = $data[ $key ];

			try {
				switch ( $defTypes[ $key ] ?? $field->getType() ) {
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
						// MyHome only renders the "embed" key (oEmbed HTML) both in
						// the admin preview and on the frontend; a bare URL is not
						// enough, so resolve it via wp_oembed_get().
						$embed = is_scalar( $value ) ? (string) $value : '';
						$field->setValue(
							$this->listing_model( $postId ),
							[
								'url'   => $embed,
								'embed' => $embed !== '' ? (string) wp_oembed_get( $embed ) : '',
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
			} catch ( Throwable $e ) {
				$success = false;
				Eagle_Logger::error(
					sprintf( 'Field write failed for %s on listing %d: %s', $key, $postId, $e->getMessage() )
				);
			}
		}

		return $success;
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
			$name = trim( $this->humanize_enum( (string) $name ) );
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
	 * Convert SCREAMING_SNAKE_CASE or ALL CAPS text to Human Readable Text.
	 * Preserves short codes like NSW, QLD, 2179.
	 */
	private function humanize_enum( string $value ): string {
		// Already has lowercase letters — leave as-is.
		if ( preg_match( '/[a-z]/', $value ) ) {
			return $value;
		}

		// Short all-digits code (postcode) — preserve.
		if ( strlen( $value ) <= 4 && preg_match( '/^[0-9]+$/', $value ) ) {
			return $value;
		}

		// Short all-caps letter code (state abbreviation) — preserve.
		if ( strlen( $value ) <= 3 && preg_match( '/^[A-Z]+$/', $value ) ) {
			return $value;
		}

		// SCREAMING_SNAKE_CASE or ALL CAPS with spaces.
		if ( preg_match( '/^[A-Z][A-Z0-9_ ]+$/', $value ) && strlen( $value ) >= 4 ) {
			return ucwords( strtolower( str_replace( '_', ' ', $value ) ) );
		}

		return $value;
	}

	/**
	 * Import images into a gallery field in bounded chunks.
	 *
	 * Only a limited number of new images are downloaded per call — capped by
	 * count, cumulative bytes and the per-request time budget — while
	 * already-imported images are reused (dedup) and images that keep failing
	 * are eventually skipped so they cannot wedge the listing. A large gallery
	 * therefore completes across several polls instead of one oversized
	 * request, which is what was triggering the 503s.
	 *
	 * @return array{complete:bool,done:int,total:int}
	 */
	private function write_gallery( int $postId, array $images, string $fieldKey ): array {
		if ( empty( $images ) ) {
			return [ 'complete' => true, 'done' => 0, 'total' => 0 ];
		}

		$fieldId = $this->fields->get_field_id( $fieldKey );
		if ( ! $fieldId ) {
			// No target field on this site; nothing to import here.
			return [ 'complete' => true, 'done' => 0, 'total' => 0 ];
		}

		// Retry images that an older plugin build may have permanently marked as
		// failed. This migration is lazy and runs only once per listing.
		if ( self::IMAGE_ATTEMPTS_VERSION !== (string) get_post_meta( $postId, self::IMAGE_ATTEMPTS_VERSION_META, true ) ) {
			$attempts = [];
			delete_post_meta( $postId, self::IMAGE_ATTEMPTS_META );
			update_post_meta( $postId, self::IMAGE_ATTEMPTS_VERSION_META, self::IMAGE_ATTEMPTS_VERSION );
		} else {
			$attempts = get_post_meta( $postId, self::IMAGE_ATTEMPTS_META, true );
			if ( ! is_array( $attempts ) ) {
				$attempts = [];
			}
		}

		$total         = 0;
		$done          = 0;
		$attachmentIds = [];
		$pending       = [];
		$seen          = [];

		foreach ( $images as $image ) {
			$url = (string) ( $image['url'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$key      = $this->image_dedup_key( $image );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$total++;

			$existing = $this->find_attachment_by_key( $key, $url, $postId );

			if ( $existing ) {
				$attachmentIds[] = $existing;
				$done++;
				continue;
			}

			// Give up on images that have already failed too many times so a
			// single broken URL can never block the listing forever; count them
			// as resolved so the gallery can reach "complete".
			$attempt = $attempts[ $key ] ?? 0;
			$count   = is_array( $attempt ) ? (int) ( $attempt['count'] ?? 0 ) : (int) $attempt;
			$last    = is_array( $attempt ) ? (int) ( $attempt['last'] ?? 0 ) : 0;

			if ( $count >= $this->max_image_attempts ) {
				// Do not make a temporary CDN/network problem permanent. Suppress
				// repeated failures for one hour, then allow a future sync to retry.
				if ( $last > time() - HOUR_IN_SECONDS ) {
					$done++;
					continue;
				}

				unset( $attempts[ $key ] );
			}

			$pending[] = [ 'url' => $url, 'key' => $key ];
		}

		// Download at most one pending image through WordPress's time- and
		// size-bounded safe HTTP layer. Larger galleries span multiple polls.
		$touched = false;
		foreach ( $pending as $image ) {
			if ( $this->images_remaining <= 0 || $this->bytes_remaining <= 0 || time() >= $this->request_deadline ) {
				break;
			}

			$slice = max( 3, $this->request_deadline - time() );
			$res   = $this->stream_download_image( $image['url'], $image['key'], $slice );
			$touched = true;
			$this->images_remaining--;

			$this->bytes_remaining -= (int) ( $res['bytes'] ?? 0 );

			if ( 'complete' === $res['status'] ) {
				$this->maybe_raise_image_memory();
				$attId = $this->insert_image_from_tmp( $image['url'], $image['key'], $res['tmp'], $postId );
				if ( $attId ) {
					$attachmentIds[] = $attId;
					$done++;
					unset( $attempts[ $image['key'] ] );
				} else {
					$attempt = $attempts[ $image['key'] ] ?? 0;
					$count = is_array( $attempt ) ? (int) ( $attempt['count'] ?? 0 ) : (int) $attempt;
					$attempts[ $image['key'] ] = [ 'count' => $count + 1, 'last' => time() ];
				}
				continue;
			}

			// Failed this attempt; count it so a permanently broken URL is
			// temporarily skipped instead of wedging the listing.
			$attempt = $attempts[ $image['key'] ] ?? 0;
			$count = is_array( $attempt ) ? (int) ( $attempt['count'] ?? 0 ) : (int) $attempt;
			$attempts[ $image['key'] ] = [ 'count' => $count + 1, 'last' => time() ];
			if ( ! empty( $res['error'] ) ) {
				Eagle_Logger::error( 'Image download failed for Eagle image ' . $image['key'] . ': ' . $res['error'] );
			}
		}

		if ( $touched ) {
			update_post_meta( $postId, self::IMAGE_ATTEMPTS_META, $attempts );
		}

		// Update the field with everything gathered so far and set the featured
		// image on the first pass; both are safe to repeat as the gallery grows.
		if ( ! empty( $attachmentIds ) ) {
			$attachmentIds = array_values( array_unique( array_map( 'intval', $attachmentIds ) ) );

			try {
				$field = tdf_field_factory()->create( $fieldId );
				if ( $field ) {
					$field->setValue( $this->listing_model( $postId ), $attachmentIds );
				}
			} catch ( Throwable $e ) {
				Eagle_Logger::error(
					sprintf( 'Gallery field write failed on listing %d: %s', $postId, $e->getMessage() )
				);
			}

			if ( ! has_post_thumbnail( $postId ) ) {
				set_post_thumbnail( $postId, $attachmentIds[0] );
			}
		}

		return [
			'complete' => $done >= $total,
			'done'     => $done,
			'total'    => $total,
		];
	}

	/**
	 * Stable per-image identity used to dedupe downloads across polls. Falls
	 * back to a hash of the URL when the API omits the image id, so a resumed
	 * import never re-downloads the same photo.
	 */
	private function image_dedup_key( array $image ): string {
		$eagleId = (string) ( $image['id'] ?? '' );
		if ( $eagleId !== '' ) {
			return $eagleId;
		}

		return 'url:' . md5( (string) ( $image['url'] ?? '' ) );
	}

	/**
	 * Attachment id previously imported for this image key, or 0.
	 */
	private function find_attachment_by_key( string $key, string $url = '', int $postId = 0 ): int {
		if ( $key === '' ) {
			return 0;
		}

		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 5,
				'meta_key'       => self::IMAGE_META,
				'meta_value'     => $key,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			]
		);

		foreach ( $existing as $attachmentId ) {
			if ( $this->attachment_file_exists( (int) $attachmentId ) ) {
				return (int) $attachmentId;
			}
		}

		/*
		 * Versions before 0.2.0 wrote IMAGE_META only after all WordPress
		 * sub-sizes had been generated. If the host terminated that expensive
		 * step, a valid attachment was left behind without its Eagle key and the
		 * next poll downloaded it again. Adopt the newest matching attachment
		 * for this listing so upgrading also recovers existing partial imports.
		 */
		if ( '' === $url || $postId <= 0 ) {
			return 0;
		}

		$urlPath = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( $urlPath ) );
		$stem = pathinfo( $filename, PATHINFO_FILENAME );

		if ( '' === $stem ) {
			return 0;
		}

		$legacy = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $postId,
				'post_mime_type' => 'image',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => '_wp_attached_file',
						'value'   => $stem,
						'compare' => 'LIKE',
					],
					[
						'key'     => self::IMAGE_META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		if ( empty( $legacy ) ) {
			return 0;
		}

		$attachmentId = 0;
		foreach ( $legacy as $candidateId ) {
			if ( $this->attachment_matches_source_filename( (int) $candidateId, $filename ) ) {
				$attachmentId = (int) $candidateId;
				break;
			}
		}

		if ( 0 === $attachmentId ) {
			return 0;
		}

		update_post_meta( $attachmentId, self::IMAGE_META, $key );
		Eagle_Logger::log(
			sprintf( 'Recovered attachment %d left by an interrupted image import.', $attachmentId )
		);

		return $attachmentId;
	}

	/**
	 * An attachment row alone is not enough after an interrupted upload.
	 */
	private function attachment_file_exists( int $attachmentId ): bool {
		$file = get_attached_file( $attachmentId );
		return is_string( $file ) && '' !== $file && is_file( $file );
	}

	/**
	 * Match an interrupted legacy upload without adopting an unrelated image
	 * whose name merely contains the same text.
	 */
	private function attachment_matches_source_filename( int $attachmentId, string $sourceFilename ): bool {
		if ( ! $this->attachment_file_exists( $attachmentId ) ) {
			return false;
		}

		$file          = (string) get_attached_file( $attachmentId );
		$sourceStem    = pathinfo( $sourceFilename, PATHINFO_FILENAME );
		$candidateStem = pathinfo( wp_basename( $file ), PATHINFO_FILENAME );

		if ( '' === $sourceStem || '' === $candidateStem ) {
			return false;
		}

		return 1 === preg_match(
			'/^' . preg_quote( $sourceStem, '/' ) . '(?:-\d+)?(?:-scaled)?$/i',
			$candidateStem
		);
	}

	/**
	 * Raise the memory ceiling for image processing when WordPress allows it.
	 */
	private function maybe_raise_image_memory(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
	}

	/**
	 * Download one image through WordPress's safe HTTP layer.
	 *
	 * wp_safe_remote_get() validates the initial URL and every redirect. The
	 * response-size cap prevents a single unexpected source file from consuming
	 * the process memory/disk budget. A failed or timed-out request is discarded
	 * and can be retried on a later poll.
	 *
	 * @return array{status:string,tmp:?string,error:?string,bytes:int}
	 *         status: complete | failed
	 */
	private function stream_download_image( string $url, string $key, int $budget ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'invalid image URL', 'bytes' => 0 ];
		}

		$tmp = wp_tempnam( 'mes-' . md5( $key ) . '.img' );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'cannot create temporary file', 'bytes' => 0 ];
		}

		$maxBytes = max( 1048576, $this->bytes_remaining );
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => max( 5, min( 20, $budget ) ),
				'redirection'         => 3,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $maxBytes + 1,
				'headers'             => [ 'Accept' => 'image/*' ],
			]
		);

		$bytes = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			return [ 'status' => 'failed', 'tmp' => null, 'error' => $response->get_error_message(), 'bytes' => $bytes ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $tmp );
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'HTTP ' . $code, 'bytes' => $bytes ];
		}

		$contentLength = (int) wp_remote_retrieve_header( $response, 'content-length' );
		if ( $bytes <= 0 || $bytes > $maxBytes || $contentLength > $maxBytes ) {
			@unlink( $tmp );
			$error = $bytes <= 0 ? 'empty response' : 'image exceeds the per-request size limit';
			return [ 'status' => 'failed', 'tmp' => null, 'error' => $error, 'bytes' => $bytes ];
		}

		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
		if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
			@unlink( $tmp );
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'remote file is not a valid image', 'bytes' => $bytes ];
		}

		return [ 'status' => 'complete', 'tmp' => $tmp, 'error' => null, 'bytes' => $bytes ];
	}

	/**
	 * Create an attachment from a downloaded temp file.
	 */
	private function insert_image_from_tmp( string $url, string $key, string $tmp, int $postId ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$urlPath  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$fileName = sanitize_file_name( wp_basename( $urlPath ) );
		if ( '' === $fileName ) {
			$fileName = 'eagle-image-' . substr( hash( 'sha256', $key ), 0, 16 ) . '.jpg';
		}
		$fileType = wp_check_filetype( $fileName );
		if ( empty( $fileType['type'] ) ) {
			$detectedMime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
			$extensions   = [
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
				'image/avif' => 'avif',
			];

			if ( is_string( $detectedMime ) && isset( $extensions[ $detectedMime ] ) ) {
				$fileName       .= '.' . $extensions[ $detectedMime ];
				$fileType['type'] = $detectedMime;
			}
		}

		$file = [
			'name'     => $fileName,
			'type'     => (string) ( $fileType['type'] ?? '' ),
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		];

		$sideload = wp_handle_sideload( $file, [ 'test_form' => false ] );
		if ( isset( $sideload['error'] ) ) {
			@unlink( $tmp );
			Eagle_Logger::error( 'Image sideload failed for Eagle image ' . $key . ': ' . $sideload['error'] );
			return false;
		}

		$attachment = [
			'post_mime_type' => $sideload['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', sanitize_file_name( basename( $sideload['file'] ) ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'meta_input'     => [
				self::IMAGE_META => $key,
			],
		];

		$attId = wp_insert_attachment( $attachment, $sideload['file'], $postId, true );
		if ( ! $attId || is_wp_error( $attId ) ) {
			@unlink( $sideload['file'] );
			Eagle_Logger::error( 'Attachment insert failed for Eagle image ' . $key );
			return false;
		}

		/*
		 * Persist identity before thumbnail generation. This is the critical
		 * ordering guarantee: even if PHP/the proxy dies below, the next poll
		 * reuses this attachment instead of inserting another copy.
		 */
		update_post_meta( $attId, self::IMAGE_META, $key );

		// Generate only the bounded set MyHome needs for normal cards/galleries.
		// Additional sizes can be regenerated later without blocking this sync.
		$limitSubsizes = static function ( $sizes, $metadata, $attachmentId ) use ( $postId ) {
			if ( ! is_array( $sizes ) ) {
				return $sizes;
			}

			$allowed = (array) apply_filters(
				'mes_allowed_image_subsizes',
				[ 'thumbnail', 'medium', 'medium_large', 'large' ],
				$postId,
				(int) $attachmentId
			);

			return array_intersect_key( $sizes, array_fill_keys( array_map( 'strval', $allowed ), true ) );
		};
		$disableBigImageScaling = static function () {
			return false;
		};

		add_filter( 'intermediate_image_sizes_advanced', $limitSubsizes, 99, 3 );
		add_filter( 'big_image_size_threshold', $disableBigImageScaling, 99 );
		try {
			$meta = wp_generate_attachment_metadata( $attId, $sideload['file'] );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( $attId, $meta );
			} else {
				Eagle_Logger::error( sprintf( 'Image metadata was not generated for attachment %d.', $attId ) );
			}
		} catch ( Throwable $e ) {
			// Keep the original attachment and let the listing continue. A missing
			// optional thumbnail must never block the entire Eagle sync.
			Eagle_Logger::error(
				sprintf( 'Image metadata failed for attachment %d: %s', $attId, $e->getMessage() )
			);
		} finally {
			remove_filter( 'intermediate_image_sizes_advanced', $limitSubsizes, 99 );
			remove_filter( 'big_image_size_threshold', $disableBigImageScaling, 99 );
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
							'avatarUrl' => trim( (string) ( $agent['avatarUrl'] ?? '' ) ),
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

	/**
	 * Download the primary agent's avatar and add it to the agent photo gallery field.
	 */
	private function write_agent_photo( int $postId, array $data ): void {
		$avatarUrl = '';
		$agents    = $data['agents'] ?? [];
		$primary   = reset( $agents );
		if ( is_array( $primary ) ) {
			$avatarUrl = trim( (string) ( $primary['avatarUrl'] ?? '' ) );
		}

		if ( $avatarUrl === '' ) {
			return;
		}

		$fieldId = $this->fields->get_field_id( 'agentPhoto' );
		if ( ! $fieldId ) {
			return;
		}

		// Already imported?
		$existing = (int) get_post_meta( $postId, self::AGENT_PHOTO_META, true );
		$galleryKey = tdf_prefix() . '_' . $fieldId;

		// If the attachment was already imported, just ensure the gallery field is set.
		if ( $existing > 0 && get_post_status( $existing ) ) {
			update_post_meta( $postId, $galleryKey, [ $existing ] );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $avatarUrl, 30 );
		if ( is_wp_error( $tmp ) ) {
			Eagle_Logger::error( sprintf( 'Agent photo download failed for listing %d: %s', $postId, $tmp->get_error_message() ) );
			return;
		}

		$filename = 'agent-photo-' . $postId . '-' . wp_unique_filename( wp_upload_dir()['path'], basename( wp_parse_url( $avatarUrl, PHP_URL_PATH ) ?: 'agent.jpg' ) );
		$move     = rename( $tmp, wp_upload_dir()['path'] . '/' . $filename );
		if ( ! $move ) {
			@unlink( $tmp );
			Eagle_Logger::error( sprintf( 'Agent photo move failed for listing %d', $postId ) );
			return;
		}

		$filetype = wp_check_filetype( $filename );
		$attachment = wp_insert_attachment(
			[
				'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
				'post_title'     => 'Agent Photo — Listing ' . $postId,
				'post_status'    => 'inherit',
				'post_parent'    => $postId,
			],
			wp_upload_dir()['path'] . '/' . $filename,
			$postId
		);

		if ( is_wp_error( $attachment ) || ! $attachment ) {
			Eagle_Logger::error( sprintf( 'Agent photo attach failed for listing %d', $postId ) );
			return;
		}

		$metadata = wp_generate_attachment_metadata( $attachment, wp_upload_dir()['path'] . '/' . $filename );
		wp_update_attachment_metadata( $attachment, $metadata );
		update_post_meta( $postId, self::AGENT_PHOTO_META, $attachment );

		// Set the gallery field with the agent photo attachment.
		update_post_meta( $postId, $galleryKey, [ $attachment ] );

		Eagle_Logger::log( sprintf( 'Agent photo attached to listing %d (attachment %d)', $postId, $attachment ) );
	}

	private function write_custom_fields( int $postId, array $data ): void {
		$raw = [];

		foreach ( $data['customFields'] ?? [] as $cf ) {
			$name = isset( $cf['name'] ) ? (string) $cf['name'] : '';
			$val  = $cf['value'] ?? null;
			$cfKey = (string) ( $cf['key'] ?? '' );

			if ( '' === $cfKey || $val === null || $val === '' ) {
				$raw[ $name ] = $val;
				continue;
			}

			$mapKey = 'custom_' . sanitize_key( $cfKey );
			$fieldId = $this->fields->get_field_id( $mapKey );

			if ( ! $fieldId ) {
				$mapKey = 'custom_' . sanitize_key( $name );
				$fieldId = $this->fields->get_field_id( $mapKey );
			}

			if ( ! $fieldId ) {
				$fieldId = $this->fields->create_field( $mapKey, $name, 'text' );
			}

			if ( $fieldId && $val !== null && $val !== '' ) {
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
			// Preserve the top-level price (dollars) — listingDetails.price is in
			// cents (100× the real value) and would overwrite it on merge.
			$rootPrice = $node['price'] ?? null;
			$data = array_merge( $data, $node['listingDetails'] );
			if ( $rootPrice !== null ) {
				$data['price'] = $rootPrice;
			}
		}

		$data['images']     = $node['images'] ?? [];
		$data['floorplans'] = $node['floorplans'] ?? [];
		$data['customFields'] = $node['customFields'] ?? [];
		$data['office']     = $node['office'] ?? '';

		// The API nests the full address under `address.formattedFullAddress` but
		// also exposes a short `formattedAddress` at the top level. Lift it so the
		// field writer can store it like any other scalar. Fall back to the
		// top-level `formattedAddress` when the nested one is empty — some Eagle
		// accounts return the address only there.
		if ( isset( $node['address']['formattedFullAddress'] ) && $node['address']['formattedFullAddress'] !== '' ) {
			$data['formattedFullAddress'] = $node['address']['formattedFullAddress'];
		} elseif ( ! empty( $node['formattedAddress'] ) ) {
			$data['formattedFullAddress'] = $node['formattedAddress'];
		}

		// Parse suburb, state, postcode from the formatted address.
		$fullAddr = $data['formattedFullAddress'] ?? '';
		if ( preg_match( '/,\s*(.+?)\s+([A-Z]{2,3})\s+(\d{4})\s*$/', $fullAddr, $addrMatch ) ) {
			$data['suburb']  = trim( $addrMatch[1], ", \t\n\r\0\x0B" );
			$data['state']   = $addrMatch[2];
			$data['postcode'] = $addrMatch[3];
			Eagle_Logger::log(
				sprintf( 'Address parsed: suburb="%s" state="%s" postcode="%s"', $data['suburb'], $data['state'], $data['postcode'] )
			);
		} else {
			// Diagnostics: log the raw address so a failed parse can be inspected.
			Eagle_Logger::log(
				sprintf( 'Address parse FAILED for "%s"', $fullAddr )
			);
		}

		// Vendors come as a nested object — store as JSON text.
		if ( isset( $node['vendors'] ) && is_array( $node['vendors'] ) ) {
			$data['vendors'] = wp_json_encode( $node['vendors'] );
		} else {
			$data['vendors'] = '';
		}

		// Extract primary agent details for individual fields.
		$agents = $node['agents'] ?? [];
		$primary = reset( $agents );
		if ( is_array( $primary ) ) {
			$office = $primary['office'] ?? '';
			if ( is_array( $office ) ) {
				$office = $office['name'] ?? '';
			}
			$data['agentName']   = trim( (string) ( $primary['name'] ?? '' ) );
			$data['agentTitle']  = trim( (string) ( $primary['title'] ?? '' ) );
			$data['agentEmail']  = trim( (string) ( $primary['email'] ?? '' ) );
			$data['agentPhone']  = trim( (string) ( $primary['phone'] ?? '' ) );
			$data['agentMobile'] = trim( (string) ( $primary['mobile'] ?? '' ) );
			$data['agentOffice'] = trim( (string) $office );
			$data['agentAvatarUrl'] = trim( (string) ( $primary['avatarUrl'] ?? '' ) );
		}

		if ( isset( $node['location'] ) && is_array( $node['location'] ) ) {
			$loc             = $node['location'];
			$data['location'] = [
				'lat'     => (string) ( $loc['latitude'] ?? '' ),
				'lng'     => (string) ( $loc['longitude'] ?? '' ),
				'address' => (string) ( $node['formattedAddress'] ?? '' ),
			];
		} elseif ( isset( $node['latitude'], $node['longitude'] ) && $node['latitude'] !== null && $node['longitude'] !== null ) {
			// The API exposes coordinates as scalar fields, not as a
			// location object; the map/search views need them stored.
			$data['location'] = [
				'lat'     => (string) $node['latitude'],
				'lng'     => (string) $node['longitude'],
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

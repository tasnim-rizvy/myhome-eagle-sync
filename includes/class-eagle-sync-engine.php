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
	public const IMAGE_ATTEMPTS_META = '_mes_eagle_image_attempts';

	// Per-request work budgets (all filterable). A single HTTP request never
	// downloads or resizes more than these, so one image-heavy listing spans
	// several polls instead of overrunning the proxy timeout or PHP memory
	// limit (the cause of the 503s on ~100 MB listings).
	private const DEFAULT_IMAGES_PER_REQUEST = 4;
	private const DEFAULT_BYTES_PER_REQUEST  = 20971520; // 20 MB.
	private const DEFAULT_TIME_BUDGET        = 20;        // Seconds of new work per request.
	private const DEFAULT_MAX_IMAGE_ATTEMPTS = 3;

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
				'processed'    => (int) ( $state['processed'] ?? 0 ),
				'total'        => (int) ( $state['total'] ?? 0 ),
				'offset'       => (int) ( $state['offset'] ?? 0 ),
				'images_done'  => (int) ( $state['current_images_done'] ?? 0 ),
				'images_total' => (int) ( $state['current_images_total'] ?? 0 ),
			];
		}

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

		// Mark this request as the active worker so overlapping requests
		// report progress instead of importing the same batch twice. The lease
		// outlives one bounded request (time budget + one in-flight image
		// download, which can reach its 120s curl timeout) yet still frees on
		// its own if the request is killed.
		$state['busy_until'] = time() + 180;
		self::set_state( $state );

		$page = $this->client->fetch_properties_page( $batch, $state['offset'] );
		if ( is_wp_error( $page ) ) {
			$state['phase'] = 'error';
			$state['last_error'] = $page->get_error_message();
			unset( $state['busy_until'] );
			self::set_state( $state );
			return [ 'phase' => 'error', 'message' => $state['last_error'] ];
		}

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
				return $this->upsert_result( is_wp_error( $postId ) ? $postId->get_error_message() : 'insert failed' );
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

		// Import galleries in bounded chunks; a large listing therefore spans
		// several polls rather than one oversized request.
		$gallery    = $this->write_gallery( $postId, $data['images'], 'gallery' );
		$floorplans = $this->write_gallery( $postId, $data['floorplans'], 'floorplans' );

		$this->write_agents( $postId, $data );
		$this->write_custom_fields( $postId, $data );

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

		$attempts = get_post_meta( $postId, self::IMAGE_ATTEMPTS_META, true );
		if ( ! is_array( $attempts ) ) {
			$attempts = [];
		}

		$total         = 0;
		$done          = 0;
		$attachmentIds = [];
		$pending       = [];

		foreach ( $images as $image ) {
			$url = (string) ( $image['url'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$total++;

			$key      = $this->image_dedup_key( $image );
			$existing = $this->find_attachment_by_key( $key );

			if ( $existing ) {
				$attachmentIds[] = $existing;
				$done++;
				continue;
			}

			// Give up on images that have already failed too many times so a
			// single broken URL can never block the listing forever; count them
			// as resolved so the gallery can reach "complete".
			if ( (int) ( $attempts[ $key ] ?? 0 ) >= $this->max_image_attempts ) {
				$done++;
				continue;
			}

			$pending[] = [ 'url' => $url, 'key' => $key ];
		}

		// Download pending images in time-boxed slices, resuming a large image
		// across polls with HTTP Range. This keeps every request short in
		// wall-clock so Hostinger's LiteSpeed/LVE never kills it (the 503), no
		// matter how slow S3 is or how big a single photo is.
		$touched = false;
		foreach ( $pending as $image ) {
			if ( $this->images_remaining <= 0 || $this->bytes_remaining <= 0 || time() >= $this->request_deadline ) {
				break;
			}

			$slice = max( 3, $this->request_deadline - time() );
			$res   = $this->stream_download_image( $image['url'], $image['key'], $slice );
			$touched = true;

			$this->bytes_remaining -= (int) ( $res['bytes'] ?? 0 );

			if ( 'complete' === $res['status'] ) {
				$this->maybe_raise_image_memory();
				$attId = $this->insert_image_from_tmp( $image['url'], $res['tmp'], $postId );
				if ( $attId ) {
					update_post_meta( $attId, self::IMAGE_META, $image['key'] );
					$attachmentIds[] = $attId;
					$done++;
					$this->images_remaining--;
					unset( $attempts[ $image['key'] ] );
				} else {
					$attempts[ $image['key'] ] = (int) ( $attempts[ $image['key'] ] ?? 0 ) + 1;
				}
				continue;
			}

			if ( 'partial' === $res['status'] ) {
				// Still downloading; it resumes on the next poll. This request's
				// budget is spent on this one image.
				break;
			}

			// Failed this attempt; count it so a permanently broken URL is
			// eventually skipped instead of wedging the listing.
			$attempts[ $image['key'] ] = (int) ( $attempts[ $image['key'] ] ?? 0 ) + 1;
			if ( ! empty( $res['error'] ) ) {
				Eagle_Logger::error( 'Image download failed for ' . $image['url'] . ': ' . $res['error'] );
			}
		}

		if ( $touched ) {
			update_post_meta( $postId, self::IMAGE_ATTEMPTS_META, $attempts );
		}

		// Update the field with everything gathered so far and set the featured
		// image on the first pass; both are safe to repeat as the gallery grows.
		if ( ! empty( $attachmentIds ) ) {
			$field = tdf_field_factory()->create( $fieldId );
			if ( $field ) {
				$field->setValue( $this->listing_model( $postId ), $attachmentIds );
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
	private function find_attachment_by_key( string $key ): int {
		if ( $key === '' ) {
			return 0;
		}

		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => self::IMAGE_META,
				'meta_value'     => $key,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return ! empty( $existing ) ? (int) $existing[0] : 0;
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
	 * Download one image in a time-boxed slice, resuming across calls.
	 *
	 * The partial download is persisted under uploads/mes-tmp and continued on
	 * the next poll with an HTTP Range request, so an arbitrarily large image
	 * is fetched over several short requests instead of one long request that
	 * the host would kill. curl's own timeout is capped to the slice, which is
	 * what actually bounds each request's wall-clock.
	 *
	 * @return array{status:string,tmp:?string,error:?string,bytes:int}
	 *         status: complete | partial | failed
	 */
	private function stream_download_image( string $url, string $key, int $budget ): array {
		if ( ! function_exists( 'curl_init' ) ) {
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'curl unavailable', 'bytes' => 0 ];
		}

		$dir = $this->partial_dir();
		if ( '' === $dir ) {
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'no temp dir', 'bytes' => 0 ];
		}

		$part   = $dir . '/' . md5( $key ) . '.part';
		$offset = file_exists( $part ) ? (int) filesize( $part ) : 0;

		$fh = fopen( $part, 'cb' ); // Open for write, keep existing bytes.
		if ( false === $fh ) {
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'cannot open partial', 'bytes' => 0 ];
		}
		fseek( $fh, 0, SEEK_END );

		$total    = 0;
		$sawRange = false;

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_FILE, $fh );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, min( 15, max( 5, $budget ) ) );
		curl_setopt( $ch, CURLOPT_TIMEOUT, max( 5, $budget ) );
		if ( $offset > 0 ) {
			curl_setopt( $ch, CURLOPT_RESUME_FROM, $offset );
		}
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			static function ( $handle, $header ) use ( &$total, &$sawRange, $offset ) {
				if ( 0 === stripos( $header, 'Content-Range:' ) ) {
					if ( preg_match( '#/\s*(\d+)\s*$#', $header, $m ) ) {
						$total = (int) $m[1];
					}
					$sawRange = true;
				} elseif ( 0 === $offset && ! $sawRange && 0 === stripos( $header, 'Content-Length:' ) ) {
					$total = (int) trim( substr( $header, strlen( 'Content-Length:' ) ) );
				}
				return strlen( $header );
			}
		);

		curl_exec( $ch );
		$errno = curl_errno( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err   = curl_error( $ch );
		curl_close( $ch );
		fclose( $fh );

		$have  = file_exists( $part ) ? (int) filesize( $part ) : 0;
		$bytes = max( 0, $have - $offset );

		// Server ignored the Range (200 to a ranged request): our appended
		// partial is now inconsistent — discard it and restart next poll.
		if ( $offset > 0 && 200 === $code ) {
			@unlink( $part );
			return [ 'status' => 'partial', 'tmp' => null, 'error' => null, 'bytes' => 0 ];
		}

		// 416 = requested range past EOF, i.e. we already hold the whole file.
		if ( 416 === $code && $have > 0 ) {
			return [ 'status' => 'complete', 'tmp' => $part, 'error' => null, 'bytes' => 0 ];
		}

		// Non-retryable HTTP errors: drop the partial so a later run starts clean.
		if ( $code >= 400 ) {
			if ( ! ( 429 === $code || $code >= 500 ) ) {
				@unlink( $part );
			}
			return [ 'status' => 'failed', 'tmp' => null, 'error' => 'HTTP ' . $code, 'bytes' => $bytes ];
		}

		$complete = ( $total > 0 && $have >= $total )
			|| ( CURLE_OK === $errno && 0 === $offset && 200 === $code && $have > 0 && 0 === $total );

		if ( $complete && $have > 0 ) {
			return [ 'status' => 'complete', 'tmp' => $part, 'error' => null, 'bytes' => $bytes ];
		}

		// Made progress but not finished (usually the slice timed out): resume.
		if ( $bytes > 0 ) {
			return [ 'status' => 'partial', 'tmp' => null, 'error' => null, 'bytes' => $bytes ];
		}

		// No progress at all: report a failure so repeated dead attempts exhaust.
		return [
			'status' => 'failed',
			'tmp'    => null,
			'error'  => '' !== $err ? ( 'cURL ' . $errno . ': ' . $err ) : ( 'HTTP ' . $code ),
			'bytes'  => 0,
		];
	}

	/**
	 * Directory for in-progress image downloads (created on demand). Empty
	 * string when the uploads directory is unavailable.
	 */
	private function partial_dir(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = $uploads['basedir'] . '/mes-tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return is_dir( $dir ) ? $dir : '';
	}

	/**
	 * Create an attachment from a downloaded temp file.
	 */
	private function insert_image_from_tmp( string $url, string $tmp, int $postId ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file = [
			'name'     => basename( $url ),
			'type'     => wp_check_filetype( basename( $url ) )['type'],
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		];

		$sideload = wp_handle_sideload( $file, [ 'test_form' => false ] );
		if ( isset( $sideload['error'] ) ) {
			@unlink( $tmp );
			Eagle_Logger::error( 'Image sideload failed for ' . $url . ': ' . $sideload['error'] );
			return false;
		}

		$attachment = [
			'post_mime_type' => $sideload['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', sanitize_file_name( basename( $sideload['file'] ) ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attId = wp_insert_attachment( $attachment, $sideload['file'], $postId );
		if ( ! $attId || is_wp_error( $attId ) ) {
			@unlink( $sideload['file'] );
			Eagle_Logger::error( 'Attachment insert failed for ' . $url );
			return false;
		}

		$meta = wp_generate_attachment_metadata( $attId, $sideload['file'] );
		wp_update_attachment_metadata( $attId, $meta );

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

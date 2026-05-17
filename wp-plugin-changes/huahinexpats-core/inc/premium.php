<?php
/**
 * Premium Listing Tiers — gallery meta box, query helpers
 *
 * Gallery data stored as comma-separated attachment IDs in _hhe_gallery.
 * Premium level (free/verified/premium/premium_plus) is stored in _hhe_premium_level
 * and managed in meta-boxes.php Status box.
 *
 * @package HuaHinExpats
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Gallery meta box
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', 'hhec_register_gallery_meta_box' );

function hhec_register_gallery_meta_box() {
	add_meta_box(
		'hhec_listing_gallery',
		__( 'Image Gallery', 'huahinexpats-core' ),
		'hhec_listing_gallery_cb',
		'listing',
		'normal',
		'default'
	);
}

function hhec_listing_gallery_cb( $post ) {
	wp_nonce_field( 'hhec_gallery_save', 'hhec_gallery_nonce' );

	$raw = get_post_meta( $post->ID, '_hhe_gallery', true );
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ?: '' ) ) );
	?>
	<style>
		.hhec-gallery-grid { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; min-height:50px; padding:6px 0; }
		.hhec-gallery-item { position:relative; }
		.hhec-gallery-item img { width:80px; height:80px; object-fit:cover; border-radius:4px; border:1px solid #ddd; display:block; }
		.hhec-gallery-remove { position:absolute; top:-7px; right:-7px; background:#c00; color:#fff; border:none; border-radius:50%; width:20px; height:20px; font-size:13px; cursor:pointer; line-height:20px; text-align:center; padding:0; font-weight:700; }
		.hhec-gallery-remove:hover { background:#900; }
	</style>

	<div class="hhec-gallery-grid" id="hhec-gallery-grid">
		<?php foreach ( $ids as $attachment_id ) : ?>
			<?php $thumb = wp_get_attachment_image_src( $attachment_id, array( 80, 80 ) ); ?>
			<?php if ( $thumb ) : ?>
				<div class="hhec-gallery-item" data-id="<?php echo absint( $attachment_id ); ?>">
					<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="">
					<button type="button" class="hhec-gallery-remove" title="<?php esc_attr_e( 'Remove', 'huahinexpats-core' ); ?>" data-id="<?php echo absint( $attachment_id ); ?>">×</button>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>

	<input type="hidden" id="hhec_gallery_ids" name="hhec_gallery_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">

	<button type="button" id="hhec-gallery-add" class="button button-secondary">
		<?php esc_html_e( 'Add Images', 'huahinexpats-core' ); ?>
	</button>

	<?php
	$current_level = get_post_meta( $post->ID, '_hhe_premium_level', true ) ?: 'free';
	$gallery_caps  = array( 'premium_plus' => 12, 'premium' => 8, 'verified' => 3, 'free' => 1 );
	$cap           = $gallery_caps[ $current_level ] ?? 1;
	$tier_label    = array( 'premium_plus' => 'Premium Plus', 'premium' => 'Premium', 'verified' => 'Verified', 'free' => 'Free' );
	?>
	<p class="description" style="margin-top:8px;">
		<?php
		printf(
			/* translators: 1: current tier name, 2: max images for this tier */
			esc_html__( 'Current tier: %1$s, max %2$d image(s). Free: 1 · Verified: 3 · Premium: 8 · Premium Plus: 12. Excess images are trimmed on save.', 'huahinexpats-core' ),
			esc_html( $tier_label[ $current_level ] ),
			$cap
		);
		?>
	</p>

	<script>
	(function($){
		var frame;
		var $grid  = $('#hhec-gallery-grid');
		var $input = $('#hhec_gallery_ids');

		function getIds() {
			var val = $input.val();
			return val ? val.split(',').map(Number).filter(Boolean) : [];
		}
		function setIds(ids) {
			$input.val( ids.length ? ids.join(',') : '' );
		}

		$('#hhec-gallery-add').on('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title:    <?php echo wp_json_encode( __( 'Select Gallery Images', 'huahinexpats-core' ) ); ?>,
				button:   { text: <?php echo wp_json_encode( __( 'Add to Gallery', 'huahinexpats-core' ) ); ?> },
				multiple: true,
				library:  { type: 'image' }
			});
			frame.on('select', function(){
				var selection = frame.state().get('selection');
				var ids = getIds();
				selection.each(function(attachment){
					var id = attachment.id;
					if (ids.indexOf(id) === -1) {
						ids.push(id);
						var sizes = attachment.get('sizes');
						var thumb = sizes && sizes.thumbnail ? sizes.thumbnail.url : attachment.get('url');
						$grid.append(
							'<div class="hhec-gallery-item" data-id="' + id + '">' +
							'<img src="' + thumb + '" alt="">' +
							'<button type="button" class="hhec-gallery-remove" data-id="' + id + '" title="Remove">×</button>' +
							'</div>'
						);
					}
				});
				setIds(ids);
			});
			frame.open();
		});

		$grid.on('click', '.hhec-gallery-remove', function(){
			var id = parseInt($(this).data('id'), 10);
			$(this).closest('.hhec-gallery-item').remove();
			setIds( getIds().filter(function(i){ return i !== id; }) );
		});
	})(jQuery);
	</script>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// Save gallery
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'save_post_listing', 'hhec_save_gallery_meta', 20 );

function hhec_save_gallery_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	if ( ! isset( $_POST['hhec_gallery_nonce'] ) ) return;
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hhec_gallery_nonce'] ) ), 'hhec_gallery_save' ) ) return;

	$raw = isset( $_POST['hhec_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['hhec_gallery_ids'] ) ) : '';
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	// Enforce per-tier gallery image cap.
	// Runs at priority 20, after hhec_save_listing_meta (priority 10) has already
	// written the updated _hhe_premium_level, so get_post_meta reflects the new tier.
	$level = get_post_meta( $post_id, '_hhe_premium_level', true ) ?: 'free';
	$caps  = array( 'premium_plus' => 12, 'premium' => 8, 'verified' => 3, 'free' => 1 );
	$max   = $caps[ $level ] ?? 1;
	$ids   = array_slice( array_values( $ids ), 0, $max );

	update_post_meta( $post_id, '_hhe_gallery', implode( ',', $ids ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Query helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Score a listing by its commercial priority.
 * Used for PHP-side sorting when WP_Query meta ordering is impractical.
 *
 * @param int $post_id
 * @return int  Higher = higher priority.
 */
function hhec_get_listing_priority_score( $post_id ) {
	$score = 0;
	if ( get_post_meta( $post_id, '_hhe_featured', true ) === '1' ) {
		$score += 100;
	}
	$level = get_post_meta( $post_id, '_hhe_premium_level', true );
	if ( $level === 'premium_plus' ) {
		$score += 50;
	} elseif ( $level === 'premium' ) {
		$score += 25;
	} elseif ( $level === 'verified' ) {
		$score += 10;
	}
	// Binary verified flag (auto-detected via phone+website OR manually set) layers
	// independently. A paid verified-tier listing that is also fully filled-out gets +20
	// — matches how featured can compound with premium tiers.
	if ( get_post_meta( $post_id, '_hhe_verified', true ) === '1' ) {
		$score += 10;
	}
	return $score;
}

/**
 * Sort an array of WP_Post objects by commercial priority (descending).
 *
 * @param WP_Post[] $posts
 * @return WP_Post[]
 */
function hhec_sort_posts_by_priority( array $posts ) {
	usort( $posts, function( $a, $b ) {
		return hhec_get_listing_priority_score( $b->ID ) - hhec_get_listing_priority_score( $a->ID );
	} );
	return $posts;
}

/**
 * Persist the derived priority score as a single numeric meta on each save.
 * This is what archive `pre_get_posts` orders by — keeps SQL ordering simple
 * and lets pagination respect commercial priority globally (not just on the
 * page currently being rendered).
 *
 * Hooked at priority 30 so it runs after meta-box save (10), gallery save
 * (20), and any premium-level changes have already been written.
 */
add_action( 'save_post_listing', 'hhec_save_priority_score_meta', 30 );

function hhec_save_priority_score_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	update_post_meta( $post_id, '_hhe_priority_score', hhec_get_listing_priority_score( $post_id ) );
}

/**
 * Re-derive the score whenever the underlying meta keys change directly
 * (e.g. via webhook tier updates that bypass save_post).
 */
add_action( 'updated_post_meta', 'hhec_resync_priority_on_meta_change', 10, 4 );
add_action( 'added_post_meta',   'hhec_resync_priority_on_meta_change', 10, 4 );
add_action( 'deleted_post_meta', 'hhec_resync_priority_on_meta_change', 10, 4 );

function hhec_resync_priority_on_meta_change( $meta_id, $post_id, $meta_key, $_meta_value ) {
	$watched = array( '_hhe_premium_level', '_hhe_featured', '_hhe_verified' );
	if ( ! in_array( $meta_key, $watched, true ) ) return;
	if ( get_post_type( $post_id ) !== 'listing' ) return;
	// Re-derive without recursing — the priority key itself isn't watched.
	update_post_meta( $post_id, '_hhe_priority_score', hhec_get_listing_priority_score( $post_id ) );
}

/**
 * Apply commercial-priority ordering to listing archives, taxonomy archives,
 * and the directory search query. Featured > Premium+ > Premium > Verified > Free.
 *
 * Uses the persisted _hhe_priority_score so SQL handles the ordering and
 * pagination remains correct — without this, premium listings on later
 * pages would be visually downgraded by date sort.
 */
add_action( 'pre_get_posts', 'hhec_apply_priority_to_archives' );

function hhec_apply_priority_to_archives( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) return;

	$applies =
		$query->is_post_type_archive( 'listing' )
		|| $query->is_tax( 'listing_category' )
		|| $query->is_tax( 'listing_area' );

	if ( ! $applies ) return;

	$query->set( 'meta_query', array(
		'relation' => 'OR',
		array( 'key' => '_hhe_priority_score', 'compare' => 'EXISTS' ),
		array( 'key' => '_hhe_priority_score', 'compare' => 'NOT EXISTS' ),
	) );
	$query->set( 'orderby', array(
		'meta_value_num' => 'DESC',
		'date'           => 'DESC',
	) );
	$query->set( 'meta_key', '_hhe_priority_score' );
}

/**
 * One-time backfill: write _hhe_priority_score for any listing missing one.
 * Runs on admin_init so it self-heals after activation without requiring a
 * manual CLI step. The flag is stored in wp_options so it only runs once.
 */
add_action( 'admin_init', 'hhec_backfill_priority_scores' );

function hhec_backfill_priority_scores() {
	if ( get_option( 'hhec_priority_scores_backfilled' ) ) return;

	$ids = get_posts( array(
		'post_type'      => 'listing',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	foreach ( $ids as $pid ) {
		update_post_meta( (int) $pid, '_hhe_priority_score', hhec_get_listing_priority_score( (int) $pid ) );
	}
	update_option( 'hhec_priority_scores_backfilled', 1, false );
}

/**
 * Get listing IDs ranked by average approved review rating.
 *
 * @param int      $limit          Maximum results.
 * @param string[] $category_slugs Optional listing_category slugs to filter by.
 * @return int[]
 */
function hhec_get_top_rated_listing_ids( $limit = 4, $category_slugs = array() ) {
	global $wpdb;
	$reviews_table = $wpdb->prefix . 'hhe_reviews';
	$limit         = absint( $limit );

	if ( ! empty( $category_slugs ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $category_slugs ), '%s' ) );
		$query_args   = array_merge( $category_slugs, array( $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr  ON tr.object_id          = p.ID
			 INNER JOIN {$wpdb->term_taxonomy}     tt  ON tt.term_taxonomy_id    = tr.term_taxonomy_id
			                                           AND tt.taxonomy            = 'listing_category'
			 INNER JOIN {$wpdb->terms}             t   ON t.term_id              = tt.term_id
			                                           AND t.slug                 IN ($placeholders)
			 INNER JOIN {$reviews_table}            r   ON r.listing_id           = p.ID
			                                           AND r.status               = 'approved'
			 WHERE p.post_type   = 'listing'
			   AND p.post_status = 'publish'
			 GROUP BY p.ID
			 HAVING COUNT(r.id) > 0
			 ORDER BY AVG(r.rating) DESC, COUNT(r.id) DESC
			 LIMIT %d",
			$query_args
		) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$reviews_table} r ON r.listing_id = p.ID AND r.status = 'approved'
			 WHERE p.post_type   = 'listing'
			   AND p.post_status = 'publish'
			 GROUP BY p.ID
			 HAVING COUNT(r.id) > 0
			 ORDER BY AVG(r.rating) DESC, COUNT(r.id) DESC
			 LIMIT %d",
			$limit
		) );
	}

	return $results ? array_map( 'intval', wp_list_pluck( $results, 'ID' ) ) : array();
}

/**
 * Get resolved gallery image URLs for a listing.
 * Falls through: gallery → featured image → category fallback.
 *
 * @param int    $post_id
 * @param string $size   WP image size.
 * @return string[]
 */
function hhec_get_listing_gallery_urls( $post_id, $size = 'medium_large' ) {
	$raw = get_post_meta( $post_id, '_hhe_gallery', true );
	if ( $raw ) {
		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		// Enforce tier cap at read time so downgraded listings stop showing
		// excess images immediately without requiring a re-save.
		$level        = get_post_meta( $post_id, '_hhe_premium_level', true ) ?: 'free';
		$gallery_caps = array( 'premium_plus' => 12, 'premium' => 8, 'verified' => 3, 'free' => 1 );
		$max          = $gallery_caps[ $level ] ?? 1;
		$ids          = array_slice( array_values( $ids ), 0, $max );

		$urls = array();
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( $src ) {
				$urls[] = $src[0];
			}
		}
		if ( ! empty( $urls ) ) return $urls;
	}

	if ( has_post_thumbnail( $post_id ) ) {
		$src = get_the_post_thumbnail_url( $post_id, $size );
		if ( $src ) return array( $src );
	}

	if ( function_exists( 'hhe_fallback_image_url' ) ) {
		return array( hhe_fallback_image_url( 'listing_category', $post_id ) );
	}

	return array();
}

// ─────────────────────────────────────────────────────────────────────────────
// Premium Expiry Cron
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_loaded', 'hhec_schedule_premium_expiry_cron' );

/**
 * Schedule the daily expiry check if it is not already in the cron queue.
 *
 * Hooked on 'wp_loaded' (fires on all request types: front-end, admin, REST,
 * WP-CLI) rather than 'wp' (front-end only) so the event gets registered even
 * on admin-only installs or low front-end traffic periods.
 *
 * WP-Cron reliability note: WP-Cron fires on page requests — if the site
 * receives little traffic the check may lag by hours. For production, configure
 * a true server cron to replace the WP scheduler:
 *
 *   1. Disable WP-Cron in wp-config.php:
 *      define( 'DISABLE_WP_CRON', true );
 *
 *   2. Add to server crontab (runs every 5 minutes):
 *      * / 5 * * * * wget -q -O /dev/null https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
 *      or via WP-CLI if available:
 *      * / 5 * * * * /usr/local/bin/wp cron event run --due-now --path=/path/to/wordpress >/dev/null 2>&1
 */
function hhec_schedule_premium_expiry_cron() {
	if ( ! wp_next_scheduled( 'hhec_premium_expiry_check' ) ) {
		wp_schedule_event( time(), 'daily', 'hhec_premium_expiry_check' );
	}
}

add_action( 'hhec_premium_expiry_check',         'hhec_run_premium_expiry_check' );
add_action( 'hhec_premium_expiry_check',         'hhec_run_premium_expiry_warnings' ); // same daily slot.

/**
 * Downgrade any listing whose _hhe_premium_expires date has passed.
 *
 * Safe to call multiple times (idempotent). Intentionally separated from
 * any payment logic so it can later be triggered by a payment webhook as well.
 *
 * Uses gmdate() so expiry comparisons are timezone-consistent regardless of
 * the site's WordPress timezone setting.
 */
function hhec_run_premium_expiry_check() {
	$today = gmdate( 'Y-m-d' );

	$expired_ids = get_posts( array(
		'post_type'      => 'listing',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => '_hhe_premium_expires',
				'value'   => '',
				'compare' => '!=',
			),
			array(
				'key'     => '_hhe_premium_expires',
				'value'   => $today,
				'compare' => '<',
				'type'    => 'DATE',
			),
			array(
				'key'     => '_hhe_premium_level',
				'value'   => 'free',
				'compare' => '!=',
			),
		),
	) );

	foreach ( $expired_ids as $post_id ) {
		update_post_meta( (int) $post_id, '_hhe_premium_level', 'free' );

		// Sync any past_due subscription row for this listing to 'expired'.
		if ( function_exists( 'hhec_expire_subscriptions_for_listing' ) ) {
			hhec_expire_subscriptions_for_listing( (int) $post_id );
		}
	}
}

/**
 * Email premium owners 7 days before their plan expires (or hits past-due).
 *
 * Idempotent — _hhe_expiry_warning_sent is keyed by the expiry date so a
 * single warning fires per renewal cycle. Resetting the meta lets a manual
 * re-fire happen if needed.
 */
function hhec_run_premium_expiry_warnings() {
	$today        = gmdate( 'Y-m-d' );
	$warn_date    = gmdate( 'Y-m-d', strtotime( '+7 days', strtotime( $today ) ) );

	$listing_ids = get_posts( array(
		'post_type'      => 'listing',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => '_hhe_premium_level',
				'value'   => 'free',
				'compare' => '!=',
			),
			array(
				'key'     => '_hhe_premium_expires',
				'value'   => $warn_date,
				'compare' => '=',
			),
		),
	) );

	if ( empty( $listing_ids ) ) return;

	foreach ( $listing_ids as $listing_id ) {
		$listing_id = (int) $listing_id;

		// Skip if already warned for this exact expiry date.
		if ( get_post_meta( $listing_id, '_hhe_expiry_warning_sent', true ) === $warn_date ) continue;

		$owner_id = (int) get_post_meta( $listing_id, '_hhe_owner_user_id', true );
		if ( ! $owner_id ) continue;
		$user = get_user_by( 'id', $owner_id );
		if ( ! $user ) continue;

		hhec_send_premium_expiry_warning_email( $listing_id, $user, $warn_date );
		update_post_meta( $listing_id, '_hhe_expiry_warning_sent', $warn_date );
	}
}

function hhec_send_premium_expiry_warning_email( $listing_id, $user, $expiry_date ) {
	$listing_title = get_the_title( (int) $listing_id );
	$tier          = get_post_meta( $listing_id, '_hhe_premium_level', true ) ?: 'premium';
	$tier_label    = ( 'premium_plus' === $tier ) ? __( 'Premium Plus', 'huahinexpats-core' ) : __( 'Premium', 'huahinexpats-core' );
	$dashboard_url = function_exists( 'hhec_owner_dashboard_url' ) ? hhec_owner_dashboard_url() : home_url( '/my-listings/' );
	$site_name     = get_bloginfo( 'name' );
	$expiry_human  = date_i18n( get_option( 'date_format' ), strtotime( $expiry_date ) );

	/* translators: 1: listing name */
	$subject = sprintf( __( 'Your %s plan renews in 7 days', 'huahinexpats-core' ), $tier_label );

	$lines = array(
		sprintf( __( 'Hi %s,', 'huahinexpats-core' ), $user->display_name ?: $user->user_login ),
		'',
		sprintf(
			/* translators: 1: listing name, 2: tier label, 3: date */
			__( 'Your listing "%1$s" (%2$s) is set to renew on %3$s.', 'huahinexpats-core' ),
			$listing_title,
			$tier_label,
			$expiry_human
		),
		'',
		__( 'No action needed if you want to continue. To change or cancel, open your dashboard:', 'huahinexpats-core' ),
		'  ' . $dashboard_url,
		'',
		'---',
		sprintf( __( 'The %s team', 'huahinexpats-core' ), $site_name ),
	);

	// Route via hhec_email_send so the warning sends from accounts@ and
	// gets logged under billing_expiry_warning. respects_test=false keeps
	// billing-critical mail flowing even when outreach test mode is on.
	if ( function_exists( 'hhec_email_send' ) ) {
		hhec_email_send( array(
			'to'            => $user->user_email,
			'subject'       => $subject,
			'body_text'     => implode( "\n", $lines ),
			'from_role'     => 'accounts',
			'reply_to'      => hhec_email_address( 'accounts' ),
			'category'      => 'billing_expiry_warning',
			'listing_id'    => (int) $listing_id,
			'respects_test' => false,
			'respects_supp' => false,
		) );
	} else {
		wp_mail(
			$user->user_email,
			$subject,
			implode( "\n", $lines ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}
}


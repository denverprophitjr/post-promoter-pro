<?php
/**
 * Create timestamps and unique identifiers for each cron.
 * @param  int $month
 * @param  int $day
 * @param  int $year
 * @param  int $post_id
 * @return array
 */
function ppp_get_timestamps( $month, $day, $year, $post_id ) {
	global $ppp_options, $ppp_social_settings;
	$days_ahead = 1;
	$times = array();
	$offset = (int) -( get_option( 'gmt_offset' ) ); // Make the timestamp in the users' timezone, b/c that makes more sense

	$ppp_post_override = get_post_meta( $post_id, '_ppp_post_override', true );
	$ppp_post_override_data = get_post_meta( $post_id, '_ppp_post_override_data', true );
	$override_times = wp_list_pluck( $ppp_post_override_data, 'time' );

	$tweet_times = ( empty( $ppp_post_override ) ) ? $ppp_options['times'] : $override_times;

	$times = array();
	foreach ( $tweet_times as $key => $data ) {
		$days_ahead = substr( $key, -1 );
		$share_time = explode( ':', $data );

		if ( strtolower( substr( $share_time[1], -2 ) == 'pm' ) && $hours != 12 ) {
			$hours = $hours + 12;
		}

		if ( strtolower( substr( $share_time[1], -2 ) == 'am' ) && $hours == 12 ) {
			$hours = 00;
		}
		$hours   = (int)$share_time[0] + $offset;

		$minutes = (int)substr( $share_time[1], 0, 2 );

		$timestamp = mktime( $hours, $minutes, 0, $month, $day + $days_ahead, $year );

		if ( $timestamp > time() ) { // Make sure the timestamp we're getting is in the future
			$times[strtotime( date_i18n( 'd-m-Y H:i:s', $timestamp , true ) )] = 'sharedate_' . $days_ahead . '_' . $post_id;
		}
	}

	return apply_filters( 'ppp_get_timestamps', $times );
}

/**
 * Schedule social media posts with wp_schedule_single_event
 * @param  id $post_id
 * @param  object $post
 * @return void
 */
function ppp_schedule_share( $post_id, $post ) {
	global $ppp_options;

	$allowed_post_types = isset( $ppp_options['post_types'] ) ? $ppp_options['post_types'] : array();
	$allowed_post_types = apply_filters( 'ppp_schedule_share_post_types', $allowed_post_types );

	if ( !isset( $_POST['post_status'] ) || !array_key_exists( $post->post_type, $allowed_post_types ) ) {
		return;
	}

	$ppp_post_exclude = get_post_meta( $post->ID, '_ppp_post_exclude', true );
	if ( $ppp_post_exclude ) { // If the post meta says to exclude from social media posts, delete all scheduled and return
		ppp_remove_scheduled_shares( $post_id );
		return;
	}

	if ( ( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] == 'publish' ) ||
	     ( $_POST['post_status'] == 'future' && $_POST['original_post_status'] == 'future' ) ) {
		// Be sure to clear any currently scheduled tweets so we aren't creating multiple instances
		// This will stop something from moving between draft and post and continuing to schedule tweets
		ppp_remove_scheduled_shares( $post_id );
	}

	if( ( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] != 'publish' ) || // From anything to published
		( $_POST['post_status'] == 'future' && $_POST['original_post_status'] == 'future' ) || // Updating a future post
		( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] == 'publish' ) ) { // Updating an already published post
		global $ppp_options, $ppp_social_settings;

		$timestamps = ppp_get_timestamps( $_POST['mm'], $_POST['jj'], $_POST['aa'], $post_id );

		foreach ( $timestamps as $timestamp => $name ) {
			wp_schedule_single_event( $timestamp, 'ppp_share_post_event', array( $post_id, $name ) );
		}
	}
}
add_action( 'ppp_share_post_event', 'ppp_share_post', 10, 2 );

/**
 * Hook for the crons to fire and send tweets
 * @param  id $post_id
 * @param  string $name
 * @return void
 */
function ppp_share_post( $post_id, $name ) {
	global $ppp_options, $ppp_social_settings, $ppp_share_settings, $ppp_twitter_oauth;
	$post = get_post( $post_id, OBJECT );

	$ppp_post_override = get_post_meta( $post_id, '_ppp_post_override', true );
	if ( !empty( $ppp_post_override ) ) {
		$ppp_post_override_data = get_post_meta( $post_id, '_ppp_post_override_data', true );
		$name_array = explode( '_', $name );
		$day = 'day' . $name_array[1];
		$tweet_text = $ppp_post_override_data[$day]['text'];
	}

	$tweet_text = isset( $tweet_text ) ? $tweet_text : $post->post_title;

	$tweet_link = get_permalink( $post_id );

	if ( isset( $ppp_share_settings['ppp_unique_links'] ) ) {
		$tweet_link .= strpos( $tweet_link, '?' ) ? '&' : '?' ;
		$name_parts = explode( '_', $name );
		$tweet_link .= 'ppp=' . $post_id . '-' . $name_parts[1];
	}

	$tweet = $tweet_text . ' ' . $tweet_link;

	$status['twitter'] = apply_filters( 'ppp_twitter_tweet', $ppp_twitter_oauth->ppp_tweet( $tweet ) );

	if ( $ppp_options['enable_debug'] == '1' ) {
		update_post_meta( $post_id, '_ppp-' . $name . '-status', $status );
	}
}

function ppp_remove_scheduled_shares( $post_id ) {
	do_action( 'ppp_pre_remove_scheduled_shares', $post_id );
	$days_ahead = 1;
	while ( $days_ahead <= 6 ) {
		$name = 'sharedate_' . $days_ahead . '_' . $post_id;
		wp_clear_scheduled_hook( 'ppp_share_post_event', array( $post_id, $name ) );

		$days_ahead++;
	}
	do_action( 'ppp_post_remove_scheduled_shares', $post_id );
}

function ppp_set_social_tokens() {
	$social_tokens = get_transient( 'ppp_social_tokens' );

	if ( !$social_tokens ) {
		$license = trim( get_option( '_ppp_license_key' ) );
		$url = PPP_STORE_URL . '/ppp-get-tokens?ppp-license-key=' . $license;
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$social_tokens = json_decode( wp_remote_retrieve_body( $response ) );
		if ( !isset( $social_tokens->error ) && isset( $social_tokens->twitter ) ) {
			set_transient( 'ppp_social_tokens', $social_tokens, WEEK_IN_SECONDS );
		}
	}

	if ( property_exists( $social_tokens, 'twitter' ) ) {
		define( 'PPP_TW_CONSUMER_KEY', $social_tokens->twitter->consumer_token );
		define( 'PPP_TW_CONSUMER_SECRET', $social_tokens->twitter->consumer_secret );
	}
}
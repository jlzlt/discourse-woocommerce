<?php

/**
 * Plugin Name: Discourse WooCommerce Sync
 * Plugin URI: http://github/paviliondev/discourse-woocommerce-sync
 * Description: Syncs WooCommerce memberships with Discourse groups
 * Version: 0.3.2
 * Author: Angus McLeod
 * Author URI: http://thepavilion.io
 */

defined( 'ABSPATH' ) or die( 'No scripts' );

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

$member_group_map = array();
$member_group_map[] = (object) array('plan_id' => 348, 'group_id' => 41, 'group_name' => 'membership');
$member_group_map[] = (object) array('plan_id' => 379, 'group_id' => 41, 'group_name' => 'membership');
$member_group_map[] = (object) array('plan_id' => 76155, 'group_id' => 41, 'group_name' => 'membership');

$product_group_map = array();
$product_group_map[] = (object) array('product_ids' => [], 'group_id' => 41, 'group_name' => 'membership');

function get_discourse_group_ids($plan_id) {
	global $member_group_map;
	$group_ids = array();

	foreach ($member_group_map as $item) {
		if ($plan_id == $item->plan_id) {
			$group_ids[] = $item->group_id;
		}
	}

	return array_unique($group_ids);
}

const ACTIVE_STATUSES = array('wcm-active');
const INACTIVE_STATUSES = array('wcm-expired', 'wcm-cancelled');

function get_user_group_action($user_id, $group_id) {
	global $member_group_map;

	$plans_for_group = array_filter($member_group_map, function($item) use ($group_id) {
		return $item->group_id == $group_id;
	});

	$has_inactive_membership = false;

	foreach ($plans_for_group as $item) {
		$membership = wc_memberships_get_user_membership($user_id, $item->plan_id);
		if ($membership) {
			if (in_array($membership->status, ACTIVE_STATUSES, true)) {
				return 'PUT';
			}
			if (in_array($membership->status, INACTIVE_STATUSES, true)) {
				$has_inactive_membership = true;
			}
		}
	}

	return $has_inactive_membership ? 'DELETE' : null;
}

function update_discourse_group_access($user_id, $action, $group_id) {
	$options = DiscourseUtilities::get_options();
	$logger = wc_get_logger();
	$sso_secret_key = $options['sso-secret'];
	$sso_provider = $options['enable-sso'];
	$sso_client = $options['sso-client-enabled'];
	$sso_enabled = $sso_secret_key && ($sso_provider || $sso_client);

	if ( empty( $options['url'] ) || empty( $options['api-key'] ) || empty( $options['publish-username'] ) || ! $sso_enabled ) {
	  return new \WP_Error( 'discourse_configuration_error', 'The WP Discourse plugin has not been properly configured.' );
	}

	$logger->info( sprintf('Updating discourse group access %s %s %s', $user_id, $action, $group_id) );

	if ( $sso_provider ) {
		update_discourse_group_access_provider( $user_id, $action, $group_id );
	} else if ( $sso_client ) {
		update_discourse_group_access_client( $user_id, $action, $group_id );
	}
}

function update_discourse_group_access_provider($user_id, $action, $group_id) {
	global $member_group_map;
	global $product_group_map;
	$logger = wc_get_logger();
	$group_name = '';

	foreach( $member_group_map as $item ) {
		if ( $group_id == $item->group_id && !empty($item->group_name) ) {
			$group_name = $item->group_name;
			break;
		}
	}

	if (!$group_name) {
		foreach( $product_group_map as $item ) {
			if ( $group_id == $item->group_id && !empty($item->group_name) ) {
				$group_name = $item->group_name;
				break;
			}
		}
	}

	if (!$group_name) {
		$logger->info(sprintf('No group_name found for group_id %s - skipping provider sync', $group_id));
		return;
	}

	if ( $action === 'PUT') {
		DiscourseUtilities::add_user_to_discourse_group( $user_id, $group_name );
	} else if ( $action === 'DELETE' ) {
		DiscourseUtilities::remove_user_from_discourse_group( $user_id, $group_name );
	}
}

function update_discourse_group_access_client($user_id, $action, $group_id) {
	$options = DiscourseUtilities::get_options();
	$logger = wc_get_logger();
	$external_url = esc_url_raw( $options['url'] . "/groups/". $group_id ."/members" );
	$user_info = get_userdata($user_id);
	// NOTE: Potential bug in client mode: WP_User does not expose `user_id`.
	// This should likely read from `discourse_sso_user_id` user meta instead.
	// Left unchanged for now because this path is only used when `sso-client-enabled`.
	$discourse_user_id = $user_info->user_id;
	$user_email = $user_info->user_email;
	$body = array();

	if ($discourse_user_id) {
		$body['user_id'] = $discourse_user_id;
	} else {
		$body['user_emails'] = $user_email;
	}

	$headers = array(
		'Content-type' => 'application/json',
		'Accept'			 => 'application/json',
		'Api-Key'      => $options['api-key'],
		'Api-Username' => $options['publish-username'],
	);

	$logger->info( sprintf('Sending %s request to %s with headers %s and body %s', $action, $external_url, json_encode($headers), json_encode($body)) );

	$response = wp_remote_request(
		$external_url,
		array(
    	 'method' 	=> $action,
			 'headers' 	=> $headers,
			 'body'    	=> json_encode($body)
    )
	);

	$logger->info( sprintf( 'Response from Discourse: %s %s' , wp_remote_retrieve_response_code($response), wp_remote_retrieve_response_message($response) ) );

	if ( ! DiscourseUtilities::validate( $response ) ) {
		return new \WP_Error( 'discourse_response_error', 'There has been an error in retrieving the user data from Discourse.' );
	}
};

function handle_wc_membership_saved($membership_plan, $args) {
	$logger = wc_get_logger();
	$logger->info( sprintf('Running handle_wc_membership_saved %s, %s, %s', $args['user_id'], $args['user_membership_id'], $args['is_update'] ) );

	$user_id = $args['user_id'];
	$membership = wc_memberships_get_user_membership($args['user_membership_id']);

	if (!$membership) {
		$logger->info('Membership not found - skipping');
		return;
	}

	$plan_id = $membership->get_plan_id();

	if ( ! $plan_id ) {
		$logger->info( sprintf('No current membership for %s, %s, %s', $args['user_id'], $args['user_membership_id'], $args['is_update'] ) );
		return;
	}

	$group_ids = get_discourse_group_ids($plan_id);

	foreach ($group_ids as $group_id) {
		$action = get_user_group_action($user_id, $group_id);

		if (!$action) {
			$logger->info( sprintf('No action for user %s group %s - skipping', $user_id, $group_id) );
			continue;
		}

		update_discourse_group_access($user_id, $action, $group_id);
	}
};

function handle_wc_membership_status_change($user_membership, $old_status, $new_status) {
	$logger = wc_get_logger();
	$logger->info( sprintf('Running handle_wc_membership_status_change %s, %s, %s', json_encode($user_membership), $old_status, $new_status ) );
	return null;
}

add_action('wc_memberships_user_membership_saved', 'handle_wc_membership_saved', 10, 2);

add_action('wc_memberships_user_membership_status_changed', 'handle_wc_membership_status_change', 10, 3);

function full_wc_membership_sync() {
	global $member_group_map;
	global $product_group_map;

	$logger = wc_get_logger();

	if (!function_exists('wc_memberships_get_user_membership')) {
		$logger->info('WooCommerce Memberships not active - skipping sync');
		return;
	}

	$last_complete = (int) get_option('discourse_sync_last_complete', 0);
	if ($last_complete > 0 && (time() - $last_complete) < DAY_IN_SECONDS) {
		return;
	}

	$phase = get_option('discourse_sync_phase', 'memberships');
	$batch_size = 50;

	if ($phase === 'memberships') {
		$offset = (int) get_option('discourse_sync_offset', 0);
		$has_configured_plans = false;
		foreach ($member_group_map as $struct) {
			if (!empty($struct->plan_id)) {
				$has_configured_plans = true;
				break;
			}
		}

		if (!$has_configured_plans) {
			$logger->info('No membership plans configured - switching to orders phase');
			update_option('discourse_sync_offset', 0);
			update_option('discourse_sync_phase', 'orders');
			return;
		}

		$plan_ids = array_map(function($item) { return $item->plan_id; }, $member_group_map);

		if ($offset === 0) {
			$count_query = new WP_Query([
				'post_type'       => 'wc_user_membership',
				'post_status'     => 'any',
				'posts_per_page'  => 1,
				'paged'           => 1,
				'post_parent__in' => $plan_ids,
				'fields'          => 'ids',
			]);
			$logger->info(sprintf('Starting membership phase - total records to process: %s', (int) $count_query->found_posts));
		}

		$membership_ids = get_posts([
			'post_type'       => 'wc_user_membership',
			'post_status'     => 'any',
			'posts_per_page'  => $batch_size,
			'offset'          => $offset,
			'post_parent__in' => $plan_ids,
			'fields'          => 'ids',
		]);

		if (empty($membership_ids)) {
			$logger->info('Membership phase complete - switching to orders phase');
			update_option('discourse_sync_offset', 0);
			update_option('discourse_sync_phase', 'orders');
			return;
		}
		
		$logger->info(sprintf('Running full_wc_membership_sync phase memberships - batch offset %s to %s', $offset, $offset + $batch_size));

		$user_group_pairs = array();
		foreach ($membership_ids as $membership_id) {
			$membership = wc_memberships_get_user_membership($membership_id);
			if (!$membership) {
				continue;
			}

			$user_id = $membership->get_user_id();
			foreach (get_discourse_group_ids($membership->get_plan_id()) as $group_id) {
				$user_group_pairs[$user_id . '_' . $group_id] = array(
					'user_id'  => $user_id,
					'group_id' => $group_id
				);
			}
		}

		foreach ($user_group_pairs as $pair) {
			$action = get_user_group_action($pair['user_id'], $pair['group_id']);
			if ($action) {
				$logger->info(sprintf('Updating user %s: %s group %s (membership sync)', $pair['user_id'], $action, $pair['group_id']));
				update_discourse_group_access($pair['user_id'], $action, $pair['group_id']);
				sleep(1);
			}
		}

		update_option('discourse_sync_offset', $offset + $batch_size);
		$logger->info(sprintf('Membership phase batch complete - next offset: %s', $offset + $batch_size));
		return;
	}

	$has_configured_products = false;
	foreach ($product_group_map as $struct) {
		if (!empty($struct->product_ids)) {
			$has_configured_products = true;
			break;
		}
	}

	if (!$has_configured_products) {
		$logger->info('Full sync cycle complete (no products configured) - throttling for 24 hours');
		update_option('discourse_sync_last_complete', time());
		update_option('discourse_sync_offset', 0);
		update_option('discourse_sync_order_offset', 0);
		update_option('discourse_sync_phase', 'memberships');
		return;
	}

	$offset = (int) get_option('discourse_sync_order_offset', 0);

	if ($offset === 0) {
		$count_query = new WP_Query([
			'post_type'      => 'shop_order',
			'post_status'    => 'wc-completed',
			'posts_per_page' => 1,
			'paged'          => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_customer_user',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
		]);
		$logger->info(sprintf('Starting orders phase - total records to process: %s', (int) $count_query->found_posts));
	}

	$orders = get_posts([
		'post_type'      => 'shop_order',
		'post_status'    => 'wc-completed',
		'posts_per_page' => $batch_size,
		'offset'         => $offset,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'     => '_customer_user',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			],
		],
	]);

	if (empty($orders)) {
		$logger->info('Full sync cycle complete - throttling for 24 hours');
		update_option('discourse_sync_last_complete', time());
		update_option('discourse_sync_offset', 0);
		update_option('discourse_sync_order_offset', 0);
		update_option('discourse_sync_phase', 'memberships');
		return;
	}
	
	$logger->info(sprintf('Running full_wc_membership_sync phase orders - batch offset %s to %s', $offset, $offset + $batch_size));

	$user_group_pairs = array();
	foreach ($orders as $order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			continue;
		}

		$user_id = $order->get_user_id();
		if (!$user_id) {
			$logger->info(sprintf('Skipping guest order %s in order phase', $order_id));
			continue;
		}

		foreach ($order->get_items() as $item) {
			$product_id = $item->get_product_id();

			foreach ($product_group_map as $struct) {
				if (in_array($product_id, $struct->product_ids)) {
					$group_id = $struct->group_id;
					$user_group_pairs[$user_id . '_' . $group_id] = array(
						'user_id'  => $user_id,
						'group_id' => $group_id
					);
				}
			}
		}
	}

	foreach ($user_group_pairs as $pair) {
		$logger->info(sprintf('Updating user %s: PUT group %s (product sync)', $pair['user_id'], $pair['group_id']));
		update_discourse_group_access($pair['user_id'], 'PUT', $pair['group_id']);
		sleep(1);
	}

	update_option('discourse_sync_order_offset', $offset + $batch_size);
	$logger->info(sprintf('Order phase batch complete - next offset: %s', $offset + $batch_size));
}

add_action('run_full_wc_membership_sync', 'full_wc_membership_sync');

// Handle single product purchases

function handle_wc_membership_order_status_change( $order_id, $old_status, $new_status ) {
	global $product_group_map;
	$logger = wc_get_logger();

	if ($new_status == "completed") {
		$order = new WC_Order($order_id);
		$items = $order->get_items();

		$logger->info( sprintf('Order items %s', json_encode($items) ) );

		foreach ( $items as $item_id => $item ) {
			$product_id = $item->get_product_id();

			foreach($product_group_map as $struct) {
		    if (in_array($product_id, $struct->product_ids)) {
					$group_id = $struct->group_id;
					$user_id = $order->get_user_id();
					$action = "PUT";

					$logger->info( sprintf('Running update_discourse_group_access %s, %s, %s', $user_id, $action, $group_id ) );

					update_discourse_group_access($user_id, $action, $group_id);
		    }
			}
		}
  }
}

add_action('woocommerce_order_status_changed', 'handle_wc_membership_order_status_change', 10, 3);

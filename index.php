<?php
/*
  Plugin Name: Event Espresso - Attendee Mover Tool
  Plugin URI: http://eventespresso.com/
  Description: Tool for moving attendees between events. This addon will reset the current price option and amounts owed for the event. Please use caution when moving attendees.

  Version: 1.2.b

  Author: Event Espresso
  Author URI: http://www.eventespresso.com

  Copyright (c) 2013 Event Espresso  All Rights Reserved.

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA

 */

//Update notifications
add_action('action_hook_espresso_attendee_mover_update_api', 'ee_attendee_mover_load_pue_update');

function ee_attendee_mover_load_pue_update() {
	global $org_options, $espresso_check_for_updates;
	if ($espresso_check_for_updates == false)
		return;

	if (file_exists(EVENT_ESPRESSO_PLUGINFULLPATH . 'class/pue/pue-client.php')) { //include the file 
		require(EVENT_ESPRESSO_PLUGINFULLPATH . 'class/pue/pue-client.php' );
		$api_key = $org_options['site_license_key'];
		$host_server_url = 'http://eventespresso.com';
		$plugin_slug = array(
			// remove following line when releasing this version to stable
			'premium' => array('b' => 'espresso-attendee-mover-pr'),
			// uncomment following line when releasing this version to stable
    		// 'premium' => array('p' => 'espresso-attendee-mover'),
   			'prerelease' => array('b' => 'espresso-attendee-mover-pr')
		);
		$options = array(
			'apikey' => $api_key,
			'lang_domain' => 'event_espresso',
			'checkPeriod' => '24',
			'option_key' => 'site_license_key',
			'options_page_slug' => 'event_espresso',
			'plugin_basename' => plugin_basename(__FILE__),
			'use_wp_update' => FALSE, //if TRUE then you want FREE versions of the plugin to be updated from WP
		);
		$check_for_updates = new PluginUpdateEngineChecker($host_server_url, $plugin_slug, $options); //initiate the class and start the plugin update engine!
	}
}

function espresso_attendee_mover_version() {
	return '1.2.b';
}

//Function to create a dropdown of events
function espresso_attendee_mover_events_list($old_event_id) {
	global $wpdb, $org_options;

	//defaults
	$group = '';
	$sql = '';
	$is_regional_manager = FALSE;
	$values = array(
			array('id' => TRUE, 'text' => __('Yes', 'event_espresso')),
			array('id' => FALSE, 'text' => __('No', 'event_espresso'))
	);

	//Check if the venue manager is turned on
	$use_venue_manager = isset($org_options['use_venue_manager']) && $org_options['use_venue_manager'] == 'Y' ? TRUE : FALSE;

	//Roles & Permissions
	//This checks to see if the user is a regional manager and creates a union to join the events that are in the users region based on the venue/locale combination
	if (function_exists('espresso_member_data') && espresso_member_data('role') == 'espresso_group_admin') {

		$is_regional_manager = TRUE;

		$group = get_user_meta(espresso_member_data('id'), "espresso_group", TRUE);
		if ($group != '0' && !empty($group)) {

			$sql = "(SELECT e.id event_id, e.event_name, e.start_date, e.wp_user ";

			//Get the venue information
			if ($use_venue_manager) {
				$sql .= ", v.name AS venue_title ";
			} else {
				$sql .= ", e.venue_title ";
			}

			//Get the locale fields
			if ($use_venue_manager) {
				$sql .= ", lc.name AS locale_name, e.wp_user ";
			}

			$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";

			//Join the venues and locales
			if (!empty($group) && $use_venue_manager) {
				$sql .= " LEFT JOIN " . EVENTS_VENUE_REL_TABLE . " vr ON vr.event_id = e.id ";
				$sql .= " LEFT JOIN " . EVENTS_VENUE_TABLE . " v ON v.id = vr.venue_id ";
				$sql .= " LEFT JOIN " . EVENTS_LOCALE_REL_TABLE . " l ON  l.venue_id = vr.venue_id ";
				$sql .= " LEFT JOIN " . EVENTS_LOCALE_TABLE . " lc ON lc.id = l.locale_id ";
			}

			//Find events in the locale
			$sql .=!empty($group) && $use_venue_manager == true ? " AND l.locale_id IN (" . implode(",", $group) . ") " : '';

			//Event status filter
			$sql .= " WHERE e.event_status != 'D' ";

			$sql .= ") UNION ";
		}
	}

	//This is the standard query to retrieve the events
	$sql .= "(SELECT e.id event_id, e.event_name, e.start_date, e.wp_user ";

	//Get the venue information
	if ($use_venue_manager) {
		//If using the venue manager, we need to get those fields
		$sql .= ", v.name AS venue_title ";
	} else {
		//Otherwise we need to get the address fields from the individual events
		$sql .= ", e.venue_title ";
	}

	$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";

	//Join the venues
	if ($use_venue_manager == true) {
		$sql .= " LEFT JOIN " . EVENTS_VENUE_REL_TABLE . " vr ON vr.event_id = e.id ";
		$sql .= " LEFT JOIN " . EVENTS_VENUE_TABLE . " v ON v.id = vr.venue_id ";
	}

	//Roles & Permissions
	//Join the locales
	if (isset($is_regional_manager) && $is_regional_manager == true && $use_venue_manager == true) {
		$sql .= " LEFT JOIN " . EVENTS_LOCALE_REL_TABLE . " l ON  l.venue_id = vr.venue_id ";
		$sql .= " LEFT JOIN " . EVENTS_LOCALE_TABLE . " lc ON lc.id = l.locale_id ";
	}

	//Event status filter
	$sql .= " WHERE e.event_status != 'D' ";

	//Roles & Permissions
	//If user is an event manager, then show only their events
	if (function_exists('espresso_member_data') && ( espresso_member_data('role') == 'espresso_event_manager' || espresso_member_data('role') == 'espresso_group_admin')) {
		$sql .= " AND e.wp_user = '" . espresso_member_data('id') . "' ";
	}

	$sql .= ") ORDER BY start_date = '0000-00-00' DESC, start_date DESC, event_name ASC ";

	$events = $wpdb->get_results($sql);
	$total_events = $wpdb->num_rows;
	$options = '';
	if ($total_events > 0) {
		foreach ($events as $event) {
			//print_r ($event);
			$event_id = $event->event_id;
			$event_name = stripslashes_deep($event->event_name);
			$venue_title = isset($event->venue_title) ? ' - ' . $event->venue_title : '';
			$start_date = isset($event->start_date) ? $event->start_date : '';
			$selected = $old_event_id == $event_id ? 'selected="selected"' : '';
			$options .= '<option value="' . $event_id . '" ' . $selected . ' >' . $event_name . ' [ ' . event_date_display($start_date) . ' ' . $venue_title . ' ]</option>';
		}
	}

	//Adjust the size of the dropdown
	$size = '';
	if ($total_events > 10) {
		$size = '10';
	}

	if ($total_events > 20) {
		$size = '20';
	}

	if ($total_events > 30) {
		$size = '30';
	}
	?>

	<li>
		<p>
			<label class="espresso" for="move_to_new_event">
	<?php _e('Move to new event?', 'event_espresso'); ?>
				<input name="move_to_new_event" type="checkbox" value="1" />
			</label>
		</p>
		<p>
			<label class="espresso" for="clone_to_new_event">
	<?php _e('Clone to new event?', 'event_espresso'); ?>
				<input name="clone_to_new_event" type="checkbox" value="1" />
			</label>
		</p>
		<p>
			<label class="espresso" for="new_event_id">
	<?php _e('Available events', 'event_espresso'); ?>
			</label>
			<select name="new_event_id" size="<?php echo $size ?>" id="attendee_move_new_event_select" >
	<?php echo $options ?>
			</select>
		</p>
	</li>
	<?php
}

add_action('action_hook_espresso_attendee_mover_events_list', 'espresso_attendee_mover_events_list', 10);

//Function to move an attendee to a different event
function espresso_attendee_mover_move() {
	global $wpdb, $org_options;

	//Defaults
	$notifications['error'] = array();
	$error_msg_text = __('An error occured while attempting to move this attendee to a new event.', 'event_espresso');

	if (isset($_POST['move_to_new_event']) && sanitize_text_field($_POST['move_to_new_event']) == TRUE) {

		if (isset($_POST['new_event_id']) && !empty($_POST['new_event_id'])) {

			$_POST['move_attendee'] = TRUE;

			//Change the price_option_type back to default
			do_action('action_hook_espresso_save_attendee_meta', $_REQUEST['id'], 'price_option_type', 'DEFAULT');

			$event_id = $_REQUEST['new_event_id'];
			$attendee_id = sanitize_text_field($_REQUEST['id']);

			//Pass the event_time id to edit_attendee_record.php
			$_POST['start_time_id'] = event_espresso_get_time($event_id, 'id');

			$cols_and_values = array(
					'event_id' => sanitize_text_field($event_id),
					'event_time' => event_espresso_get_time($event_id, 'start_time'),
					'end_time' => event_espresso_get_time($event_id, 'end_time'),
			);
			$cols_and_values_format = array('%d', '%s', '%s');

			//Update the pricing info
			$sql = "SELECT ep.id, ep.price_type, ed.start_date, ed.end_date FROM " . EVENTS_DETAIL_TABLE . " ed ";
			$sql .= "LEFT JOIN " . EVENTS_PRICES_TABLE . " ep ON ed.id=ep.event_id WHERE ed.id ='" . absint($event_id) . "' ORDER BY ep.id LIMIT 1 ";
			$prices = $wpdb->get_row($sql, ARRAY_A);
			if (!empty($prices['id'])) {
				//DB values
				$price_id = $prices['id'];
				$price_type = !empty($prices['price_type']) ? $prices['price_type'] : '';

				//Calculate prices
				$orig_price = event_espresso_get_orig_price_and_surcharge($price_id, $event_id);
				$final_price = event_espresso_get_final_price($price_id, $event_id, $orig_price);
				//Update the $cols_and_values array
				$cols_and_values['price_option'] = $price_type;
				$cols_and_values['orig_price'] = number_format((float) $orig_price->event_cost, 2, '.', '');
				$cols_and_values['final_price'] = number_format((float) $final_price, 2, '.', '');

				array_push($cols_and_values_format, '%s', '%f', '%f');
			}
			$cols_and_values['start_date'] = $prices['start_date'];
			$cols_and_values['end_date'] = $prices['end_date'];
			array_push($cols_and_values_format, '%s', '%s');

			// run the update
			$where_cols_and_values = array('id' => $attendee_id);
			$where_cols_and_values_format = array('%d');
			$upd_success = $wpdb->update(EVENTS_ATTENDEE_TABLE, $cols_and_values, $where_cols_and_values, $cols_and_values_format, $where_cols_and_values_format);

			// if there was an error
			if ($upd_success === FALSE) {
				$notifications['error'][] = $error_msg_text;
			}
		} else {
			//No event id
			$notifications['error'][] = $error_msg_text;
		}
	}

	// display error messages
	if (!empty($notifications['error'])) {
		$error_msg = implode($notifications['error'], '<br />');
		?>
		<div id="message" class="error">
			<p> <strong><?php echo $error_msg; ?></strong> </p>
		</div>
		<?php
	} else {
		$_POST['event_id'] = $_POST['new_event_id'];
	}
}

add_action('action_hook_espresso_attendee_mover_move', 'espresso_attendee_mover_move', 10);

//Function to clone an attendee into a different event
function espresso_attendee_mover_clone() {
	global $wpdb, $org_options;

	//Defaults
	$notifications['error'] = array();
	$error_msg_text = __('An error occured while attempting to move this attendee to a new event.', 'event_espresso');

	if (isset($_POST['clone_to_new_event']) && sanitize_text_field($_POST['clone_to_new_event']) == TRUE) {

		if (isset($_POST['new_event_id']) && !empty($_POST['new_event_id'])) {

			$_POST['clone_attendee'] = TRUE;

			//Change the price_option_type back to default
			do_action('action_hook_espresso_save_attendee_meta', $_REQUEST['id'], 'price_option_type', 'DEFAULT');

			$event_id = $_REQUEST['new_event_id'];
			$attendee_id = sanitize_text_field($_REQUEST['id']);
			$attendee_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . EVENTS_ATTENDEE_TABLE . " WHERE id='%d'", $attendee_id), ARRAY_A);
			unset($attendee_data['id']);
			$attendee_answers = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . EVENTS_ANSWER_TABLE . " WHERE attendee_id='%d'", $attendee_id), ARRAY_A);
			if (defined("EVENTS_MEMBER_REL_TABLE")) {
				$member_relations = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "events_member_rel WHERE attendee_id='%d'", $attendee_id), ARRAY_A);
			} else {
				$member_relations = array();
			}

			$attendee_data['registration_id'] = uniqid('', true);
			$attendee_data['event_id'] = sanitize_text_field($event_id);
			$attendee_data['event_time'] = event_espresso_get_time($event_id, 'start_time');
			$attendee_data['end_time'] = event_espresso_get_time($event_id, 'end_time');

			//Update the pricing info
			$sql = "SELECT ep.price_type, ep.event_cost, ep.surcharge, ep.surcharge_type,	ep.member_price_type, ep.member_price, ed.start_date, ed.end_date ";
			$sql .= "FROM " . EVENTS_DETAIL_TABLE . " ed LEFT JOIN " . EVENTS_PRICES_TABLE . " ep ON ed.id=ep.event_id ";
			$sql .= "WHERE ed.id ='" . absint($event_id) . "' ORDER BY ep.id LIMIT 1 ";
			$prices = $wpdb->get_row($sql, ARRAY_A);
			if (!empty($prices)) {
				//values
				if (count($member_relations) > 0) {
					$orig_price = $prices['member_price'];
					$price_type = $prices['member_price_type'];
				} else {
					$orig_price = $prices['event_cost'];
					$price_type = $prices['price_type'];
				}
				
				//Calculate prices
				if ($prices['surcharge'] > 0) {
					if ($prices['surcharge_type'] == "flat_rate") {
						$final_price = $orig_price + $prices['surcharge'];
					} else {
						$final_price = $orig_price * (1 + ($prices['surcharge']/100));
					}
				} else {
					$final_price = $orig_price;
				}
				
				//Update the $attendee_data array
				$attendee_data['price_option'] = $price_type;
				$attendee_data['orig_price'] = number_format((float) $orig_price, 2, '.', '');
				$attendee_data['final_price'] = number_format((float) $final_price, 2, '.', '');
			}
			$attendee_data['start_date'] = $prices['start_date'];
			$attendee_data['end_date'] = $prices['end_date'];

			//keys are the column names, values are the formats
			$match_array = array(
				'registration_id' => '%s',
				'is_primary' => '%d',
				'lname' => '%s',
				'fname' => '%s',
				'address' => '%s',
				'address2' => '%s',
				'city' => '%s',
				'state' => '%s',
				'zip' => '%s',
				'country_id' => '%s', 
				'organization_name' => '%s',
				'vat_number' => '%s',
				'email' => '%s',
				'phone' => '%s',
				'date' => '%s',
				'price_option' => '%s',
				'orig_price' => '%f',
				'final_price' => '%f',
				'quantity' => '%d',
				'total_cost' => '%f',
				'amount_pd' => '%f',
				'coupon_code' => '%s',
				'payment' => '%s',
				'payment_status' => '%s',
				'txn_type' => '%s',
				'txn_id' => '%s',
				'payment_date' => '%s',
				'event_id' => '%d',
				'event_time' => '%s',
				'end_time' => '%s',
				'start_date' => '%s',
				'end_date' => '%s',
				'attendee_session' => '%s',
				'transaction_details' => '%s',
				'pre_approve' => '%d',
				'checked_in' => '%d',
				'checked_in_quantity' => '%d',
				'hashSalt' => '%s',
			);

			$cols_and_values_format = array();
			foreach ( array_keys( $attendee_data ) as $column ) {
				if ( isset( $match_array[$column] ) ) {
					$cols_and_values_format[] = $match_array[$column];
				}
			}

			$upd_success = $wpdb->insert(EVENTS_ATTENDEE_TABLE, $attendee_data, $cols_and_values_format);
			$new_attendee_id = $wpdb->insert_id;

			foreach ($attendee_answers as $attendee_answer) {
				unset($attendee_answer['id']);
				$attendee_answer['registration_id'] = $attendee_data['registration_id'];
				$attendee_answer['attendee_id'] = $new_attendee_id;
				$wpdb->insert(EVENTS_ANSWER_TABLE, $attendee_answer, array('%s', '%d', '%d', '%s'));
			}
			
			foreach ($member_relations as $member_relation) {
				unset($member_relation['id']);
				$member_relation['event_id'] = $event_id;
				$member_relation['attendee_id'] = $new_attendee_id;
				$wpdb->insert($wpdb->prefix . "events_member_rel", $member_relation, array('%d', '%d', '%s', '%d'));
			}
			
			//other tables that might also need to be updated because new attendee created has different id than old attendee, so these tables may need to be queried with old attendee id, data updated, and inserted with new attendee id.
			//some of them might not need to, such at the attendee_checkin table, because a new attendee wouldn't be checked in
			//attendee_checkin table
			//attendee_meta table
			//groupon_codes table
			//mailchimp_attendee_rel table
			//seating_chart_event_seat table

			// if there was an error
			if ($upd_success === FALSE) {
				$notifications['error'][] = $error_msg_text;
			} else {
				//Pass the event_time id to edit_attendee_record.php
				$_POST['start_time_id'] = event_espresso_get_time($event_id, 'id');
				$_REQUEST['id'] = $new_attendee_id;
				$_REQUEST['registration_id'] = $attendee_data['registration_id'];
			}
		} else {
			//No event id
			$notifications['error'][] = $error_msg_text;
		}
	}

	// display error messages
	if (!empty($notifications['error'])) {
		$error_msg = implode($notifications['error'], '<br />');
		?>
		<div id="message" class="error">
			<p> <strong><?php echo $error_msg; ?></strong> </p>
		</div>
		<?php
	} else {
		$_POST['event_id'] = $_POST['new_event_id'];
	}
}

add_action('action_hook_espresso_attendee_mover_move', 'espresso_attendee_mover_clone', 11);

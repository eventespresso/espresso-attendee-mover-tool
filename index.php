<?php
/*
  Plugin Name: Event Espresso - Attendee Mover Tool
  Plugin URI: http://eventespresso.com/
  Description: Tool for moving attendees between events.

  Version: 0.0.1

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

function espresso_attendee_mover_version() {
	return '0.0.1';
}

function espresso_attendee_mover_events_list($old_event_id) {
	global $wpdb, $org_options;
	
	$values=array(
		array('id'=>TRUE,'text'=> __('Yes','event_espresso')),
		array('id'=>FALSE,'text'=> __('No','event_espresso'))
	);
	
	//Check if the venue manager is turned on
	$use_venue_manager = isset( $org_options['use_venue_manager'] ) && $org_options['use_venue_manager'] == 'Y' ? TRUE : FALSE;
	
	//This is the standard query to retrieve the events
	$sql .= "SELECT e.id event_id, e.event_name, e.event_identifier, e.reg_limit, e.registration_start, ";
	$sql .= " e.start_date, e.is_active, e.recurrence_id, e.registration_startT, e.wp_user ";

	//Get the venue information
	if ( $use_venue_manager ) {
		//If using the venue manager, we need to get those fields
		$sql .= ", v.name AS venue_title, v.address AS venue_address, v.address2 AS venue_address2, v.city AS venue_city, v.state AS venue_state, v.zip AS venue_zip, v.country AS venue_country ";
	} else {
		//Otherwise we need to get the address fields from the individual events
		$sql .= ", e.venue_title, e.phone, e.address, e.address2, e.city, e.state, e.zip, e.country ";
	}
	
	//get the locale fields
	if ( $is_regional_manager && $use_venue_manager ) {
		$sql .= ", lc.name AS locale_name, e.wp_user ";
	}
	
	$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";

	//Join the venues
	if ($use_venue_manager == true) {
		$sql .= " LEFT JOIN " . EVENTS_VENUE_REL_TABLE . " vr ON vr.event_id = e.id ";
		$sql .= " LEFT JOIN " . EVENTS_VENUE_TABLE . " v ON v.id = vr.venue_id ";
	}
	
	//Event status filter
	$sql .= " WHERE e.event_status != 'D' ";
	
	//If user is an event manager, then show only their events
	if (function_exists('espresso_member_data') && ( espresso_member_data('role') == 'espresso_event_manager' || espresso_member_data('role') == 'espresso_group_admin')) {
		$sql .= " AND e.wp_user = '" . espresso_member_data('id') . "' ";
	}
	
	$sql .= " ORDER BY start_date = '0000-00-00' DESC, start_date DESC, event_name ASC ";
	
	$events = $wpdb->get_results($sql);
	$total_events = $wpdb->num_rows;
	$option = '';
	if ( $total_events > 0 ) {
		foreach ($events as $event) {
			//print_r ($event);
			$event_id = $event->event_id;
			$event_name = stripslashes_deep($event->event_name);
			$venue_title = isset($event->venue_title) ? ' - '.$event->venue_title : '';
			$start_date = isset($event->start_date) ? $event->start_date : '';
			$selected = $old_event_id == $event_id ? 'selected="selected"':'';
			$options .= '<option value="'.$event_id.'" '.$selected.' >'.$event_name.' [ '.event_date_display($start_date).' '.$venue_title.' ]</option>';
		}
	}
	
	//Adjust the size of the dropdown
	if ( $total_events > 10 ) {
		$size = '10';	
	}
	
	if ( $total_events > 20 ) {
		$size = '20';	
	}
	
	if ( $total_events > 30 ) {
		$size = '30';	
	}
	?>

	<li>
		<p><label class="espresso" for="new_event_id">
			<?php _e('Move to new event?', 'event_espresso'); ?> <?php echo select_input('move_to_new_event', $values, FALSE);?>	
			</label>	
			
			<select name="new_event_id" size="<?php echo $size ?>" id="attendee_move_new_event_select" >
				<?php echo $options ?>
			</select>
		</p>	
		
	</li>
<?php
}
add_action('action_hook_espresso_attendee_mover_events_list', 'espresso_attendee_mover_events_list', 10);


function espresso_attendee_mover_move() {
	global $wpdb, $org_options;
	$notifications['error']	 = array();
	if ( isset($_POST['move_to_new_event']) && sanitize_text_field( $_POST['move_to_new_event'] ) == TRUE ){
		// update payment status for ALL attendees for the entire registration
		$set_cols_and_values = array( 
			'event_id'=>sanitize_text_field( $_REQUEST['new_event_id'] ), 
			'event_time'=>event_espresso_get_time($event_id, 'start_time'), 
			'end_time'=>event_espresso_get_time($event_id, 'end_time') 
		);
		$set_format = array( '%d', '%s', '%s' );
		$where_cols_and_values = array( 'id'=> sanitize_text_field( $_REQUEST['id'] ) );
		$where_format = array( '%d' );
		// run the update
		$upd_success = $wpdb->update( EVENTS_ATTENDEE_TABLE, $set_cols_and_values, $where_cols_and_values, $set_format, $where_format );
		// if there was an actual error
		if ( $upd_success === FALSE ) {
			$notifications['error'][] = __('An error occured while attempting to move this attendee to a new event.', 'event_espresso'); 
		}
	}
	// display error messages
	if ( ! empty( $notifications['error'] )) {
		$error_msg = implode( $notifications['error'], '<br />' );
	?>
	<div id="message" class="error">
		<p>
			<strong><?php echo $error_msg; ?></strong>
		</p>
	</div>

	<?php 
	}else{
		$_POST['event_id'] = $_POST['new_event_id'];
	}
}
add_action('action_hook_espresso_attendee_mover_move', 'espresso_attendee_mover_move', 10);
<?php
function sched_conf_get_page_content_edit($page_type,$guid) {
	$vars = array();
	$vars['id'] = 'sched-conf-edit';
	$vars['name'] = 'sched_conf_edit';
	// just in case a feature adds an image upload
	$vars['enctype'] = 'multipart/form-data';
	
	$body_vars = array();

	if ($page_type == 'edit') {
		$title = elgg_echo('sched_conf:edit_conf_title');
		$conf = get_entity((int)$guid);
		if (elgg_instanceof($conf, 'object', 'conference') && $conf->canEdit()) {
			$body_vars['conference'] = $conf;
			$body_vars['form_data'] =  sched_conf_prepare_edit_form_vars($conf);
			$conf_container = get_entity($conf->container_guid);
			if (elgg_instanceof($conf_container, 'group')) {
				elgg_push_breadcrumb(elgg_echo('event_calendar:group_breadcrumb'), 'event_calendar/group/'.$conf->container_guid);
			} else {
				elgg_push_breadcrumb(elgg_echo('event_calendar:show_events_title'),'event_calendar/list');
			}
			elgg_push_breadcrumb(elgg_echo('sched_conf:edit_conf_title'));

			$content = elgg_view_form('sched_conf/edit', $vars,$body_vars);
		} else {
			$content = elgg_echo('sched_conf:error_edit');
		}
	} else {
		$title = elgg_echo('sched_conf:add_conf_title');
		if ($guid) {
			// add to group
			$group = get_entity($guid);
			if (elgg_instanceof($group, 'group')) {
				$body_vars['group_guid'] = $guid;
				elgg_push_breadcrumb(elgg_echo('event_calendar:group_breadcrumb'), 'event_calendar/group/'.$guid);
				elgg_push_breadcrumb(elgg_echo('sched_conf:add_conf_title'));
				$body_vars['form_data'] = event_calendar_prepare_edit_form_vars();	
				$content = elgg_view_form('sched_conf/edit', $vars, $body_vars);
			} else {
				$content = elgg_echo('sched_conf:no_group');
			}
		} else {
			elgg_push_breadcrumb(elgg_echo('event_calendar:show_events_title'),'event_calendar/list');

			elgg_push_breadcrumb(elgg_echo('sched_conf:add_conf_title'));
			$body_vars['form_data'] = sched_conf_prepare_edit_form_vars();
	
			$content = elgg_view_form('sched_conf/edit', $vars, $body_vars);
		} 
	}

	$params = array('title' => $title, 'content' => $content,'filter' => '');

	$body = elgg_view_layout("content", $params);
	
	return elgg_view_page($title,$body);	
}

/**
 * Pull together variables for the edit form
 *
 * @param ElggObject       $event
 * @return array
 */
function sched_conf_prepare_edit_form_vars($conf = NULL) {

	// input names => defaults
	$values = array(
		'title' => NULL,
		'description' => NULL,
		'application' => NULL,
		'application_code' => NULL,
		'immediate' => NULL,
		'start_date' => NULL,
		'start_time_h' => NULL,
		'start_time_m' => NULL,
		'tags' => NULL,
		'access_id' => ACCESS_DEFAULT,
		'group_guid' => NULL,
		'guid' => NULL,
		'start_time' => NULL,
	);

	if ($conf) {
		foreach (array_keys($values) as $field) {
			if (isset($conf->$field)) {
				$values[$field] = $conf->$field;
			}
		}
	}

	if (elgg_is_sticky_form('sched_conf')) {
		$sticky_values = elgg_get_sticky_values('sched_conf');
		foreach ($sticky_values as $key => $value) {
			$values[$key] = $value;
		}
	}
	
	elgg_clear_sticky_form('sched_conf');

	return $values;
}

function sched_conf_get_event_for_conference($conf_guid) {
	$options = array(
		'type' => 'object',
		'subtype' => 'event_calendar',
		'relationship' => 'conference_has_event',
		'relationship_guid' => $conf_guid,
		'limit' => 1
	);
	$events = elgg_get_entities_from_relationship($options);
	if ($events) {
		return $events[0];
	} else {
		return FALSE;
	}
}

function sched_conf_get_conference_for_event($event_guid) {
	$options = array(
		'type' => 'object',
		'subtype' => 'conference',
		'relationship' => 'conference_has_event',
		'relationship_guid' => $event_guid,
		'inverse_relationship' => TRUE,
		'limit' => 1
	);
	$confs = elgg_get_entities_from_relationship($options);
	if ($confs) {
		return $confs[0];
	} else {
		return FALSE;
	}
}

function sched_conf_set_event_from_form($conf_guid=0,$group_guid=0) {
	// TODO - save conf, create associated event and add the current user to the event
	$fields = array(
		'title',
		'description',
		'application',
		'application_code',
		'immediate',
		'start_date',
		'tags',
		'access_id',
		'group_guid',
	);

	$user_guid = elgg_get_logged_in_user_guid();
	
	$required_fields = array('title','application','application_code');
	
	if ($conf_guid) {
		$conf = get_entity($conf_guid);
		if (!elgg_instanceof($conf, 'object', 'conference')) {
			// do nothing because this is a bad conference guid
			return FALSE;
		}
	} else {
		$conf = new ElggObject();
		$conf->subtype = 'conference';
		$conf->owner_guid = $user_guid;
		if ($group_guid) {
			$conf->container_guid = $group_guid;
		} else {
			$conf->container_guid = $user_guid;
		}
	}
	
	$missing_fields = FALSE;
	
	foreach($fields as $fn) {
		$value = trim(get_input($fn,''));
		if (!$value && in_array($fn,$required_fields)) {
			$missing_fields = TRUE;
			break;
		}
		$conf->$fn = $value;
	}
	
	error_log("start date is {$conf->start_date}");
	
	if (!$missing_fields) {
		$sh = get_input('start_time_h','');
		$sm = get_input('start_time_m','');
		if (is_numeric($sh) && is_numeric($sm)) {
			// workaround for pulldown zero value bug
			$sh--;
			$sm--;
			$conf->start_time = $sh*60+$sm;
		} else {
			$conf->start_time = '';
		}
		if (is_numeric($conf->start_time)) {
			// Set start date to the Unix start time, if set.
			// This allows sorting by date *and* time.
			$conf->start_date += $conf->start_time*60;
		}
		if (!$conf->immediate && (!$conf->start_date || !$conf->start_time)) {
			$missing_fields = TRUE;
		}
	}
	
	// TODO: need to handle the immediate case
	// Probably just set start date and start time to now
	if (!$missing_fields && $conf->save()) {
		if ($conf_guid) {
			$event = sched_conf_get_event_for_conference($conf_guid);
			sched_conf_sync_event_for_conference($conf,$event);
		} else {
			$event = sched_conf_sync_event_for_conference($conf);
			add_entity_relationship($conf->guid,'conference_has_event',$event->guid);
			if (elgg_plugin_exists('event_calendar')) {
				elgg_load_library('elgg:event_calendar');
				event_calendar_add_personal_event($event->guid, $user_guid);
			}
		}	
		return $conf;
	}
	
	return FALSE;
}

function sched_conf_sync_event_for_conference($conf,$event=NULL) {
	if (!$event) {
		$event = new ElggObject();
		$event->subtype = 'event_calendar';
		$event->owner_guid = $conf->owner_guid;
		$event->container_guid = $conf->container_guid;
	}
	
	$event->access_id = $conf->access_id;
	$event->title = $conf->title;
	$event->description = $conf->description;
	$event->venue = elgg_echo('sched_conf:venue',array($conf->application));
	$event->start_date = $conf->start_date;
	$event->end_date = '';
	$event->start_time = $conf->start_time;
	$event->tags = $conf->tags;
	if (elgg_plugin_exists('event_calendar')) {
		elgg_load_library('elgg:event_calendar');
		$event->real_end_time = event_calendar_get_end_time($event);
	}	
	$event->save();
	return $event;
}
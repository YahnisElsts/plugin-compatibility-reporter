<?php
if( defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN') ) {
	delete_site_option('avp_settings');
	delete_site_option('avp_plugin_list');
	delete_site_transient('avp_cron_start_timestamp');

	if ( $next_timestamp = wp_next_scheduled('avp_check_and_vote') ) {
		wp_unschedule_event($next_timestamp, 'avp_check_and_vote');
	}
}
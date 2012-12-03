<?php
/*
Plugin Name: Plugin Compatibility Reporter
Plugin URI: http://w-shadow.com/?p=3216
Description: Enables you to report if the plugins that you're using are working properly. It will also automatically report active plugins as compatible after they've been active for a while.
Version: 1.0
Author: Janis Elsts
Author URI: http://w-shadow.com/
*/

define('AVP_PLUGIN_FILE', __FILE__);

require dirname(__FILE__) . '/Avp/WordPressPluginDirectory.php';
require dirname(__FILE__) . '/Avp/PluginModel.php';
require dirname(__FILE__) . '/Avp/Settings.php';
require dirname(__FILE__) . '/Avp/SettingsUi.php';
require dirname(__FILE__) . '/Avp/PluginStorage.php';

$plugin_compatibility_reporter = new Avp_Core();

class Avp_Core {
	const DAY_IN_SECONDS = 86400;

	/**
	 * @var Avp_Settings
	 */
	private $settings;
	private $adminUi;

	private $get = array();

	/**
	 * @var Avp_PluginStorage
	 */
	private $plugins;

	public function __construct() {
		$this->get = $_GET;
		if ( get_magic_quotes_gpc() ) {
			$this->get = stripslashes_deep($this->get);
		}

		$this->settings = new Avp_Settings();
		$this->plugins = new Avp_PluginStorage();
		$this->adminUi = new Avp_SettingsUi($this->settings, $this->plugins);

		add_action('load-plugins.php', array($this->plugins, 'update'));

		add_filter('plugin_row_meta', array($this, 'addVotingLinks'), 10, 4);
		add_action('load-plugins.php', array($this, 'handleManualVotes'), 11);
		add_action('admin_notices', array($this, 'outputVotingResult'));
		add_action('admin_print_scripts-plugins.php', array($this, 'outputPluginPageCss'));

		add_action('activated_plugin', array($this, 'updatePluginState'), 10, 1);
		add_action('deactivated_plugin', array($this, 'updatePluginState'), 10, 1);

		if ( wp_next_scheduled('avp_check_and_vote') === false ) {
			wp_schedule_event(strtotime('+5 seconds'), 'daily', 'avp_check_and_vote');
		}
		add_action('avp_check_and_vote', array($this, 'cronEvent'));

		register_deactivation_hook(AVP_PLUGIN_FILE, 'deactivate');
		add_action('admin_notices', array($this, 'outputSettingsNag'));
	}

	public function cronEvent() {
		set_time_limit(600);

		$alreadyRunning = get_site_transient('avp_cron_start_timestamp');
		if ( !empty($alreadyRunning) ) {
			return;
		}
		set_site_transient('avp_cron_start_timestamp', time(), 10*60);

		$this->plugins->update();
		if ( empty($this->settings['username']) || empty($this->settings['password']) ) {
			//Can't do anything more without a WordPress.org account.
			return;
		}

		$directory = new Avp_WordPressPluginDirectory($this->settings['username'], $this->settings['password']);
		foreach($this->plugins as $plugin) { /** @var Avp_PluginModel $plugin */
			//We need to know if the plugin is present in the WordPress.org directory,
			//and if/how the user has voted on its compatibility.
			$isStatusUnknown = ($plugin->isInDirectory() === null);
			$shouldRefreshVotes = $plugin->isInDirectory() && ($plugin->timeSinceLastCheck() > $this->settings['check_period']);
			if ( $isStatusUnknown || $shouldRefreshVotes ) {
				$plugin->refreshDirectoryData($directory);
			}

			//If the plugin has been active for long enough and the user hasn't marked it as working
			//or broken yet, vote it as working automatically.
			$shouldVote = $plugin->isActive()
				&& ( $plugin->timeActive() > $this->settings['trial_period'] )
				&&   $plugin->isInDirectory()
				&& ! $plugin->hasVoteForCurrentVersion();

			if ( $shouldVote ) {
				$plugin->vote($directory, true, false);
			}
		}

		$this->plugins->save();
		delete_site_transient('avp_cron_start_timestamp');
	}

	/**
	 * Add a voting links to the plugin row in the "Plugins" page. By default,
	 * the new links will appear after the "Visit plugin site" link.
	 *
	 * @param array $pluginMeta Array of meta links.
	 * @param string $pluginFile
	 * @param array|null $pluginData Plugin headers.
	 * @param string|null $status The currently selected tab ("all", "active" and so on).
	 * @return array
	 */
	public function addVotingLinks($pluginMeta, $pluginFile, $pluginData = null, $status = null) {
		$plugin = $this->plugins->get($pluginFile);

		if ( $plugin !== null && $plugin->isInDirectory() ) {
			$compatible = $plugin->getVote(self::getWpVersion(), $pluginData['Version']);

			$votingUrl = add_query_arg(
				array(
					'avp-plugin-file' => $pluginFile,
				    'avp-plugin-version' => $pluginData['Version'],
				),
				admin_url('plugins.php')
			);
			$votingUrl = wp_nonce_url($votingUrl, 'avp-vote-' . $pluginFile);

			$pluginMeta[] = sprintf(
				'Compatibility: <a href="%s" title="Report as compatible with WordPress %s"%s>Works</a>',
				esc_attr(add_query_arg('avp-vote', 'works', $votingUrl)),
				esc_attr(self::getWpVersion()),
				($compatible === true) ? ' class="avp-current-vote"' : ''
			);

			$pluginMeta[] = sprintf(
				'<a href="%s" title="Report as broken on WordPress %s"%s>Broken</a>',
				esc_attr(add_query_arg('avp-vote', 'broken', $votingUrl)),
				esc_attr(self::getWpVersion()),
				($compatible === false) ? ' class="avp-current-vote"' : ''
			);
		}

		if ( $plugin !== null && $plugin->isActive() ) {
			$timeActive = $plugin->timeActive();
			$pluginMeta[] = sprintf(
				'<span class="avp-time-active description">Active for %s</span>',
				$timeActive > 0 ? human_time_diff(0.01, $timeActive) : '1 sec'
			);
		}

		return $pluginMeta;
	}

	public function outputPluginPageCss() {
		?>
		<style type="text/css">
			.plugins a.avp-current-vote {
				font-weight: bold;
				color: black;
			}
		</style>
		<?php
	}

	/**
	 * Submit a manual compatibility vote.
	 */
	public function handleManualVotes() {
		if ( !isset($this->get['avp-vote'], $this->get['avp-plugin-file'], $this->get['avp-plugin-version']) || !$this->userCanVote()) {
			return;
		}

		$pluginFile = strval($this->get['avp-plugin-file']);
		check_admin_referer('avp-vote-' . $pluginFile);

		$plugin = $this->plugins->get($pluginFile);
		if ( $plugin === null ) {
			wp_die("Unknown plugin.", '', array('back_link' => true));
		}

		$directory = $this->directoryLogin();
		if ( is_wp_error($directory) ) {
			wp_die($directory->get_error_message(), '', array('back_link' => true));
		}

		$slug = $plugin->getSlug();
		$wpVersion = self::getWpVersion();
		$pluginVersion = strval($this->get['avp-plugin-version']);
		$compatible = $this->get['avp-vote'] === 'works';

		$result = $directory->vote($slug, $pluginVersion, $wpVersion, $compatible, true);
		if ( is_wp_error($result) ) {
			wp_die($result->get_error_message(), '', array('back_link' => true));
		} else {
			//Make a note of the vote.
			$plugin->setVote($wpVersion, $pluginVersion, $compatible);
			$this->plugins->save();

			$redirect = add_query_arg(
				array(
				     'avp-plugin-file' => $pluginFile,
				     'avp-vote-result' => $compatible ? 1 : 0,
				),
				admin_url('plugins.php')
			);
			wp_redirect($redirect);
			die();
		}
	}

	public function outputVotingResult() {
		if ( !isset($this->get['avp-vote-result']) ) {
			return;
		}
		printf(
			'<div class="updated"><p>Plugin reported as <strong>%s</strong>.</p></div>',
			intval($this->get['avp-vote-result']) ? 'working' : 'broken'
		);
	}

	/**
	 * Check if the current user has the right and ability to vote on plugin compatibility.
	 *
	 * @return bool
	 */
	private function userCanVote() {
		return current_user_can('update_plugins') && !empty($this->settings['username']) && !empty($this->settings['password']);
	}

	/**
	 * Log into the WordPress.org plugin directory.
	 *
	 * @return WP_Error|Avp_WordPressPluginDirectory
	 */
	private function directoryLogin() {
		if ( empty($this->settings['username']) || empty($this->settings['password']) ) {
			return new WP_Error("You must set enter your WordPress.org account credentials before you can do this.");
		}

		$directory = new Avp_WordPressPluginDirectory();
		$loggedIn = $directory->login($this->settings['username'], $this->settings['password']);

		$this->settings['login_error'] = is_wp_error($loggedIn) ? $loggedIn : null;
		$this->settings->save();

		if ( is_wp_error($loggedIn) ) {
			return $loggedIn;
		} else {
			return $directory;
		}
	}

	/**
	 * The deactivation hook.
	 */
	public function deactivate() {
		//Remove our cron event.
		if ( $next_timestamp = wp_next_scheduled('avp_check_and_vote') ) {
			wp_unschedule_event($next_timestamp, 'avp_check_and_vote');
		}
	}

	/**
	 * If the user has not configured the plugin yet, output an admin notice
	 * telling them to to go the settings page.
	 */
	public function outputSettingsNag() {
		if ( !$this->adminUi->isSettingsPage() && ( empty($this->settings['username']) || empty($this->settings['password']) ) ) {
			printf(
				'<div class="updated">
					<p>
						Please go to <a href="%s">Plugins &rarr; Compatibility Reporter</a>
						and enter your WordPress.org account details to enable
						Plugin Compatibility Reporter.
					</p>
				</div>',
				esc_attr($this->adminUi->getSettingsPageUrl())
			);
		}
	}

	/**
	 * Callback for the "activated_plugin" and "deactivated_plugin" hooks. Records the new
	 * state of the plugin in question and resets the activation timestamp.
	 *
	 * @param string $pluginFile
	 */
	public function updatePluginState($pluginFile) {
		$plugin = $this->plugins->get($pluginFile);
		if ( $plugin !== null ) {
			if ( current_filter() == 'activated_plugin' ) {
				$plugin->setActive(true);
			} else if ( current_filter() == 'deactivated_plugin' ) {
				$plugin->setActive(false);
			}
		}
	}

	public static function getWpVersion() {
		return $GLOBALS['wp_version'];
	}
}
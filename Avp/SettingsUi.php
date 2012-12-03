<?php
class Avp_SettingsUi {
	/** @var Avp_Settings */
	private $settings;

	/** @var Avp_PluginStorage */
	private $plugins;

	private $pageSlug = 'avp-settings';
	private $group = 'avp_settings_group';

	private $pageHook;

	public function __construct($settings, $plugins) {
		$this->settings = $settings;
		$this->plugins = $plugins;

		add_action('admin_menu', array($this, 'addSettingsPage'));
		add_action('admin_init', array($this, 'initSettingsApi'));

		add_filter('plugin_action_links_' . plugin_basename(AVP_PLUGIN_FILE), array($this, 'addSettingsLink'));
	}

	public function addSettingsPage() {
		$this->pageHook = add_plugins_page(
			'Plugin Compatibility Reporter',
			'Compatibility Reporter',
			'update_plugins',
			$this->pageSlug,
			array($this, 'displaySettingsPage')
		);
	}

	public function initSettingsApi() {
		register_setting($this->group, $this->settings->getOptionName(), array($this, 'validateSettings'));

		$sectionId = 'wordpress.org_account';
		add_settings_section(
			$sectionId,
			'Your WordPress.org Account',
			'__return_null',
			$this->pageSlug
		);
		add_settings_field(
			'username',
			'Username',
			array($this, 'outputUsernameField'),
			$this->pageSlug,
			$sectionId
		);
		add_settings_field(
			'password',
			'Password',
			array($this, 'outputPasswordField'),
			$this->pageSlug,
			$sectionId
		);

		$sectionId = 'voting';
		add_settings_section(
			$sectionId,
			'Automatic Reporting',
			'__return_null',
			$this->pageSlug
		);
		add_settings_field(
			'trial_period',
			'Mark as compatible',
			array($this, 'outputTrialPeriod'),
			$this->pageSlug,
			'voting'
		);
	}

	public function displaySettingsPage() {
		$cronStarted = get_site_transient('avp_cron_start_timestamp');
		if ( !empty($cronStarted) ) {
			add_settings_error(
				'cron_event',
				'cron_event',
				sprintf(
					'Analysing plugins and downloading WordPress.org data (elapsed time: %s)...',
					human_time_diff($cronStarted, time())
				),
				'updated'
			);
		}

		//Pretty standard settings page code.
		?>
	    <div class="wrap">
	        <div class="icon32" id="icon-options-general"><br></div>
	        <h2>Plugin Compatibility Reporter</h2>
	        <?php settings_errors(); ?>

	        <form action="<?php echo esc_attr(admin_url('options.php')); ?>" method="post">
				<?php
		        settings_fields($this->group);
		        do_settings_sections($this->pageSlug);
		        submit_button();
		        ?>
			</form>
		</div>
		<?php
	}

	public function outputUsernameField() {
		printf(
			'<input name="%s[username]" id="username" value="%s" type="text">',
			esc_attr($this->settings->getOptionName()),
			!empty($this->settings['username']) ? esc_attr($this->settings['username']) : ''
		);
	}

	public function outputPasswordField() {
		printf(
			'<input name="%s[password]" id="password" value="%s" type="password">',
			esc_attr($this->settings->getOptionName()),
			!empty($this->settings['password']) ? esc_attr($this->settings['password']) : ''
		);
	}

	public function outputTrialPeriod() {
		printf(
			'<select name="%s[trial_period]" id="trial_period">',
			esc_attr($this->settings->getOptionName())
		);

		$day = Avp_Settings::DAY_IN_SECONDS;
		$options = array(
			'1 day' => 1 * $day,
			'2 days' => 2 * $day,
			'3 days' => 3 * $day,
			'4 days' => 4 * $day,
			'5 days' => 5 * $day,
			'6 days' => 6 * $day,
			'1 week' => 7 * $day,
			'2 weeks' => 14 * $day,
			'3 weeks' => 21 * $day,
			'1 month' => 30 * $day,
		);
		foreach($options as $name => $value) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($value),
				selected($value, $this->settings['trial_period'], false),
				$name
			);
		}
		echo '</select> after activation';
		?><br>
			<span class="description">
				Automatically report new plugins as compatible after they've been active for a while.
				This only applies to plugin versions that you haven't already voted for, and will not
				overwrite existing reports.
			</span>
		<?php
	}


	public function validateSettings($input) {
		$expected = array(
			'username' => '',
			'password' => '',
			'trial_period' => '',
		);
		//Pre-fill missing fields with empty values and throw away any unexpected inputs.
		$input = array_merge($expected, array_intersect_key($input, $expected));

		$isLoginOk = false;
		$input['username'] = trim(strval($input['username']));
		$input['password'] = trim(strval($input['password']));

		//Must specify either both username and password, or none.
		if ( $input['username'] === '' || $input['password'] === '' ) {
			if ( $input['username'] === '' ) {
				add_settings_error('username', 'empty-username', 'Username must not be empty.');
			}
			if ( $input['password'] === '' ) {
				add_settings_error('password', 'empty-password', 'Password must not be empty.');
			}
			$input['username'] = '';
			$input['password'] = '';
		} else {
			//(Re)validate the account credentials.
			$directory = new Avp_WordPressPluginDirectory();
			$loginResult = $directory->login($input['username'], $input['password']);
			if ( is_wp_error($loginResult) ) {
				add_settings_error('username', $loginResult->get_error_code(), $loginResult->get_error_message());
			} else {
				$isLoginOk = true;
			}
		}

		//The trial period must be 1 to 30 days long (in seconds) and
		//it must contain a whole number of days.
		$day = Avp_Settings::DAY_IN_SECONDS;
		$input['trial_period'] = intval($input['trial_period'] / $day) * $day;
		$input['trial_period'] = min(max(intval($input['trial_period']), $day), 30 * $day);

		//If settings have been changed and we have a valid WordPress.org account,
		//trigger an immediate check.
		$accountChanged = ($input['username'] != $this->settings['username']);
		$shouldTriggerCheck = $isLoginOk &&
			(
				   $accountChanged
				|| $input['password'] != $this->settings['password']
				|| $input['trial_period'] != $this->settings['trial_period']
			);

		//If the user switches to a different WordPress.org account, we should
		//throw away any cached votes since they don't belong to the new account.
		if ( $isLoginOk && $accountChanged ) {
			$this->plugins->clearVotes();
		}

		if ( $shouldTriggerCheck ) {
			wp_schedule_single_event(strtotime('+10 seconds'), 'avp_check_and_vote');
			add_settings_error(
				'username',
				'avp-check-scheduled',
				"Settings saved. This plugin will now download your existing compatibility votes from WordPress.org (this can take a few minutes).",
				'updated'
			);
		}

		return array_merge($this->settings->toArray(), $input);
	}

	/**
	 * Add "Settings" to the plugin's action links on the "Plugins" page.
	 *
	 * @param array $actionLinks List of existing action links.
	 * @return array Modified list of action links.
	 */
	public function addSettingsLink($actionLinks) {
		$actionLinks['settings'] = sprintf(
			'<a href="%s">Settings</a>',
			esc_attr($this->getSettingsPageUrl())
		);
		return $actionLinks;
	}

	public function getSettingsPageUrl() {
		return add_query_arg('page', $this->pageSlug, admin_url('plugins.php'));
	}

	/**
	 * Check if the user is currently on the settings page.
	 *
	 * @return bool
	 */
	public function isSettingsPage() {
		if ( !is_admin() || !function_exists('get_current_screen') ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen->id == $this->pageHook;
	}
}

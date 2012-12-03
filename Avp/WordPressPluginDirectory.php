<?php
class Avp_WordPressPluginDirectory {
	private $username;
	private $password;

	private $cookies = array();
	private $isLoggedIn = false;

	public function __construct($username = null, $password = null) {
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Log into WordPress.org.
	 *
	 * @param string $username
	 * @param string $password
	 * @return bool|WP_Error
	 */
	public function login($username, $password) {
		$loginFormAction = 'http://wordpress.org/support/bb-login.php';
		$formFields = array(
			'user_login' => $username,
			'password' => $password,
			'Submit' => 'Log In',
			're' => '',
		);
		$args = array(
			'body' => $formFields,
		    'headers' => array(
				'Referer' => 'http://wordpress.org/extend/plugins/',
		    ),
			'redirection' => 0, //Must be zero or WP will do a POST to the redirect location.
		);

		$result = wp_remote_post($loginFormAction, $args);
		if ( is_wp_error($result) ) {
			return $result;
		}

		//If the login is successful, wordpress.org sets some cookies and redirects to the referrer URL.
		$success = (wp_remote_retrieve_header($result, 'location') != '') && !empty($result['cookies']);
		if ( $success ) {
			$this->cookies = $result['cookies'];
			$this->isLoggedIn = true;
			return true;
		} else {
			return new WP_Error('login_failed', 'Invalid username or password');
		}
	}

	/**
	 * Ensure the user is logged in. Will attempt to log in if not already logged in.
	 *
	 * @return bool|WP_Error
	 */
	private function autoLogin() {
		if ( !$this->isLoggedIn ) {
			if ( isset($this->username, $this->password) ) {
				$loginResult = $this->login($this->username, $this->password);
				if ( is_wp_error($loginResult) ) {
					return $loginResult;
				}
			} else {
				return new WP_Error('not_logged_in', 'You must log in before you can do this.');
			}
		}
		return $this->isLoggedIn;
	}

	public function getUserVotes($pluginSlug) {
		$loggedIn = $this->autoLogin();
		if ( is_wp_error($loggedIn) ) {
			return $loggedIn;
		}

		$dom = $this->fetchDirectoryPage($pluginSlug);
		if ( is_wp_error($dom) ) {
			return $dom;
		}

		$userVotes = $this->extractUserVotes($dom);
		return $userVotes;
	}

	/**
	 * Extract the user's compatibility votes from a WordPress.org plugin page.
	 *
	 * @param DOMDocument $dom
	 * @return array|WP_Error
	 */
	private function extractUserVotes($dom) {
		//Find WP version rows in the compatibility table.
		$xpath = new DOMXpath($dom);

		$table = $xpath->query('//table[@id = "compatibility-table"]');
		if ( $table->length < 1 ) {
			return new WP_Error('unknown_page_format', "Failed to parse the WordPress.org directory page - no compatibility table.");
		}
		$wpVersionRows = $xpath->query('./tr', $table->item(0));

		//Extract votes.
		$userVotes = array();
		foreach($wpVersionRows as $row){ /** @var DOMElement $row */
			//WordPress version is encoded in the row ID, e.g. "compatibility-version-2_9_2".
			$id = $row->getAttribute('id');
			if ( empty($id) ){
				continue;
			}
			$wpVersion = str_replace('compatibility-version-', '', $id);
			$wpVersion = trim(str_replace('_', '.', $wpVersion));

			$userVotes[$wpVersion] = array();

			//Extract votes for each WP version/plugin version combination.
			foreach($row->getElementsByTagName('td') as $cell){ /** @var DOMElement $cell */
				//Plugin version is encoded in the "class" attribute, e.g. "compatibility-topic-version-1_0".
				$matched = preg_match(
					'@compatibility-topic-version-(?P<version_number>[^\s]+?)(?:\s|$)@i',
					$cell->getAttribute('class'),
					$matches
				);

				if ( !$matched ) {
					continue;
				}
				$pluginVersion = trim( str_replace('_', '.', $matches['version_number']));

				//The current user's vote may also be present in the class names. If the user
				//has marked the plugin as working, the "class" attr. will include the "user-works"
				//value. If they marked it as broken, it will be "user-broken" instead.
				$matched = preg_match(
					'@(?:\s|^)user-(?P<user_vote>works|broken)(?:\s|$)@',
					$cell->getAttribute('class'),
					$matches
				);
				if ( $matched ){
					$userVotes[$wpVersion][$pluginVersion] = ($matches['user_vote'] == 'works');
				}
			}
		}

		return $userVotes;
	}

	/**
	 * Vote about a plugin's compatibility with a specific WP version.
	 *
	 * @param string $pluginSlug
	 * @param string $pluginVersion
	 * @param string $wordpressVersion
	 * @param bool $compatible
	 * @param bool $allowOverwrite Whether to overwrite the existing vote, if any.
	 * @return bool|WP_Error
	 */
	public function vote($pluginSlug, $pluginVersion, $wordpressVersion, $compatible, $allowOverwrite = true) {
		$loggedIn = $this->autoLogin();
		if ( is_wp_error($loggedIn) ) {
			return $loggedIn;
		}

		//Load the plugin's directory page
		$dom = $this->fetchDirectoryPage($pluginSlug);
		if ( is_wp_error($dom) ){
			return $dom;
		}

		//Extract the form fields that we'll need to send the vote.
		$fields = array();

		$xpath = new DOMXpath($dom);
		$inputs = $xpath->query( '//form[@class = "compatibility"]//input' );
		if ( $inputs->length > 0 ) {
			foreach($inputs as $input) { /** @var DOMElement $input */
				if ( $input->getAttribute('name') != '' ) {
					$fields[$input->getAttribute('name')] = $input->getAttribute('value');
				}
			}
		} else {
			return new WP_Error(
				'unknown_page_format',
				"Can't parse the directory page of this plugin."
			);
		}

		//TODO: Check if the specified WordPress version is known.

		//Optionally check if the user has already voted.
		if ( !$allowOverwrite ) {
			$existingVotes = $this->extractUserVotes($dom);
			if ( is_wp_error($existingVotes) ) {
				return $existingVotes;
			}
			if ( isset($existingVotes[$wordpressVersion][$pluginVersion]) ) {
				return new WP_Error('already_voted', "The user has already voted on this WordPress/plugin version combo.", $existingVotes);
			}
		}

		//Prepare the voting data.
		$fields['compatibility[version]'] = $wordpressVersion;
		$fields['compatibility[topic_version]'] = $pluginVersion;

		//"Works" = 1, "Broken" = 0
		$fields['compatibility[compatible]'] = $compatible ? 1 : 0;

		//Submit the vote.
		$result = wp_remote_post(
			$this->getPluginDirectoryUrl($pluginSlug),
			array(
				'body' => $fields,
				'cookies' => $this->cookies,
				'redirection' => 0,
				'headers' => array(
					'Referer' => 'http://wordpress.org/extend/plugins/',
				),
			)
		);

		if ( is_wp_error($result) ) {
			return $result;
		}

		//On success WP redirects to the plugin page with a bunch of query args (not relevant here).
		if ( wp_remote_retrieve_response_code($result) == 302 ) {
			return true;
		} else {
			return new WP_Error(
				'voting_failed',
				"Your vote was not registered. Perhaps the voting process has changed."
			);
		}
	}

	/**
     * Load the plugin's description page from the WordPress.org plugin directory.
     *
     * @param string $pluginSlug
     * @return DOMDocument|WP_Error Either the page contents, or an instance of WP_Error if something went wrong.
     */
	private function fetchDirectoryPage($pluginSlug){
		//Load the plugin's directory page
		$pageUrl = $this->getPluginDirectoryUrl($pluginSlug);
		$result = wp_remote_get($pageUrl, array('cookies' => $this->cookies));

		if ( is_wp_error($result) ) {
			return $result;
		} else if ( wp_remote_retrieve_response_code($result) == 404 ) {
			return new WP_Error('not_in_directory', "This plugin is not listed in the WordPress.org directory.");
		} else if ( wp_remote_retrieve_response_code($result) != 200 ) {
			return new WP_Error('http_request_failed', "Couldn't load the plugin's directory page.");
		}

		$dom = new DOMDocument();
		@$dom->loadHTML(wp_remote_retrieve_body($result));

		return $dom;
	}

	 /**
	  * Try to guess the plugin's wordpress.org URL from a its slug or filename.
	  *
	  * @param string $plugin Plugin file, e.g. "cool-widget/cool-widget.php", or a slug.
	  * @return string
	  */
	private function getPluginDirectoryUrl($plugin){
		$pluginSlug = Avp_PluginModel::getSlugFromFilename($plugin);
		$pluginPage = 'http://wordpress.org/extend/plugins/' . urlencode($pluginSlug) . '/';
		return $pluginPage;
	}
}
<?php
class Avp_PluginModel {
	private $slug;
	private $version;
	private $wpVersion;

	private $lastCheckedOn = 0;

	/**
	 * @var int|null Timestamp for when the plugin was activated, or null if inactive.
	 */
	private $activatedOn = null;

	private $inDirectory = null;
	private $votes = null;
	private $lastError = null;

	public function __construct($pluginFile, $pluginData, $active = false) {
		$this->slug = self::getSlugFromFilename($pluginFile);
		$this->version = $pluginData['Version'];
		$this->wpVersion = Avp_Core::getWpVersion();
		$this->setActive($active);
	}

	public function setActive($active) {
		//If the plugin was activated or deactivated since the last check, set/unset the activation timestamp.
		$wasActive = isset($this->activatedOn);
		if ( $active !== $wasActive ) {
			$this->activatedOn = $active ? time() : null;
		}
	}

	public function isActive() {
		return isset($this->activatedOn);
	}

	/**
	 * Get the duration that the plugin has been active.
	 *
	 * @return int|null Time active (in seconds) or null if the plugin isn't active.
	 */
	public function timeActive() {
		if ( !$this->isActive() ) {
			return null;
		}
		return time() - $this->activatedOn;
	}

	public function getActivatedOn() {
		return $this->activatedOn;
	}

	/**
	 * Update the stored plugin data to match the currently installed plugin and WP version.
	 *
	 * @param array $pluginData Plugin headers.
	 */
	public function updateVersion($pluginData) {
		//If stored version numbers don't match current ones, reset state.
		$currentVersion = $pluginData['Version'];
		$wpVersion = Avp_Core::getWpVersion();

		if ( ($this->version != $currentVersion) || ($this->wpVersion != $wpVersion) ) {
			//echo "Stored version of {$this->slug} doesn't match current version, reset state.<br>\n";
			$this->activatedOn = $this->isActive() ? time() : null;
			$this->lastCheckedOn = 0;

			$this->version = $currentVersion;
			$this->wpVersion = $wpVersion;
			$this->lastError = null;
		}
	}

	public function isInDirectory() {
		return $this->inDirectory;
	}

	/**
	 * Load compatibility data from the plugin directory.
	 *
	 * @param Avp_WordPressPluginDirectory $directory
	 * @return null|WP_Error
	 */
	public function refreshDirectoryData($directory) {
		$this->lastCheckedOn = time();
		$this->lastError = null;

		$votes = $directory->getUserVotes($this->slug);
		if ( is_wp_error($votes) ) {
			if ( $votes->get_error_code() == 'not_in_directory' ) {
				$this->inDirectory = false;
			} else {
				//Unexpected error.
				$this->lastError = $votes;
				return $votes;
			}
		} else {
			$this->votes = $votes;
			$this->inDirectory = true;
		}
		return null;
	}

	public function getVote($wordpressVersion, $pluginVersion) {
		if ( isset($this->votes, $this->votes[$wordpressVersion][$pluginVersion]) ) {
			return $this->votes[$wordpressVersion][$pluginVersion];
		}
		return null;
	}

	public function hasVoteForCurrentVersion() {
		return $this->hasVote($this->wpVersion, $this->version);
	}

	public function hasVote($wordpressVersion, $pluginVersion) {
		return $this->getVote($wordpressVersion, $pluginVersion) !== null;
	}

	public function setVote($wordpressVersion, $pluginVersion, $compatible) {
		if ( !isset($this->votes) ) {
			$this->votes = array();
		}
		if ( !isset($this->votes[$wordpressVersion]) ) {
			$this->votes[$wordpressVersion] = array();
		}
		$this->votes[$wordpressVersion][$pluginVersion] = $compatible;
	}

	public function setVotes($votes) {
		$this->votes = $votes;
	}

	public function timeSinceLastCheck() {
		return time() - $this->lastCheckedOn;
	}

	/**
	 * Vote the current version of this plugin as working/broken with the current version of WordPress.
	 *
	 * @param Avp_WordPressPluginDirectory $directory
	 * @param bool $compatible True = works, false = broken.
	 * @param bool $allowOverwrite Whether to overwrite existing votes, if any.
	 * @return bool|WP_Error
	 */
	public function vote($directory, $compatible, $allowOverwrite = true) {
		$result = $directory->vote($this->slug, $this->version, $this->wpVersion, $compatible, $allowOverwrite);
		if ( is_wp_error($result) ) {
			if ( $result->get_error_code() === 'already_voted' ) {
				$this->setVotes($result->get_error_data('already_voted'));
			} else {
				//Record the error somehow.
				$this->lastError = $result;
				return $this->lastError;
			}
		} else {
			//Record the new vote.
			$this->setVote($this->wpVersion, $this->version, $compatible);
		}
		return true;
	}

	public function getSlug() {
		return $this->slug;
	}

	public static function getSlugFromFilename($pluginFile) {
		$pluginSlug = dirname(plugin_basename($pluginFile)); //May fail if the plugin dir. is renamed.
		if ( empty($pluginSlug) || ($pluginSlug == '.') ){
			$pluginSlug = basename($pluginFile, '.php');
		}
		return $pluginSlug;
	}
}

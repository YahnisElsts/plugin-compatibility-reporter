<?php
class Avp_Settings implements ArrayAccess {
	const DAY_IN_SECONDS = 86400;

	private $settings = array();
	private $isLoaded = false;
	private $optionName = 'avp_settings';

	/**
	 * Lazy-load plugin settings from the database.
	 */
	public function load() {
		if ( $this->isLoaded ) {
			return;
		}

		$defaults = array(
			'username' => null,
			'password' => null,
			'login_error' => null,
			'trial_period' => 7 * self::DAY_IN_SECONDS,
			'check_period' => 1 * self::DAY_IN_SECONDS,
		);
		$settings = get_site_option($this->optionName, array());
		if ( !is_array($settings) ) {
			$settings = array();
		}

		$this->settings = array_merge($defaults, $settings);
		$this->isLoaded = true;
	}

	/**
	 * Reload settings from the database.
	 */
	public function reload() {
		$this->isLoaded = false;
		$this->load();
	}

	/**
	 * Save settings to the database.
	 */
	public function save() {
		update_site_option($this->optionName, $this->settings);
	}

	/**
	 * Get the name of the WordPress option where settings are stored.
	 * @return string
	 */
	public function getOptionName() {
		return $this->optionName;
	}

	/**
	 * Retrieve all settings as a native PHP array.
	 */
	public function toArray() {
		$this->load();
		return $this->settings;
	}

	public function offsetExists($offset) {
		$this->load();
		return array_key_exists($offset, $this->settings);
	}

	public function offsetGet($offset) {
		$this->load();
		if ( array_key_exists($offset, $this->settings) ) {
			return $this->settings[$offset];
		}
		return null;
	}

	public function offsetSet($offset, $value) {
		$this->load();
		$this->settings[$offset] = $value;
	}

	public function offsetUnset($offset) {
		$this->load();
		if ( array_key_exists($offset, $this->settings) ) {
			unset($this->settings[$offset]);
		}
	}
}

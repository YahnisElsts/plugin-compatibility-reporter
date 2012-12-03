<?php
/**
 * Tracks and stores Avp_PluginModel's.
 */
class Avp_PluginStorage implements ArrayAccess, Iterator {
	private $optionName = 'avp_plugin_list';

	/**
	 * @var Avp_PluginModel[] $plugins
	 */
	private $plugins = array();
	private $isLoaded = false;

	private $isValidIterator = false;

	/**
	 * Lazy-load plugin records from the database.
	 */
	public function load() {
		if ( $this->isLoaded ) {
			return;
		}

		$plugins = get_site_option($this->optionName, array());
		$this->plugins = is_array($plugins) ? $plugins : array();
		$this->plugins = array_map('maybe_unserialize', $this->plugins);

		$this->isLoaded = true;
	}

	public function reload() {
		$this->isLoaded = false;
		$this->load();
	}

	public function save() {
		//We need to serialize plugin models manually instead of letting WP do it. This is because
		//some caching plugins will load site options before plugins are loaded, which causes
		//"incomplete object" warnings as the the class hasn't been loaded yet by that point.
		$plugins = array_map('maybe_serialize', $this->plugins);
		update_site_option($this->optionName, $plugins);
	}

	/**
	 * Get a plugin record by filename. Returns null if the plugin is not found.
	 *
	 * @param string $pluginFile
	 * @return Avp_PluginModel
	 */
	public function get($pluginFile) {
		$this->load();
		if ( array_key_exists($pluginFile, $this->plugins) ) {
			return $this->plugins[$pluginFile];
		}
		return null;
	}

	/**
	 * Update the internal plugin records to match the actual, installed plugins.
	 * Changes are saved automatically. You don't need to explicitly call save().
	 */
	public function update() {
		$this->load();

		if ( !function_exists('get_plugins') ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$installedPlugins = get_plugins();
		$activePlugins = array_map('plugin_basename', wp_get_active_and_valid_plugins());

		//Remove uninstalled plugins from the internal plugin list.
		$keep = array();
		foreach($this->plugins as $pluginFile => $state) {
			if ( isset($installedPlugins[$pluginFile]) ) {
				$keep[$pluginFile] = $state;
			}
		}
		$this->plugins = $keep;

		foreach($installedPlugins as $pluginFile => $headers) {
			$isActive = in_array($pluginFile, $activePlugins);

			//Add previously unseen plugins to the internal list.
			if ( !isset($this->plugins[$pluginFile]) ) {
				$this->plugins[$pluginFile] = new Avp_PluginModel($pluginFile, $headers, $isActive);
			}

			//Update plugin versions and active/inactive status.
			$plugin = $this->plugins[$pluginFile]; /** @var Avp_PluginModel $plugin */
			$plugin->updateVersion($headers);
			$plugin->setActive($isActive);
		}

		$this->save();
	}

	/**
	 * Discard all cached compatibility votes.
	 */
	public function clearVotes() {
		$this->load();
		foreach($this->plugins as $plugin) {
			$plugin->setVotes(null);
		}
	}

	/**
	 * Whether a offset exists
	 *
	 * @param string $offset An offset to check for.
	 * @return boolean True on success or false on failure.
	 */
	public function offsetExists($offset) {
		$this->load();
		return array_key_exists($offset, $this->plugins);
	}

	/**
	 * @param string $offset The offset to retrieve.
	 * @return Avp_PluginModel
	 */
	public function offsetGet($offset) {
		return $this->get($offset);
	}

	/**
	 * @param string $offset The offset to assign the value to.
	 * @param mixed $value The value to set.
	 * @return void
	 */
	public function offsetSet($offset, $value) {
		$this->load();
		$this->plugins[$offset] = $value;
	}

	/**
	 * @param string $offset The offset to unset.
	 * @return void
	 */
	public function offsetUnset($offset) {
		$this->load();
		if ( array_key_exists($offset, $this->plugins) ) {
			unset($this->plugins[$offset]);
		}
	}

	/**
	 * Return the current element
	 * @return Avp_PluginModel
	 */
	public function current() {
		return current($this->plugins);
	}

	/**
	 * Move forward to next element
	 * @return void Any returned value is ignored.
	 */
	public function next() {
		$this->isValidIterator = (next($this->plugins) !== false);
	}

	/**
	 * Return the key of the current element
	 * @return mixed scalar on success, or null on failure.
	 */
	public function key() {
		return key($this->plugins);
	}

	/**
	 * Checks if current position is valid
	 * @return boolean The return value will be casted to boolean and then evaluated.
	 * Returns true on success or false on failure.
	 */
	public function valid() {
		return $this->isValidIterator;
	}

	/**
	 * Rewind the Iterator to the first element
	 * @return void Any returned value is ignored.
	 */
	public function rewind() {
		$this->isValidIterator = (reset($this->plugins) !== false);
	}
}
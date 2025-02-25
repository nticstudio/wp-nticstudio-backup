<?php

namespace NticstudioWpBackup; // Change this to use your namespace

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the plugin or theme update checking process for self-hosted repositories.
 *
 * This class integrates with WordPress's update system to check for updates
 * from a self-hosted server, such as GitHub. It uses metadata and cached data
 * to determine if updates are available and handles version comparisons.
 *
 * Key Features:
 * - Fetches update data from a self-hosted GitHub repository.
 * - Uses transients to cache update data, reducing redundant API calls.
 * - Integrates seamlessly with WordPress's native update process.
 */
class Updater_Checker {
	/**
	 * Duration for which the transient cache is valid.
	 * Default is set to 24 hours (DAY_IN_SECONDS).
	 */
	protected const CACHE_TIME = \DAY_IN_SECONDS; // Change this to your desired cache time.

	/**
	 * Base URL for accessing the plugin's metadata in the GitHub repository.
	 * Replace `{{username}}` and `{{repository}}` with actual values to construct the full URL.
	 */
	protected const REPOSITORY_BASE_URL = 'https://api.github.com/repos/{{username}}/{{repository}}/contents/wp-dist/data.json';

	/**
	 * The basename of the plugin file.
	 * Example: 'plugin-slug/plugin-slug.php'.
	 *
	 * Used to identify the plugin within WordPress.
	 */
	protected string $plugin_basename;

	/**
	 * The directory name of the plugin.
	 * Extracted from the plugin's basename.
	 * Example: 'plugin-slug'.
	 */
	protected string $plugin_dirname;

	/**
	 * The current version of the plugin.
	 * Example: '1.0.0'.
	 *
	 * Used to compare with the latest version available on the server.
	 */
	protected string $plugin_current_version;

	/**
	 * The full repository URL pointing to the plugin metadata file.
	 * Constructed dynamically based on the GitHub username and repository name.
	 */
	protected string $repository_url;

	/**
	 * Indicates whether a forced update check has already been performed.
	 * Default is `false`. Prevents duplicate forced checks during execution.
	 */
	protected bool $already_forced;

	/**
	 * The GitHub username or organization that owns the repository.
	 * Example: 'yourusername'.
	 */
	protected string $github_username;

	/**
	 * The name of the GitHub repository containing the plugin.
	 * Example: 'my-repo'.
	 */
	protected string $github_repository;

	/**
	 * The unique cache key for storing plugin update data in a transient.
	 *
	 * This key is dynamically generated based on the plugin directory name
	 * to ensure it is unique for each plugin.
	 *
	 * Example:
	 * - If `$plugin_dirname` is `plugin-slug`, the cache key will be `plugin-slug_update_data`.
	 */
	protected string $cache_key;

	/**
	 * Initializes the plugin update checker.
	 *
	 * The constructor sets up the required properties for the GitHub repository,
	 * plugin information, and prepares the base URL and plugin directory name.
	 *
	 * @param string $github_username        The GitHub username or organization name where the repository is hosted.
	 * @param string $github_repository      The name of the GitHub repository containing the plugin.
	 * @param string $plugin_basename        The plugin's basename (e.g., 'plugin-slug/plugin-slug.php').
	 * @param string $plugin_current_version The current version of the plugin (e.g., '1.0.0').
	 */
	public function __construct( string $github_username, string $github_repository, string $plugin_basename, string $plugin_current_version ) {
		$this->github_username = $github_username;
		$this->github_repository = $github_repository;
		$this->plugin_basename = $plugin_basename;
		$this->plugin_current_version = $plugin_current_version;
		$this->already_forced = false;

		$this->set_plugin_dirname();
		$this->set_base_url();
		$this->set_cache_key();
	}

	/**
	 * Registers WordPress hooks for the plugin update mechanism.
	 *
	 * This method integrates the plugin's update system into WordPress by:
	 * - Filtering plugin information requests.
	 * - Handling plugin update checks.
	 * - Purging cached update data after an update.
	 *
	 * @return void
	 */
	public function set_hooks() {
		\add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 20, 3 );
		\add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ) );
		\add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
	}

	/**
	 * Filters the plugin information request.
	 *
	 * This method is hooked into the `plugins_api` filter to provide custom plugin metadata.
	 * It validates the action and slug before fetching update metadata from the server.
	 * If valid metadata is retrieved, it formats the response to match WordPress's expected structure.
	 *
	 * @param mixed       $result The default plugin information result (passed by WordPress).
	 * @param string|null $action The type of information being requested. Expected: 'plugin_information'.
	 * @param object|null $args   Additional arguments provided by WordPress, including the plugin slug.
	 *
	 * @return mixed|\stdClass Returns the default result if the action or slug is invalid, or an
	 *                         object containing the plugin metadata if valid data is retrieved.
	 */
	public function get_plugin_info( $result, $action = null, $args = null ) {
		if ( $action !== 'plugin_information' || $this->plugin_dirname !== $args->slug ) {
			return $result;
		}

		$metadata_from_server = $this->fetch_update_metadata();
		if ( ! $this->validate_metadata( $metadata_from_server ) ) {
			return $result;
		}

		$result = new \stdClass();
		$result->name = $metadata_from_server['name'] ?? '';
		$result->slug = $metadata_from_server['slug'] ?? '';
		$result->version = $metadata_from_server['version'] ?? '';
		$result->tested = $metadata_from_server['tested'] ?? '';
		$result->requires = $metadata_from_server['requires'] ?? '';
		$result->author = $metadata_from_server['author'] ?? '';
		$result->author_profile = $metadata_from_server['author_profile'] ?? '';
		$result->download_link = $metadata_from_server['download_url'] ?? '';
		$result->trunk = $metadata_from_server['download_url'] ?? '';
		$result->requires_php = $metadata_from_server['requires_php'] ?? '';
		$result->last_updated = $metadata_from_server['last_updated'] ?? '';
		$result->sections = array(
			'description' => $metadata_from_server['sections']['description'] ?? '',
			'installation' => $metadata_from_server['sections']['installation'] ?? '',
			'changelog' => $metadata_from_server['sections']['changelog'] ?? '',
			'upgrade_notice' => $metadata_from_server['sections']['upgrade_notice'] ?? '',
		);

		return $result;
	}

	/**
	 * Fetches update data from the GitHub repository or cache.
	 *
	 * This method retrieves metadata for plugin updates, either from the transient cache
	 * or directly from the GitHub API if no cached data is available or a forced update is requested.
	 *
	 * Workflow:
	 * - Checks for a transient cache with update data.
	 * - If `force-check=1` is set in the query string, ignores the cache and forces a fresh request.
	 * - Sends an HTTP GET request to the GitHub repository URL to fetch update metadata.
	 * - Caches the retrieved data for future use.
	 *
	 * Error Handling:
	 * - Returns a `WP_Error` if the HTTP request fails or if the response code is not 200.
	 *
	 * @return array|\WP_Error Array of update data on success, or `WP_Error` on failure.
	 */
	public function fetch_update_metadata() {
		$data_from_server = \get_transient( $this->cache_key );

		$force_update = \sanitize_text_field( $_GET['force-check'] ?? '' );
		if ( $force_update === '1' && $this->already_forced === false ) {
			$data_from_server = false;
			$this->already_forced = true;
		}

		if ( $data_from_server === false ) {
			$response = \wp_remote_get(
				$this->repository_url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Accept' => 'application/vnd.github.v3.raw',
						'User-Agent' => 'WordPress-Request',
					),
				)
			);

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = \wp_remote_retrieve_response_code( $response );
			if ( $response_code !== 200 ) {
				return new \WP_Error(
					'http_error',
					'Request error: HTTP code ' . $response_code
				);
			}

			$body = \wp_remote_retrieve_body( $response );
			$data_from_server = json_decode( $body, true );

			\set_transient( $this->cache_key, $data_from_server, self::CACHE_TIME );
		}

		return $data_from_server;
	}

	/**
	 * Checks for updates and modifies the transient data for plugin updates.
	 *
	 * This method is hooked into the `site_transient_update_plugins` filter
	 * to check if a newer version of the plugin is available. It fetches metadata
	 * from the update server, validates it, and updates the transient data with
	 * the appropriate response for WordPress's update system.
	 *
	 * @param object $transient The transient object containing update information for all plugins.
	 * @return object The modified transient object with the update information for this plugin.
	 */
	public function check_for_update( $transient ) {
		if ( ! isset( $transient->response ) ) {
			return $transient;
		}

		$metadata_from_server = $this->fetch_update_metadata();
		if( ! $this->validate_metadata( $metadata_from_server ) ) {
			return $transient;
		}

		$update_data = $this->prepare_plugin_update_data( $metadata_from_server );
		if ( version_compare( $this->plugin_current_version, $metadata_from_server['version'], '<' ) ) {
			$transient->response[ $this->plugin_basename ] = $update_data;
		} elseif ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->plugin_basename ] = $update_data;
		}

		return $transient;
	}

	/**
	 * Prepares the plugin update data for WordPress.
	 *
	 * This method formats the metadata retrieved from the update server into a structure
	 * that WordPress expects for handling plugin updates. The formatted data includes
	 * information such as the plugin slug, current version, tested WordPress version,
	 * and the download URL for the update package.
	 *
	 * @param array $metadata_from_server The metadata fetched from the update server,
	 *                                    typically including 'version', 'tested', and 'download_url'.
	 * @return stdClass An object containing the formatted plugin update data:
	 *                  - slug (string): The directory name of the plugin.
	 *                  - plugin (string): The plugin's basename (e.g., 'plugin-slug/plugin-slug.php').
	 *                  - new_version (string): The version of the plugin available on the server.
	 *                  - tested (string): The WordPress version this plugin update was tested with.
	 *                  - package (string): The URL to the download package for the plugin update.
	 */
	public function prepare_plugin_update_data( $metadata_from_server ) {
		$update_data = new \stdClass();
		$update_data->slug = $this->plugin_dirname;
		$update_data->plugin = $this->plugin_basename;
		$update_data->new_version = $metadata_from_server['version'] ?? '';
		$update_data->tested = $metadata_from_server['tested'] ?? '';
		$update_data->package = $metadata_from_server['download_url'] ?? '';
		return $update_data;
	}

	/**
	 * Clears cached update data after the plugin is updated.
	 *
	 * @param object $upgrader The upgrader instance.
	 * @param array  $options  Options passed during the upgrade process.
	 * @return void
	 */
	public function purge( $upgrader, $options ) {
		if (
			isset( $options['action'], $options['type'], $options['plugins'] ) &&
			$options['action'] === 'update' &&
			$options['type'] === 'plugin'
		) {
			foreach ( $options['plugins'] as $plugin ) {
				if ( $plugin === $this->plugin_basename ) {
					\delete_transient( $this->cache_key );
					break;
				}
			}
		}
	}

	/**
	 * Sets the directory name of the plugin.
	 *
	 * This method extracts the directory name from the plugin's basename and assigns
	 * it to the `$plugin_dirname` property. If the `plugin_basename` property is not set,
	 * it returns an empty string.
	 *
	 * @return void
	 */
	private function set_plugin_dirname() {
		if( ! isset( $this->plugin_basename ) ) {
			return '';
		}

		$directory = explode( '/', $this->plugin_basename );
		$this->plugin_dirname = $directory[0] ?? '';
	}

	/**
	 * Sets the base URL of the GitHub repository.
	 *
	 * This method constructs the repository URL by replacing placeholders in the
	 * constant `REPOSITORY_BASE_URL` with the GitHub username and repository name.
	 * The resulting URL is assigned to the `$repository_url` property.
	 *
	 * Requirements:
	 * - The `$github_repository` and `$github_username` properties must be set.
	 *   If either is not set, the method returns an empty string and does not modify `$repository_url`.
	 *
	 * @return void
	 */
	private function set_base_url() {
		if ( ! isset( $this->github_repository ) || ! isset( $this->github_username ) ) {
			return '';
		}

		$this->repository_url = str_replace(
			array(
				'{{username}}',
				'{{repository}}',
			),
			array(
				$this->github_username,
				$this->github_repository,
			),
			self::REPOSITORY_BASE_URL
		);
	}

	/**
	 * Sets the cache key for storing update data.
	 *
	 * The cache key is generated dynamically using the plugin's directory name.
	 * It is used to store and retrieve transient data related to plugin updates.
	 *
	 * @return void
	 */
	private function set_cache_key() {
		$this->cache_key = $this->plugin_dirname . '_update_data';
	}

	/**
	 * Validates the retrieved update metadata.
	 *
	 * This method ensures that the metadata is an array and handles WP_Error responses.
	 *
	 * @param mixed $metadata The data returned from `fetch_update_metadata`.
	 * @return array|false Returns the metadata as an array if valid, or `false` on error.
	 */
	private function validate_metadata( $metadata ) {
		if ( \is_wp_error( $metadata ) ) {
			error_log( 'Update metadata fetch error: ' . $metadata->get_error_message() );
			return false;
		}

		if ( ! is_array( $metadata ) ) {
			error_log( 'Invalid update metadata: Expected array, got ' . gettype( $metadata ) );
			return false;
		}

		return $metadata;
	}
}
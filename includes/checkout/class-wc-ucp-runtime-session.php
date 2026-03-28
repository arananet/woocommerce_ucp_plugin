<?php
/**
 * Lightweight runtime WooCommerce session used for headless REST calls.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Runtime_Session {

	/**
	 * Session data store.
	 *
	 * @var array
	 */
	private $data = array();

	/**
	 * Retrieve a value from the session.
	 *
	 * @param string $key     Data key.
	 * @param mixed  $default Default value when key is missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Store a value in the session.
	 *
	 * @param string $key   Data key.
	 * @param mixed  $value Value to store.
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	/**
	 * Remove a key from the session.
	 *
	 * @param string $key Data key.
	 */
	public function delete( $key ) {
		unset( $this->data[ $key ] );
	}

	/**
	 * Compatibility no-op for handlers that call save_data().
	 */
	public function save_data() {}

	/**
	 * Compatibility no-op for handlers that call cleanup_sessions().
	 */
	public function cleanup_sessions() {}

	/**
	 * Compatibility no-op for handlers that check cookie state.
	 */
	public function set_customer_session_cookie() {}

	/**
	 * Compatibility helper for WC core checks.
	 *
	 * @return bool
	 */
	public function has_session() {
		return true;
	}
}

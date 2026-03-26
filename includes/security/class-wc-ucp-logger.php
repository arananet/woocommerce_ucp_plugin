<?php
/**
 * UCP audit logger. Wraps WooCommerce logging.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Logger {

    const SOURCE = 'wc-ucp';

    /**
     * @var \WC_Logger|null
     */
    private $logger = null;

    /**
     * Get WC logger instance.
     *
     * @return \WC_Logger
     */
    private function get_logger() {
        if ( null === $this->logger && function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
        return $this->logger;
    }

    /**
     * Log an informational event.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public function info( $message, $context = array() ) {
        $this->log( 'info', $message, $context );
    }

    /**
     * Log a warning event.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public function warning( $message, $context = array() ) {
        $this->log( 'warning', $message, $context );
    }

    /**
     * Log an error event.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public function error( $message, $context = array() ) {
        $this->log( 'error', $message, $context );
    }

    /**
     * Log a debug event.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public function debug( $message, $context = array() ) {
        $this->log( 'debug', $message, $context );
    }

    /**
     * Internal log dispatcher.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log( $level, $message, $context = array() ) {
        $logger = $this->get_logger();
        if ( ! $logger ) {
            return;
        }

        if ( ! empty( $context ) ) {
            $message .= ' | Context: ' . wp_json_encode( $context );
        }

        $logger->log( $level, $message, array( 'source' => self::SOURCE ) );
    }
}

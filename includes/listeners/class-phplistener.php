<?php
/**
 * PHP listener for DecaLog.
 *
 * Defines class for PHP listener.
 *
 * @package Listeners
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */

namespace Decalog\Listener;

use Decalog\API\DLogger;
use Decalog\System\Environment;
use Decalog\System\Option;
use Monolog\Logger;
use Monolog\Utils;

/**
 * PHP listener for DecaLog.
 *
 * Defines methods and properties for PHP listener class.
 *
 * @package Listeners
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */
class PhpListener extends AbstractListener {

	/**
	 * The error level mapping.
	 *
	 * @since  1.0.0
	 * @var array $error_level_map List of mappings.
	 */
	private $error_level_map = [
		E_ERROR             => Logger::EMERGENCY,
		E_PARSE             => Logger::EMERGENCY,
		E_CORE_ERROR        => Logger::EMERGENCY,
		E_COMPILE_ERROR     => Logger::EMERGENCY,
		E_USER_ERROR        => Logger::ERROR,
		E_RECOVERABLE_ERROR => Logger::ERROR,
		E_CORE_WARNING      => Logger::WARNING,
		E_WARNING           => Logger::WARNING,
		E_COMPILE_WARNING   => Logger::WARNING,
		E_USER_WARNING      => Logger::WARNING,
		E_NOTICE            => Logger::NOTICE,
		E_USER_NOTICE       => Logger::NOTICE,
		E_STRICT            => Logger::NOTICE,
		E_DEPRECATED        => Logger::INFO,
		E_USER_DEPRECATED   => Logger::INFO,
	];

	/**
	 * The exception level mapping.
	 *
	 * @since  1.0.0
	 * @var array $exception_level_map List of mappings.
	 */
	private $exception_level_map = [
		'ParseError' => Logger::EMERGENCY,
		'Throwable'  => Logger::ERROR,
	];

	/**
	 * The fatal errors levels.
	 *
	 * @since  1.0.0
	 * @var array $fatal_errors List of fatal errors.
	 */
	private $fatal_errors = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ];

	/**
	 * The previous error handler, to restore if needed.
	 *
	 * @since  1.0.0
	 * @var callable $previous_error_handler The previous error handler.
	 */
	private $previous_error_handler;

	/**
	 * The previous exception handler, to restore if needed.
	 *
	 * @since  1.0.0
	 * @var callable $previous_exception_handler The previous exception handler.
	 */
	private $previous_exception_handler;

	/**
	 * Sets the listener properties.
	 *
	 * @since    1.0.0
	 */
	protected function init() {
		global $wpdb;
		$this->id      = 'php';
		$this->name    = 'PHP';
		$this->class   = 'php';
		$this->product = 'PHP';
		$this->version = Environment::php_version();
	}

	/**
	 * Verify if this listener is needed, mainly by verifying if the listen plugin/theme is loaded.
	 *
	 * @return  boolean     True if listener is needed, false otherwise.
	 * @since    1.0.0
	 */
	protected function is_available() {
		return true;
	}

	/**
	 * "Launch" the listener.
	 *
	 * @return  boolean     True if listener was launched, false otherwise.
	 * @since    1.0.0
	 */
	protected function launch() {
		add_action( 'wp_loaded', [ $this, 'environment_check' ] );
		register_shutdown_function( [ $this, 'handle_fatal_error' ] );
		// phpcs:ignore
		$this->previous_error_handler = set_error_handler( [ $this, 'handle_error' ] );
		// phpcs:ignore
		$this->previous_exception_handler = set_exception_handler( [ $this, 'handle_exception' ] );
		return true;
	}

	/**
	 * Check environment modifications.
	 *
	 * @since    1.2.0
	 */
	public function environment_check() {
		$old_version = Option::get( 'php_version', 'x' );
		if ( Environment::php_version() !== $old_version && 'x' !== $old_version ) {
			Option::set( 'php_version', Environment::php_version() );
			if ( version_compare( Environment::php_version(), $old_version, '<' ) ) {
				$this->logger->warning( sprintf( 'PHP version downgraded from %s to %s.', $old_version, Environment::php_version() ) );
			} else {
				$this->logger->notice( sprintf( 'PHP version upgraded from %s to %s.', $old_version, Environment::php_version() ) );
			}
		} elseif ( 'x' === $old_version ) {
			Option::set( 'php_version', Environment::php_version() );
		}
		$old_extensions = Option::get( 'php_extensions', 'x' );
		$new_extensions = get_loaded_extensions();
		if ( $new_extensions !== $old_extensions && 'x' !== $old_extensions ) {
			Option::set( 'php_extensions', $new_extensions );
			$added   = array_diff( $new_extensions, $old_extensions );
			$removed = array_diff( $old_extensions, $new_extensions );
			if ( count( $added ) > 0 ) {
				$this->logger->notice( sprintf( 'Added PHP extension(s): %s.', implode( ', ', $added ) ) );
			}
			if ( count( $removed ) > 0 ) {
				$this->logger->warning( sprintf( 'Removed PHP extension(s): %s.', implode( ', ', $removed ) ) );
			}
		} elseif ( 'x' === $old_extensions ) {
			Option::set( 'php_extensions', $new_extensions );
		}
	}

	/**
	 * Handles fatal errors.
	 *
	 * @since    1.0.0
	 */
	public function handle_fatal_error() {
		$last_error = error_get_last();
		if ( $last_error && in_array( $last_error['type'], $this->fatal_errors, true ) ) {
			DLogger::ban( str_replace( '.php', '', strtolower( $last_error['file'] ) ) );
			$file    = './' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $last_error['file'] ) );
			$file   .= ':' . $last_error['line'];
			$message = sprintf( 'Fatal error (%s): "%s" at %s', $this->code_to_string( $last_error['type'] ), $last_error['message'], $file );
			$this->logger->alert( $message, (int) $last_error['type'] );
		}
	}

	/**
	 * Handles errors.
	 *
	 * @param   integer $code The error code.
	 * @param   string  $message The error message.
	 * @param   string  $file The file where the error was raised.
	 * @param   integer $line The line where the error was raised.
	 * @param   array   $context The context of the error.
	 * @since    1.0.0
	 */
	public function handle_error( $code, $message, $file = '', $line = 0, $context = [] ) {
		if ( ! in_array( $code, $this->fatal_errors, true ) ) {
			DLogger::ban( str_replace( '.php', '', strtolower( $file ) ) );
			$level   = $this->error_level_map[ $code ] ?? Logger::CRITICAL;
			$file    = './' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
			$file   .= ':' . $line;
			$message = sprintf( 'Error (%s): "%s" at %s', $this->code_to_string( $code ), $message, $file );
			$this->logger->log( $level, $message, (int) $code );
		}
		if ( $this->previous_error_handler && is_callable( $this->previous_error_handler ) ) {
			return call_user_func( $this->previous_error_handler, $code, $message, $file, $line, $context );
		} else {
			return true;
		}
	}

	/**
	 * Handles errors.
	 *
	 * @param   Exception $exception  The uncaught exception.
	 * @since    1.0.0
	 */
	public function handle_exception( $exception ) {
		DLogger::ban( str_replace( '.php', '', strtolower( $exception->getFile() ) ) );
		$file    = './' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $exception->getFile() ) );
		$file   .= ':' . $exception->getLine();
		$message = sprintf( 'Uncaught exception (%s): "%s" at %s', Utils::getClass( $exception ), $exception->getMessage(), $file );
		$this->logger->error( $message, (int) $exception->getCode() );
		if ( $this->previous_exception_handler && is_callable( $this->previous_exception_handler ) ) {
			return call_user_func( $this->previous_exception_handler, $exception );
		} else {
			exit( 255 );
		}
	}

	/**
	 * Get the PHP error.
	 *
	 * @param   integer $code   The original PHP error code.
	 * @return  string     The PHP error, ready to print.
	 * @since    1.0.0
	 */
	private function code_to_string( $code ) {
		switch ( $code ) {
			case E_ERROR:
				return 'E_ERROR';
			case E_WARNING:
				return 'E_WARNING';
			case E_PARSE:
				return 'E_PARSE';
			case E_NOTICE:
				return 'E_NOTICE';
			case E_CORE_ERROR:
				return 'E_CORE_ERROR';
			case E_CORE_WARNING:
				return 'E_CORE_WARNING';
			case E_COMPILE_ERROR:
				return 'E_COMPILE_ERROR';
			case E_COMPILE_WARNING:
				return 'E_COMPILE_WARNING';
			case E_USER_ERROR:
				return 'E_USER_ERROR';
			case E_USER_WARNING:
				return 'E_USER_WARNING';
			case E_USER_NOTICE:
				return 'E_USER_NOTICE';
			case E_STRICT:
				return 'E_STRICT';
			case E_RECOVERABLE_ERROR:
				return 'E_RECOVERABLE_ERROR';
			case E_DEPRECATED:
				return 'E_DEPRECATED';
			case E_USER_DEPRECATED:
				return 'E_USER_DEPRECATED';
		}
		return 'Unknown PHP error';
	}
}

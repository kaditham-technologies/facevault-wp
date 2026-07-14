<?php
/**
 * Test stand-in for the Store API RouteException so the checkout gate's
 * class_exists guard passes and the throw path is exercisable in unit
 * tests without WooCommerce.
 *
 * @package FaceVault
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions;

/**
 * Mirrors the constructor shape of the real exception.
 */
class RouteException extends \Exception {

	/**
	 * Machine error code.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $http_status_code;

	/**
	 * Constructor.
	 *
	 * @param string $error_code       Machine error code.
	 * @param string $message          Human message.
	 * @param int    $http_status_code HTTP status.
	 */
	public function __construct( $error_code, $message, $http_status_code = 400 ) {
		parent::__construct( $message );
		$this->error_code       = $error_code;
		$this->http_status_code = $http_status_code;
	}
}

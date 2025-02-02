<?php
/**
 * Blog (site) handling
 *
 * Handles all Blog (site) operations and detection.
 *
 * @package System
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */

namespace Decalog\System;

/**
 * Define the Blog (site) functionality.
 *
 * Handles all Blog (site) operations and detection.
 *
 * @package System
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */
class Blog {

	/**
	 * Initializes the class and set its properties.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Get a blog name.
	 *
	 * @param   integer $id         Optional. The blog id.
	 * @param   string  $default    Optional. Default value to return if blog is not detected.
	 * @return  string  The blog name if detected, $default otherwise.
	 * @since   1.0.0
	 */
	public static function get_blog_name( $id = null, $default = 'unknown' ) {
		if ( $id && is_numeric($id) && $id >0 && Environment::is_wordpress_multisite()) {
			$blog_info = get_blog_details( $id );
			return $blog_info->blogname;

		} elseif ( $id && is_numeric($id) && $id >0) {
			return get_bloginfo('name');

		} else {
			return $default;
		}
	}

	/**
	 * Get the current blog id.
	 *
	 * @param   mixed   $default    Optional. Default value to return if blog is not detected.
	 * @return  mixed|integer The blog id if detected, null otherwise.
	 * @since   1.0.0
	 */
	public static function get_current_blog_id($default = null) {
		$blog_id = $default;
		$id = get_current_blog_id();
		if ( $id && is_numeric($id) && $id >0) {
			$blog_id = $id;
		}
		return $blog_id;
	}

	/**
	 * Get the current blog name.
	 *
	 * @param   string  $default    Optional. Default value to return if blog is not detected.
	 * @return  string  The current blog name if detected, "anonymous" otherwise.
	 * @since   1.0.0
	 */
	public static function get_current_blog_name($default = 'unknown' ) {
		return self::get_blog_name( self::get_current_blog_id(), $default );
	}

}

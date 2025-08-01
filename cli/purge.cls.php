<?php
/**
 * LiteSpeed Cache Purge Interface CLI.
 *
 * @package LiteSpeed\CLI
 */

namespace LiteSpeed\CLI;

defined( 'WPINC' ) || exit();

use LiteSpeed\Core;
use LiteSpeed\Router;
use LiteSpeed\Admin_Display;
use WP_CLI;

/**
 * LiteSpeed Cache Purge Interface
 */
class Purge {

	/**
	 * List all site domains and ids on the network.
	 *
	 * For use with the blog subcommand.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all the site domains and ids in a table.
	 *     $ wp litespeed-purge network_list
	 */
	public function network_list() {
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite installation!' );
			return;
		}

		$buf = WP_CLI::colorize( '%CThe list of installs:%n' ) . PHP_EOL;

		$sites = get_sites();
		foreach ( $sites as $site ) {
			$buf .= WP_CLI::colorize( '%Y' . $site->domain . $site->path . ':%n ID ' . $site->blog_id ) . PHP_EOL;
		}

		WP_CLI::line( $buf );
	}

	/**
	 * Sends an AJAX request to the site.
	 *
	 * @param string $action The action to perform.
	 * @param array  $extra  Additional data to include in the request.
	 * @return object The HTTP response.
	 * @since 1.0.14
	 */
	private function send_request( $action, $extra = array() ) {
		$data = array(
			Router::ACTION => $action,
			Router::NONCE => wp_create_nonce( $action ),
		);
		if ( ! empty( $extra ) ) {
			$data = array_merge( $data, $extra );
		}

		$url = admin_url( 'admin-ajax.php' );
		WP_CLI::debug( 'URL is ' . $url );

		$out = WP_CLI\Utils\http_request( 'GET', $url, $data );
		return $out;
	}

	/**
	 * Purges all cache entries for the blog (the entire network if multisite).
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge Everything associated with the WordPress install.
	 *     $ wp litespeed-purge all
	 */
	public function all() {
		$action = is_multisite() ? Core::ACTION_QS_PURGE_EMPTYCACHE : Core::ACTION_QS_PURGE_ALL;

		$purge_ret = $this->send_request( $action );

		if ( $purge_ret->success ) {
			WP_CLI::success( __( 'Purged All!', 'litespeed-cache' ) );
		} else {
			WP_CLI::error( 'Something went wrong! Got ' . $purge_ret->status_code );
		}
	}

	/**
	 * Purges all cache entries for the blog.
	 *
	 * ## OPTIONS
	 *
	 * <blogid>
	 * : The blog id to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     # In a multisite install, purge only the shop.example.com cache (stored as blog id 2).
	 *     $ wp litespeed-purge blog 2
	 *
	 * @param array $args Positional arguments (blogid).
	 */
	public function blog( $args ) {
		if ( ! is_multisite() ) {
			WP_CLI::error( 'Not a multisite installation.' );
			return;
		}

		$blogid = $args[0];
		if ( ! is_numeric( $blogid ) ) {
			$error = WP_CLI::colorize( '%RError: invalid blog id entered.%n' );
			WP_CLI::line( $error );
			$this->network_list( $args );
			return;
		}

		$site = get_blog_details( $blogid );
		if ( false === $site ) {
			$error = WP_CLI::colorize( '%RError: invalid blog id entered.%n' );
			WP_CLI::line( $error );
			$this->network_list( $args );
			return;
		}

		switch_to_blog( $blogid );

		$purge_ret = $this->send_request( Core::ACTION_QS_PURGE_ALL );
		if ( $purge_ret->success ) {
			WP_CLI::success( __( 'Purged the blog!', 'litespeed-cache' ) );
		} else {
			WP_CLI::error( 'Something went wrong! Got ' . $purge_ret->status_code );
		}
	}

	/**
	 * Purges all cache tags related to a URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge the front page.
	 *     $ wp litespeed-purge url https://mysite.com/
	 *
	 * @param array $args Positional arguments (URL).
	 */
	public function url( $args ) {
		$data          = array(
			Router::ACTION => Core::ACTION_QS_PURGE,
		);
		$url           = $args[0];
		$deconstructed = wp_parse_url( $url );
		if ( empty( $deconstructed ) ) {
			WP_CLI::error( 'URL passed in is invalid.' );
			return;
		}

		if ( is_multisite() ) {
			if ( 0 === get_blog_id_from_url( $deconstructed['host'], '/' ) ) {
				WP_CLI::error( 'Multisite URL passed in is invalid.' );
				return;
			}
		} else {
			$deconstructed_site = wp_parse_url( get_home_url() );
			if ( $deconstructed['host'] !== $deconstructed_site['host'] ) {
				WP_CLI::error( 'Single site URL passed in is invalid.' );
				return;
			}
		}

		WP_CLI::debug( 'URL is ' . $url );

		$purge_ret = WP_CLI\Utils\http_request( 'GET', $url, $data );
		if ( $purge_ret->success ) {
			WP_CLI::success( __( 'Purged the URL!', 'litespeed-cache' ) );
		} else {
			WP_CLI::error( 'Something went wrong! Got ' . $purge_ret->status_code );
		}
	}

	/**
	 * Helper function for purging by IDs.
	 *
	 * @param array    $args     The ID list to parse.
	 * @param string   $select   The purge by kind.
	 * @param callable $callback The callback function to check the ID.
	 */
	private function purgeby( $args, $select, $callback ) {
		$filtered = array();
		foreach ( $args as $val ) {
			if ( ! ctype_digit( $val ) ) {
				WP_CLI::debug( '[LSCACHE] Skip val, not a number. ' . $val );
				continue;
			}
			$term = $callback( $val );
			if ( ! empty( $term ) ) {
				WP_CLI::line( $term->name );
				$filtered[] = in_array( $callback, array( 'get_tag', 'get_category' ), true ) ? $term->name : $val;
			} else {
				WP_CLI::debug( '[LSCACHE] Skip val, not a valid term. ' . $val );
			}
		}

		if ( empty( $filtered ) ) {
			WP_CLI::error( 'Arguments must be integer IDs.' );
			return;
		}

		$str = implode( ',', $filtered );

		$purge_titles = array(
			Admin_Display::PURGEBY_CAT => 'Category',
			Admin_Display::PURGEBY_PID => 'Post ID',
			Admin_Display::PURGEBY_TAG => 'Tag',
			Admin_Display::PURGEBY_URL => 'URL',
		);

		WP_CLI::line( 'Will purge the following: [' . $purge_titles[ $select ] . '] ' . $str );

		$data = array(
			Admin_Display::PURGEBYOPT_SELECT => $select,
			Admin_Display::PURGEBYOPT_LIST   => $str,
		);

		$purge_ret = $this->send_request( Core::ACTION_PURGE_BY, $data );
		if ( $purge_ret->success ) {
			WP_CLI::success( __( 'Purged!', 'litespeed-cache' ) );
		} else {
			WP_CLI::error( 'Something went wrong! Got ' . $purge_ret->status_code );
		}
	}

	/**
	 * Purges cache tags for a WordPress tag.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The Term IDs to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge the tag IDs 1, 3, and 5
	 *     $ wp litespeed-purge tag 1 3 5
	 *
	 * @param array $args Positional arguments (IDs).
	 */
	public function tag( $args ) {
		$this->purgeby( $args, Admin_Display::PURGEBY_TAG, 'get_tag' );
	}

	/**
	 * Purges cache tags for a WordPress category.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The Term IDs to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge the category IDs 1, 3, and 5
	 *     $ wp litespeed-purge category 1 3 5
	 *
	 * @param array $args Positional arguments (IDs).
	 */
	public function category( $args ) {
		$this->purgeby( $args, Admin_Display::PURGEBY_CAT, 'get_category' );
	}

	/**
	 * Purges cache tags for a WordPress Post/Product.
	 *
	 * @alias product
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The Post IDs to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge the post IDs 1, 3, and 5
	 *     $ wp litespeed-purge post_id 1 3 5
	 *
	 * @param array $args Positional arguments (IDs).
	 */
	public function post_id( $args ) {
		$this->purgeby( $args, Admin_Display::PURGEBY_PID, 'get_post' );
	}
}

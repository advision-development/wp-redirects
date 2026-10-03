<?php
/**
 * Minimal stand-ins for the Yoast SEO Premium 27.3 redirect classes that YoastManagerStore uses:
 * same class names, method names and signatures, storing in the real options. Loaded only by
 * YoastStoreTest. Deliberately does not define WPSEO_PREMIUM_VERSION.
 */

if ( ! class_exists( 'WPSEO_Redirect' ) ) {
	class WPSEO_Redirect {
		private $origin;
		private $target;
		private $type;
		private $format;

		public function __construct( $origin, $target = '', $type = 301, $format = 'plain' ) {
			$this->origin = 'plain' === $format ? trim( $origin, '/' ) : $origin;
			$this->target = $target;
			$this->type   = (int) $type;
			$this->format = $format;
		}

		public function get_origin() {
			return $this->origin;
		}

		public function get_target() {
			return $this->target;
		}

		public function get_type() {
			return $this->type;
		}

		public function get_format() {
			return $this->format;
		}

		public function origin_is( $url ) {
			if ( 'plain' === $this->format ) {
				$url = trim( $url, '/' );
			}
			return (string) $this->origin === (string) $url;
		}
	}

	class WPSEO_Redirect_Option {
		private $redirects = [];

		public function __construct( $retrieve_redirects = true ) {
			if ( $retrieve_redirects ) {
				$this->redirects = $this->get_all();
			}
		}

		public function get_all() {
			$out = [];
			foreach ( (array) get_option( 'wpseo-premium-redirects-base', [] ) as $row ) {
				$out[] = new WPSEO_Redirect( $row['origin'], $row['url'], $row['type'], $row['format'] );
			}
			return $out;
		}

		public function add( WPSEO_Redirect $redirect ) {
			if ( false === $this->search( $redirect->get_origin() ) ) {
				$this->redirects[] = $redirect;
				return true;
			}
			return false;
		}

		public function delete( WPSEO_Redirect $redirect ) {
			$found = $this->search( $redirect->get_origin() );
			if ( false !== $found ) {
				unset( $this->redirects[ $found ] );
				return true;
			}
			return false;
		}

		public function get( $origin ) {
			$found = $this->search( $origin );
			return false !== $found ? $this->redirects[ $found ] : false;
		}

		public function search( $origin ) {
			foreach ( $this->redirects as $key => $redirect ) {
				if ( $redirect->origin_is( $origin ) ) {
					return $key;
				}
			}
			return false;
		}

		public function save( $retry_upgrade = true ) {
			$rows = [];
			foreach ( $this->redirects as $redirect ) {
				$rows[] = [
					'origin' => $redirect->get_origin(),
					'url'    => $redirect->get_target(),
					'type'   => $redirect->get_type(),
					'format' => $redirect->get_format(),
				];
			}
			update_option( 'wpseo-premium-redirects-base', $rows, false );
		}
	}

	class WPSEO_Redirect_Manager {
		public static $saves = 0;

		private $redirect_option;

		public function __construct( $redirect_format = 'plain', $exporters = null, $option = null ) {
			$this->redirect_option = $option ? $option : new WPSEO_Redirect_Option();
		}

		public function save_redirects() {
			++self::$saves;
			$this->redirect_option->save();
			$export = [
				'plain' => [],
				'regex' => [],
			];
			foreach ( $this->redirect_option->get_all() as $redirect ) {
				$export[ $redirect->get_format() ][ $redirect->get_origin() ] = [
					'url'  => $redirect->get_target(),
					'type' => $redirect->get_type(),
				];
			}
			update_option( 'wpseo-premium-redirects-export-plain', $export['plain'] );
			update_option( 'wpseo-premium-redirects-export-regex', $export['regex'] );
		}
	}
}

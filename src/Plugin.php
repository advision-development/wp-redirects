<?php
/**
 * Plugin service container and hook wiring.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\NotFoundController;
use Advision\Redirects\Rest\RedirectsController;
use Advision\Redirects\Rest\SettingsController;
use Advision\Redirects\Rest\TestController;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundLogger;
use Advision\Redirects\Tracking\NotFoundRepository;
use Advision\Redirects\Updates\UpdateChecker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private Repository $repository;
	private RuleCache $rule_cache;
	private ChainResolver $chains;
	private Validator $validator;
	private HitTracker $hit_tracker;
	private NotFoundRepository $not_found;
	private Redirector $redirector;
	private NotFoundLogger $not_found_logger;
	private SlugWatcher $slug_watcher;
	private Cron $cron;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository       = new Repository();
		$this->rule_cache       = new RuleCache( $this->repository );
		$this->chains           = new ChainResolver();
		$this->validator        = new Validator( $this->repository, $this->chains );
		$this->hit_tracker      = new HitTracker( $this->repository );
		$this->not_found        = new NotFoundRepository();
		$this->redirector       = new Redirector( $this->rule_cache, $this->hit_tracker );
		$this->not_found_logger = new NotFoundLogger( $this->not_found, $this->redirector );
		$this->slug_watcher     = new SlugWatcher( $this->repository, $this->validator );
		$this->cron             = new Cron( $this->hit_tracker, $this->not_found );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', [ Schema::class, 'maybe_upgrade' ] );
		add_action( 'plugins_loaded', [ $this, 'loaded' ], 20 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		$this->redirector->register();
		$this->not_found_logger->register();
		$this->slug_watcher->register();
		$this->cron->register();

		UpdateChecker::boot( ADV_REDIRECTS_FILE );
	}

	public function loaded(): void {
		/**
		 * Fires once WP Redirects is loaded.
		 *
		 * @param Plugin $plugin Service container.
		 */
		do_action( 'adv_redirects_loaded', $this );
	}

	public function register_routes(): void {
		$controllers = [
			new RedirectsController( $this->repository, $this->validator, $this->rule_cache, $this->chains ),
			new TestController( $this->rule_cache, $this->chains ),
			new NotFoundController( $this->not_found ),
			new SettingsController(),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	public static function activate(): void {
		Schema::install();
		Cron::ensure_scheduled();
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function rule_cache(): RuleCache {
		return $this->rule_cache;
	}

	public function validator(): Validator {
		return $this->validator;
	}

	public function chains(): ChainResolver {
		return $this->chains;
	}

	public function hit_tracker(): HitTracker {
		return $this->hit_tracker;
	}

	public function not_found(): NotFoundRepository {
		return $this->not_found;
	}

	public function redirector(): Redirector {
		return $this->redirector;
	}

	public function not_found_logger(): NotFoundLogger {
		return $this->not_found_logger;
	}
}

<?php
/**
 * GitHub webhook endpoint.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Lets GitHub announce a new release the moment it is published.
 *
 * The endpoint is a trigger and nothing more: it never installs what the
 * payload describes. A verified delivery only queues a normal automatic run,
 * which asks GitHub's API — with the site's own token — for the newest
 * release and installs that, after the same checks a scheduled run does.
 *
 * Defences, in the order a request meets them:
 *
 * 1. The route is not registered at all unless the feature is on and a
 *    secret exists, so a disabled webhook is a 404 like any unknown URL.
 * 2. The body is capped in size before anything reads it.
 * 3. Every request must carry `X-Hub-Signature-256`, an HMAC-SHA256 of the raw
 *    body under the shared secret, compared in constant time. Nothing without
 *    a valid signature gets past the permission callback.
 * 4. Only `release` events with the `published` action count. Pings answer
 *    with a pong so the GitHub setup screen shows a green tick.
 * 5. The repository named in the payload has to be the configured one.
 * 6. Delivery IDs are remembered, so a redelivered or replayed request is a
 *    no-op.
 */
final class Webhook {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'gthu/v1';

	/**
	 * REST route.
	 */
	const REST_ROUTE = '/release';

	/**
	 * Largest body accepted, in bytes. Release payloads are a few kilobytes.
	 */
	const MAX_BODY = 262144;

	/**
	 * Transient holding recent delivery identifiers.
	 */
	const DELIVERIES_TRANSIENT = 'gthu_webhook_deliveries';

	/**
	 * How many delivery identifiers are remembered.
	 */
	const DELIVERY_MEMORY = 100;

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Runs the update.
	 *
	 * @var Auto_Updater
	 */
	private $updater;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings repository.
	 * @param Auto_Updater $updater  Automatic updater.
	 */
	public function __construct( Settings $settings, Auto_Updater $updater ) {
		$this->settings = $settings;
		$this->updater  = $updater;
	}

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares the route, when the feature is usable.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! $this->settings->get( 'webhook_enabled', false ) || ! $this->settings->has_webhook_secret() ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Address to paste into the GitHub webhook form.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Creates a fresh shared secret.
	 *
	 * @return string 64 hexadecimal characters.
	 */
	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Verifies GitHub's signature of a request body.
	 *
	 * @param string $body      Raw request body, byte for byte.
	 * @param string $signature Value of the X-Hub-Signature-256 header.
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	public static function signature_matches( $body, $signature, $secret ) {
		$signature = trim( (string) $signature );
		$secret    = (string) $secret;

		if ( '' === $secret || 0 !== strpos( $signature, 'sha256=' ) ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $body, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Decides what a release payload means for this site.
	 *
	 * @param array<string, mixed> $payload             Decoded JSON payload.
	 * @param string               $repository          Configured repository as owner/name.
	 * @param bool                 $include_prereleases Whether pre-releases are installed.
	 * @return array{status: string, reason: string, tag: string} `status` is accepted, ignored or rejected.
	 */
	public static function interpret( array $payload, $repository, $include_prereleases ) {
		$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		// GitHub sends several events for one release (published, released,
		// prereleased, edited...). One of them is enough.
		if ( 'published' !== $action ) {
			return self::verdict( 'ignored', 'action', '' );
		}

		$named = isset( $payload['repository']['full_name'] ) ? (string) $payload['repository']['full_name'] : '';

		if ( '' === $named || strtolower( $named ) !== strtolower( (string) $repository ) ) {
			return self::verdict( 'rejected', 'repository', '' );
		}

		$release = isset( $payload['release'] ) && is_array( $payload['release'] ) ? $payload['release'] : array();

		if ( ! empty( $release['draft'] ) ) {
			return self::verdict( 'ignored', 'draft', '' );
		}

		if ( ! empty( $release['prerelease'] ) && ! $include_prereleases ) {
			return self::verdict( 'ignored', 'prerelease', '' );
		}

		$tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		$tag = (string) preg_replace( '/[^A-Za-z0-9._\/+-]/', '', $tag );

		if ( '' === $tag ) {
			return self::verdict( 'ignored', 'tag', '' );
		}

		return self::verdict( 'accepted', 'published', $tag );
	}

	/**
	 * Permission callback: nothing unsigned gets any further.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		$secret = $this->settings->webhook_secret();

		if ( '' === $secret ) {
			return new WP_Error( 'gthu_webhook_disabled', 'Webhook is not configured.', array( 'status' => 404 ) );
		}

		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'gthu_webhook_too_large', 'Payload too large.', array( 'status' => 413 ) );
		}

		$signature = (string) $request->get_header( 'x_hub_signature_256' );

		if ( '' === $signature ) {
			$this->remember( 'rejected', __( 'Rejected a request without a signature.', 'github-theme-updater' ) );

			return new WP_Error( 'gthu_webhook_unsigned', 'Missing X-Hub-Signature-256 header.', array( 'status' => 401 ) );
		}

		if ( ! self::signature_matches( $body, $signature, $secret ) ) {
			$this->remember( 'rejected', __( 'Rejected a request with a wrong signature. The secret on GitHub does not match the one saved here.', 'github-theme-updater' ) );

			return new WP_Error( 'gthu_webhook_signature', 'Signature does not match.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handles a verified delivery.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$event    = strtolower( (string) $request->get_header( 'x_github_event' ) );
		$delivery = (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) $request->get_header( 'x_github_delivery' ) );

		if ( 'ping' === $event ) {
			$this->remember( 'ping', __( 'GitHub sent a ping: the webhook is set up correctly.', 'github-theme-updater' ) );

			return new WP_REST_Response( array( 'status' => 'pong' ), 200 );
		}

		if ( 'release' !== $event ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'event',
				),
				202
			);
		}

		$payload = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array(
					'status' => 'error',
					'reason' => 'json',
				),
				400
			);
		}

		$repository = $this->settings->repository();
		$verdict    = self::interpret(
			$payload,
			$repository ? $repository->full_name() : '',
			(bool) $this->settings->get( 'include_prereleases', false )
		);

		if ( 'rejected' === $verdict['status'] ) {
			$this->remember(
				'rejected',
				sprintf(
					/* translators: %s: repository name sent by GitHub. */
					__( 'Rejected a delivery for a different repository (%s). The webhook must be set up on the repository entered under Settings.', 'github-theme-updater' ),
					isset( $payload['repository']['full_name'] ) ? (string) $payload['repository']['full_name'] : '?'
				)
			);

			return new WP_REST_Response(
				array(
					'status' => 'rejected',
					'reason' => $verdict['reason'],
				),
				403
			);
		}

		if ( 'accepted' !== $verdict['status'] ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => $verdict['reason'],
				),
				202
			);
		}

		if ( '' !== $delivery && ! $this->first_time( $delivery ) ) {
			return new WP_REST_Response(
				array(
					'status' => 'duplicate',
					'tag'    => $verdict['tag'],
				),
				200
			);
		}

		$this->updater->queue_webhook_run( $verdict['tag'] );

		$this->remember(
			'queued',
			sprintf(
				/* translators: %s: git tag name. */
				__( 'GitHub announced release %s; the update was queued.', 'github-theme-updater' ),
				$verdict['tag']
			)
		);

		return new WP_REST_Response(
			array(
				'status' => 'queued',
				'tag'    => $verdict['tag'],
			),
			202
		);
	}

	/**
	 * Whether a delivery identifier has not been seen before, remembering it.
	 *
	 * @param string $delivery Delivery GUID from GitHub.
	 * @return bool
	 */
	private function first_time( $delivery ) {
		$seen = get_transient( self::DELIVERIES_TRANSIENT );
		$seen = is_array( $seen ) ? $seen : array();

		if ( in_array( $delivery, $seen, true ) ) {
			return false;
		}

		$seen[] = $delivery;
		$seen   = array_slice( $seen, -self::DELIVERY_MEMORY );

		set_transient( self::DELIVERIES_TRANSIENT, $seen, WEEK_IN_SECONDS );

		return true;
	}

	/**
	 * Records the last thing the endpoint did, for the settings screen.
	 *
	 * @param string $status  Keyword: ping, queued or rejected.
	 * @param string $message Human readable summary.
	 * @return void
	 */
	private function remember( $status, $message ) {
		$this->settings->update_state(
			array(
				'webhook_last_at'      => time(),
				'webhook_last_status'  => $status,
				'webhook_last_message' => $message,
			)
		);
	}

	/**
	 * Shapes an interpret() result.
	 *
	 * @param string $status Verdict keyword.
	 * @param string $reason Reason keyword.
	 * @param string $tag    Tag to install.
	 * @return array{status: string, reason: string, tag: string}
	 */
	private static function verdict( $status, $reason, $tag ) {
		return array(
			'status' => $status,
			'reason' => $reason,
			'tag'    => $tag,
		);
	}
}

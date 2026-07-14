<?php
/**
 * Per-user verification-status state machine. This class is the single
 * writer of all _facevault_* user meta.
 *
 * Security invariants (do not weaken — tests enforce them):
 * - Webhook events map to users strictly via the stored session id.
 *   external_user_id is a consistency check only, never a lookup key.
 * - Decisions are monotonic per session: review→accept, review→reject and
 *   accept→reject (late veto) apply; once a session has recorded a reject,
 *   a later accept for the same session is ignored (replay hardening —
 *   deliveries carry no timestamp).
 * - Only the decision band is stored. Raw scores are never persisted.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * State machine over user meta.
 */
class User_Status {

	const STATUS_NONE     = 'none';
	const STATUS_PENDING  = 'pending';
	const STATUS_REVIEW   = 'review';
	const STATUS_VERIFIED = 'verified';
	const STATUS_REJECTED = 'rejected';

	const META_STATUS           = '_facevault_status';
	const META_SESSION          = '_facevault_session_id';
	const META_VERIFIED_SESSION = '_facevault_verified_session_id';
	const META_VERIFIED_AT      = '_facevault_verified_at';
	const META_UPDATED_AT       = '_facevault_updated_at';
	const META_HISTORY          = '_facevault_history';
	const META_EXTERNAL_REF     = '_facevault_external_ref';

	const HISTORY_CAP = 10;

	/**
	 * API client for the poll fallback.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api_client API client.
	 */
	public function __construct( Api_Client $api_client ) {
		$this->api_client = $api_client;
	}

	/**
	 * Current status for a user.
	 *
	 * @param int $user_id User id.
	 * @return string One of the STATUS_* constants.
	 */
	public function get_status( $user_id ) {
		$status = get_user_meta( $user_id, self::META_STATUS, true );
		$known  = array( self::STATUS_PENDING, self::STATUS_REVIEW, self::STATUS_VERIFIED, self::STATUS_REJECTED );
		return in_array( $status, $known, true ) ? $status : self::STATUS_NONE;
	}

	/**
	 * When the user was verified (unix timestamp), or 0.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function get_verified_at( $user_id ) {
		return (int) get_user_meta( $user_id, self::META_VERIFIED_AT, true );
	}

	/**
	 * Opaque per-user reference sent to FaceVault as external_user_id.
	 *
	 * Generated once on first use and stored in user meta. The raw WP user
	 * id is deliberately not the default: FaceVault's public status poll is
	 * scoped by (site slug, external_user_id), so sequential integer ids
	 * would let anyone enumerate users' verification statuses. Overridable
	 * via the facevault_external_user_id filter, which receives this ref as
	 * the default.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function external_ref( $user_id ) {
		$ref = (string) get_user_meta( $user_id, self::META_EXTERNAL_REF, true );
		if ( '' === $ref ) {
			$ref = 'wp_' . bin2hex( random_bytes( 16 ) );
			update_user_meta( $user_id, self::META_EXTERNAL_REF, $ref );
		}
		return (string) apply_filters( 'facevault_external_user_id', $ref, $user_id );
	}

	/**
	 * Record a freshly minted widget session. Deliberately does not change
	 * the status — an unopened widget is not a verification attempt.
	 *
	 * @param int    $user_id    User id.
	 * @param string $session_id Session id.
	 */
	public function record_mint( $user_id, $session_id ) {
		update_user_meta( $user_id, self::META_SESSION, $session_id );
		update_user_meta( $user_id, self::META_UPDATED_AT, time() );
	}

	/**
	 * The user's latest widget session id, or ''.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function get_session_id( $user_id ) {
		return (string) get_user_meta( $user_id, self::META_SESSION, true );
	}

	/**
	 * Apply a verified webhook event.
	 *
	 * @param string $session_id       Session id from the payload.
	 * @param string $external_user_id external_user_id echo from the payload.
	 * @param string $trust_decision   accept|review|reject (may be '').
	 * @param string $status           Session status from the payload.
	 * @return string Outcome: applied|duplicate|unknown_session|mismatched_user|stale_session|reject_sticky|ignored_status.
	 */
	public function apply_webhook( $session_id, $external_user_id, $trust_decision, $status ) {
		$user_id = $this->find_user_by_session( $session_id );
		if ( null === $user_id ) {
			return 'unknown_session';
		}

		$stored   = (string) get_user_meta( $user_id, self::META_EXTERNAL_REF, true );
		$expected = apply_filters( 'facevault_external_user_id', '' !== $stored ? $stored : (string) $user_id, $user_id );
		$echo     = (string) $external_user_id;
		// The raw user id stays accepted alongside the opaque ref: sessions
		// minted before the ref existed (pre-0.1.1) echo it, and their
		// deliveries — late vetoes included — must keep applying. The user
		// mapping is via session_id either way; this check is consistency
		// only.
		if ( '' !== $echo && $echo !== (string) $expected && $echo !== (string) $user_id ) {
			return 'mismatched_user';
		}

		$new_status = $this->status_from_decision( $trust_decision, $status );
		if ( null === $new_status ) {
			return 'ignored_status';
		}

		return $this->apply( $user_id, $session_id, $new_status, 'webhook' );
	}

	/**
	 * Apply a GET /sessions/{id} poll result for the user's latest session.
	 *
	 * @param int   $user_id User id.
	 * @param array $session Decoded session payload.
	 * @return string Outcome (see apply_webhook), or ignored_status.
	 */
	public function apply_poll_result( $user_id, $session ) {
		$session_id = isset( $session['session_id'] ) ? (string) $session['session_id'] : $this->get_session_id( $user_id );
		$status     = isset( $session['status'] ) ? (string) $session['status'] : '';

		$new_status = $this->status_from_poll( $status, $user_id );
		if ( null === $new_status ) {
			return 'ignored_status';
		}

		return $this->apply( $user_id, $session_id, $new_status, 'poll' );
	}

	/**
	 * Opportunistic, throttled server-side poll for non-terminal statuses.
	 * Called from render paths; never from cron.
	 *
	 * @param int $user_id User id.
	 */
	public function maybe_poll( $user_id ) {
		if ( ! Settings::get( 'poll_fallback' ) ) {
			return;
		}
		$status = $this->get_status( $user_id );
		if ( ! in_array( $status, array( self::STATUS_PENDING, self::STATUS_REVIEW ), true ) ) {
			return;
		}
		$session_id = $this->get_session_id( $user_id );
		if ( '' === $session_id ) {
			return;
		}
		$throttle_key = 'facevault_poll_' . $user_id;
		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, MINUTE_IN_SECONDS );

		$session = $this->api_client->get_session( $session_id );
		if ( ! is_wp_error( $session ) ) {
			$this->apply_poll_result( $user_id, $session );
		}
	}

	/**
	 * Resolve a session id to a user, via the latest-session and
	 * verified-anchor meta keys only.
	 *
	 * @param string $session_id Session id.
	 * @return int|null
	 */
	public function find_user_by_session( $session_id ) {
		if ( '' === (string) $session_id ) {
			return null;
		}
		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded single-row lookup keyed by an indexed meta_value.
					'relation' => 'OR',
					array(
						'key'   => self::META_SESSION,
						'value' => $session_id,
					),
					array(
						'key'   => self::META_VERIFIED_SESSION,
						'value' => $session_id,
					),
				),
			)
		);
		if ( empty( $users ) ) {
			return null;
		}
		return (int) $users[0];
	}

	/**
	 * Core transition logic shared by webhook and poll paths.
	 *
	 * @param int    $user_id    User id.
	 * @param string $session_id Session the event belongs to.
	 * @param string $new_status        Proposed new status.
	 * @param string $source     webhook|poll|manual.
	 * @return string Outcome.
	 */
	private function apply( $user_id, $session_id, $new_status, $source ) {
		$latest = $this->get_session_id( $user_id );
		$anchor = (string) get_user_meta( $user_id, self::META_VERIFIED_SESSION, true );

		if ( $session_id !== $latest && $session_id !== $anchor ) {
			return 'stale_session';
		}

		$history = $this->get_history( $user_id );

		// Reject is sticky per session: an accept that arrives (or is
		// replayed) after a recorded reject must not re-verify the user.
		if ( self::STATUS_VERIFIED === $new_status ) {
			foreach ( $history as $entry ) {
				if ( $entry['session_id'] === $session_id && self::STATUS_REJECTED === $entry['status'] ) {
					return 'reject_sticky';
				}
			}
		}

		// Idempotency: same session, same resulting status → no-op.
		foreach ( $history as $entry ) {
			if ( $entry['session_id'] === $session_id ) {
				if ( $entry['status'] === $new_status ) {
					return 'duplicate';
				}
				break; // Only the most recent entry for this session counts.
			}
		}

		$old = $this->get_status( $user_id );

		update_user_meta( $user_id, self::META_STATUS, $new_status );
		update_user_meta( $user_id, self::META_UPDATED_AT, time() );

		if ( self::STATUS_VERIFIED === $new_status ) {
			update_user_meta( $user_id, self::META_VERIFIED_AT, time() );
			update_user_meta( $user_id, self::META_VERIFIED_SESSION, $session_id );
		} elseif ( self::STATUS_REJECTED === $new_status && ( $session_id === $anchor || $session_id === $latest ) ) {
			// Late veto (or failed re-verification): fail closed.
			delete_user_meta( $user_id, self::META_VERIFIED_AT );
			delete_user_meta( $user_id, self::META_VERIFIED_SESSION );
		}

		array_unshift(
			$history,
			array(
				'session_id' => $session_id,
				'source'     => $source,
				'status'     => $new_status,
				'time'       => time(),
			)
		);
		update_user_meta( $user_id, self::META_HISTORY, array_slice( $history, 0, self::HISTORY_CAP ) );

		do_action( 'facevault_status_changed', $user_id, $new_status, $old, $source );

		return 'applied';
	}

	/**
	 * Decision/status → plugin status for webhook payloads.
	 *
	 * @param string $trust_decision accept|review|reject or ''.
	 * @param string $status         Session status string.
	 * @return string|null Null when unrecognized (caller must no-op).
	 */
	private function status_from_decision( $trust_decision, $status ) {
		switch ( $trust_decision ) {
			case 'accept':
				return self::STATUS_VERIFIED;
			case 'review':
				return self::STATUS_REVIEW;
			case 'reject':
				return self::STATUS_REJECTED;
		}
		// Fall back to the session status (e.g. a late-veto re-delivery).
		switch ( $status ) {
			case 'passed':
				return self::STATUS_VERIFIED;
			case 'failed':
				return self::STATUS_REJECTED;
			case 'review':
				return self::STATUS_REVIEW;
		}
		return null;
	}

	/**
	 * Poll status → plugin status. Unknown strings (including future
	 * backend additions) map to null so the caller no-ops instead of
	 * corrupting state.
	 *
	 * @param string $status  Session status from GET /sessions/{id}.
	 * @param int    $user_id User id (expiry handling).
	 * @return string|null
	 */
	private function status_from_poll( $status, $user_id ) {
		switch ( $status ) {
			case 'passed':
				return self::STATUS_VERIFIED;
			case 'failed':
				return self::STATUS_REJECTED;
			case 'review':
				return self::STATUS_REVIEW;
			case 'in_progress':
			case 'analyzing':
			case 'pending':
				return self::STATUS_PENDING;
			case 'expired':
			case 'purged':
				// The attempt evaporated; only downgrade non-verified users.
				return self::STATUS_VERIFIED === $this->get_status( $user_id ) ? null : self::STATUS_NONE;
		}
		return null;
	}

	/**
	 * Read the capped history array.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	private function get_history( $user_id ) {
		$history = get_user_meta( $user_id, self::META_HISTORY, true );
		return is_array( $history ) ? $history : array();
	}
}

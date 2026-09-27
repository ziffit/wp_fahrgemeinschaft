<?php
/**
 * Plain data records for the two stored objects.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * A work-service event.
 *
 * Replaces the former WP_Post of the `fg_arbeitsdienst` type. Only fields the
 * plugin actually reads are exposed, so no WordPress post API can be used by
 * accident on these rows.
 */
final class FG_Event {
	/**
	 * Row ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Visible title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Event date as `Y-m-d`.
	 *
	 * @var string
	 */
	public $event_date = '';

	/**
	 * Start time of the duty as `H:i`, empty when the duty lasts the whole day.
	 *
	 * The time has two jobs that happen to be one: it says when the duty begins,
	 * and it is the point at which the duty stops being offered, because
	 * afterwards nobody can join a car any more.
	 *
	 * @var string
	 */
	public $event_time = '';

	/**
	 * Pre-registered participant addresses.
	 *
	 * @var string[]
	 */
	public $participants = array();

	/**
	 * Stable non-public UUID.
	 *
	 * @var string
	 */
	public $event_uuid = '';

	/**
	 * Random public reference used in public URLs and forms.
	 *
	 * @var string
	 */
	public $public_ref = '';

	/**
	 * Whether the event may be offered publicly.
	 *
	 * @var bool
	 */
	public $is_active = false;

	/**
	 * Creation time as `Y-m-d H:i:s` in site time.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Free text naming the group the duty belongs to.
	 *
	 * @var string
	 */
	public $group_name = '';

	/**
	 * Number of people the duty needs, 0 when the club states none.
	 *
	 * @var int
	 */
	public $demand = 0;

	/**
	 * Length of the duty in whole hours, 0 when unknown.
	 *
	 * @var int
	 */
	public $duration_hours = 0;

	/**
	 * Free text about the duty, line breaks included.
	 *
	 * @var string
	 */
	public $description = '';
}

/**
 * A ride, offered or searched for one event.
 */
final class FG_Ride {
	/**
	 * Row ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Owning event ID.
	 *
	 * @var int
	 */
	public $event_id = 0;

	/**
	 * `pending` until the creator confirmed by mail, then `published`.
	 *
	 * @var string
	 */
	public $status = FG_RIDE_STATUS_PENDING;

	/**
	 * `offer` or `search`.
	 *
	 * @var string
	 */
	public $mode = '';

	/**
	 * Publicly visible label chosen by the creator.
	 *
	 * @var string
	 */
	public $alias = '';

	/**
	 * Approximate pickup area.
	 *
	 * @var string
	 */
	public $origin = '';

	/**
	 * Private contact address of the creator.
	 *
	 * @var string
	 */
	public $contact_email = '';

	/**
	 * Random public reference.
	 *
	 * @var string
	 */
	public $public_ref = '';

	/**
	 * Confirmation time, empty while the ride is only a suggestion.
	 *
	 * @var string
	 */
	public $confirmed_at = '';

	/**
	 * Consent text version the creator agreed to.
	 *
	 * @var string
	 */
	public $consent_version = '';

	/**
	 * Time the consent was given.
	 *
	 * @var string
	 */
	public $consented_at = '';

	/**
	 * Creation time.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Hash of the confirmation token.
	 *
	 * @var string
	 */
	public $pending_confirm_hash = '';

	/**
	 * Confirmation token expiry as unix timestamp.
	 *
	 * @var int
	 */
	public $pending_confirm_expires = 0;

	/**
	 * Hash of the discard token.
	 *
	 * @var string
	 */
	public $pending_discard_hash = '';

	/**
	 * Discard token expiry as unix timestamp.
	 *
	 * @var int
	 */
	public $pending_discard_expires = 0;

	/**
	 * Hash of the deletion token of a published ride.
	 *
	 * @var string
	 */
	public $delete_hash = '';

	/**
	 * Deletion token expiry as unix timestamp.
	 *
	 * @var int
	 */
	public $delete_expires = 0;

	/**
	 * Validated URL of the public form the ride came from.
	 *
	 * @var string
	 */
	public $source_url = '';
}

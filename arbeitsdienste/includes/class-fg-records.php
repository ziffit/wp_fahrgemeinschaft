<?php
/**
 * Plain data records for the stored objects.
 *
 * @package Arbeitsdienste
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

	/**
	 * Where to meet, as the club writes it, pin included or not.
	 *
	 * @var string
	 */
	public $meeting_point = '';

	/**
	 * Web address of the meeting point, empty when there is none.
	 *
	 * Only drawn as a link while the text is there: a link whose wording is empty
	 * leads nowhere and says nothing, so an address without a name is not shown at
	 * all. The rule is enforced in one place — the store hands both out, the
	 * renderer and the mailer ask for them together.
	 *
	 * @var string
	 */
	public $meeting_point_url = '';
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
	 * `published` — a ride is visible from the moment it is written.
	 *
	 * The column stays because a version of this plugin that is rolled back
	 * reads it, and because is_valid_public_ride() asks for it rather than
	 * trusting that every row was written by the current code.
	 *
	 * @var string
	 */
	public $status = FG_RIDE_STATUS_PUBLISHED;

	/**
	 * `offer` or `search`.
	 *
	 * @var string
	 */
	public $mode = '';

	/**
	 * Approximate pickup area.
	 *
	 * @var string
	 */
	public $origin = '';

	/**
	 * Random public reference.
	 *
	 * @var string
	 */
	public $public_ref = '';

	/**
	 * Time the ride was published, which since 1.15.0 is its creation time.
	 *
	 * The column is named after the confirmation it used to wait for. It is
	 * written in the same statement that writes the row, so it is never empty on
	 * a ride this version created.
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

	/**
	 * Row ID of the member who offers the ride.
	 *
	 * The key of a ride. Everything that used to be typed by hand — a name for the
	 * public list and an address for the contact request — is read from this
	 * member instead, so a ride cannot name a person who is not in the club and
	 * cannot be answered by an address that belongs to nobody.
	 *
	 * Zero means no member: such a row is not shown and the migration of schema
	 * 1.4.0 removed the ones that existed before the column was there.
	 *
	 * @var int
	 */
	public $member_id = 0;
}

/**
 * A member of the club who may register for work services.
 *
 * The member number is the identity, not the row ID. A member is created by an
 * import from the club's member administration, and the same person coming
 * back with a changed name is the same row. The row ID is never shown to anyone
 * outside the backend.
 */
final class FG_Member {
	/**
	 * Row ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Member number as written in the club's member administration.
	 *
	 * Text, not a number, so a leading zero survives. Compared without regard to
	 * case, which the column's collation does on its own.
	 *
	 * @var string
	 */
	public $member_no = '';

	/**
	 * E-mail address, unique across all members.
	 *
	 * @var string
	 */
	public $email = '';

	/**
	 * First name.
	 *
	 * @var string
	 */
	public $first_name = '';

	/**
	 * Last name.
	 *
	 * @var string
	 */
	public $last_name = '';

	/**
	 * Creation time as `Y-m-d H:i:s` in site time.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Work group of this member.
	 *
	 * Not a key and not the group of a work service: the duty carries its own
	 * group, and this is the standing team or working circle a member belongs to,
	 * the way the club's membership administration files it. Empty means that the
	 * club's file said nothing about it.
	 *
	 * @var string
	 */
	public $work_group = '';

	/**
	 * Time of the last change of name, address or work group.
	 *
	 * @var string
	 */
	public $updated_at = '';
}

/**
 * The registration of one member for one work service.
 *
 * There is at most one such row per pair of member and work service; the
 * database holds a unique key on both columns, so a second registration is
 * refused by the storage layer even if two visitors arrive at the same moment.
 */
final class FG_Event_Member {
	/**
	 * Row ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Owning work service ID.
	 *
	 * @var int
	 */
	public $event_id = 0;

	/**
	 * Registered member ID.
	 *
	 * @var int
	 */
	public $member_id = 0;

	/**
	 * Time of the registration as `Y-m-d H:i:s` in site time.
	 *
	 * @var string
	 */
	public $registered_at = '';

	/**
	 * How many mails this member received for this duty.
	 *
	 * One for the mail of the public registration and one for every further
	 * notification, and only for mails that were actually handed over. A refused
	 * delivery does not count: the number is meant to answer the question "how
	 * often did this member hear about it", and a mail nobody received is not an
	 * answer to it.
	 *
	 * The tokens of the unregistration links are not part of this record. They
	 * live in their own table since schema 1.7.0, because a registration has as
	 * many of them as it has mails.
	 *
	 * @var int
	 */
	public $notified_count = 0;

	/**
	 * Whether the backend entered this member into the duty.
	 *
	 * The public form and the backend are two different acts, and the list of
	 * participants cannot be read without telling them apart: a member who signed
	 * up has heard about the duty, a member who was entered by an editor has not
	 * until somebody sends the mail. The number of notifications beside this flag
	 * is the second half of the same sentence.
	 *
	 * @var bool
	 */
	public $added_by_admin = false;

	/**
	 * Random public reference of this registration, used in the unregistration
	 * link.
	 *
	 * The link in the mail carries this and the token together, so the internal
	 * row ID is not part of any URL.
	 *
	 * @var string
	 */
	public $public_ref = '';

	/**
	 * Validated URL of the duty list the registration came from.
	 *
	 * The unregistration link opens a page of its own, on the site root, and a
	 * member who presses the button there has to land back on the list of duties
	 * where the confirmation can be seen. The list is not at a fixed address, so
	 * the address the form was sent from is kept with the registration, exactly
	 * as a ride keeps the page it was offered on.
	 *
	 * @var string
	 */
	public $source_url = '';
}

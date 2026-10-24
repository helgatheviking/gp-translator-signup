<?php
/**
 * Plugin Name: Translator Sign-up for GlotPress
 * Description: Lets translators register for GlotPress with the languages they can translate. Approving a translator makes them a reviewer for those languages on every project; rejecting stops them from suggesting translations.
 * Version:     0.1.0
 * Author:      helgatheviking
 * Author URI:  https://www.kathyisawesome.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: gp-translator-signup
 *
 * Plugin URI:  https://github.com/helgatheviking/gp-translator-signup
 * Update URI:  https://www.backcourt.io
 *
 * Requires PHP: 8.0
 * Requires at least: 6.9.0
 * Requires Plugins: glotpress
 *
 * @package GP_Translator_Signup
 */

namespace HelgaTheViking\GPTranslatorSignup;

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Update server
// ---------------------------------------------------------------------------

/**
 * Add Git Updater Lite
 */
if ( file_exists( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';

	if ( class_exists( Vendor\Fragen\Git_Updater\Lite::class ) ) {
		( new Vendor\Fragen\Git_Updater\Lite( __FILE__ ) )->run();
	}
}

use GP;
use GP_Locales;
use WP_User;
use WP_User_Query;

/**
 * User meta: GlotPress locale slugs the translator asked to translate.
 */
const LANGUAGES_META = 'gp_translator_languages';

/**
 * User meta: languages they asked for that have no translation set yet.
 */
const OTHER_META = 'gp_translator_other_languages';

/**
 * User meta: pending, approved or rejected. Absent for users who did not sign up through the form.
 */
const STATUS_META = 'gp_translator_status';

/**
 * Hidden field that only bots fill in.
 */
const HONEYPOT = 'gp_translator_website';

/**
 * Returns the translator statuses and their labels.
 *
 * @return array<string, string>
 */
function statuses(): array {
	return array(
		'pending'  => __( 'Pending', 'gp-translator-signup' ),
		'approved' => __( 'Approved', 'gp-translator-signup' ),
		'rejected' => __( 'Rejected', 'gp-translator-signup' ),
	);
}

/**
 * Returns the locales that have at least one translation set, keyed by GlotPress locale slug.
 *
 * @return array<string, string> Locale slug => "English name (native name)".
 */
function available_locales(): array {
	global $wpdb;

	if ( ! class_exists( 'GP' ) || ! class_exists( 'GP_Locales' ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from GlotPress.
	$slugs   = $wpdb->get_col( 'SELECT DISTINCT locale FROM ' . GP::$translation_set->table );
	$locales = array();

	foreach ( $slugs as $slug ) {
		$locale = GP_Locales::by_slug( $slug );
		if ( $locale ) {
			$locales[ $slug ] = $locale->english_name === $locale->native_name
				? $locale->english_name
				: sprintf( '%s (%s)', $locale->english_name, $locale->native_name );
		}
	}

	asort( $locales );
	return $locales;
}

/**
 * Returns a user's requested locale slugs.
 *
 * @param int $user_id
 * @return string[]
 */
function user_languages( int $user_id ): array {
	$languages = get_user_meta( $user_id, LANGUAGES_META, true );
	return is_array( $languages ) ? $languages : array();
}

/**
 * Returns a user's requested languages as readable names.
 *
 * @param int $user_id
 * @return string
 */
function user_languages_label( int $user_id ): string {
	$locales = available_locales();
	$names   = array();

	foreach ( user_languages( $user_id ) as $slug ) {
		$names[] = $locales[ $slug ] ?? $slug;
	}

	$other = (string) get_user_meta( $user_id, OTHER_META, true );
	if ( '' !== $other ) {
		/* translators: %s: languages typed in by the translator */
		$names[] = sprintf( __( 'Other: %s', 'gp-translator-signup' ), $other );
	}

	return $names ? implode( ', ', $names ) : __( 'None given', 'gp-translator-signup' );
}

/**
 * Keeps only locale slugs that have a translation set.
 *
 * @param mixed $submitted Raw submitted value.
 * @return string[]
 */
function sanitize_languages( $submitted ): array {
	if ( ! is_array( $submitted ) ) {
		return array();
	}
	$submitted = array_map( 'sanitize_text_field', wp_unslash( $submitted ) );
	return array_values( array_intersect( $submitted, array_keys( available_locales() ) ) );
}

/**
 * Renders the language checkboxes.
 *
 * @param string[] $checked Locale slugs to check.
 */
function render_language_checkboxes( array $checked ): void {
	foreach ( available_locales() as $slug => $name ) {
		printf(
			'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
			esc_attr( LANGUAGES_META ),
			esc_attr( $slug ),
			checked( in_array( $slug, $checked, true ), true, false ),
			esc_html( $name )
		);
	}
}

// -----------------------------------------------------------------------------
// Registration form
// -----------------------------------------------------------------------------

add_action( 'register_form', __NAMESPACE__ . '\\render_registration_fields' );
add_filter( 'registration_errors', __NAMESPACE__ . '\\check_honeypot' );
// Before WordPress sends its notifications (priority 10), so the admin email can list the languages.
add_action( 'register_new_user', __NAMESPACE__ . '\\save_registration', 5 );

/**
 * Adds the language fields and the honeypot to wp-login.php?action=register.
 */
function render_registration_fields(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Refilling the form after a failed submission.
	$checked = isset( $_POST[ LANGUAGES_META ] ) ? sanitize_languages( $_POST[ LANGUAGES_META ] ) : array();
	$other   = isset( $_POST[ OTHER_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ OTHER_META ] ) ) : '';
	// phpcs:enable
	?>
	<p style="margin-bottom:16px">
		<?php esc_html_e( 'Languages you can translate', 'gp-translator-signup' ); ?><br>
		<?php render_language_checkboxes( $checked ); ?>
	</p>
	<p>
		<label for="<?php echo esc_attr( OTHER_META ); ?>"><?php esc_html_e( 'Other languages (optional)', 'gp-translator-signup' ); ?></label>
		<input type="text" name="<?php echo esc_attr( OTHER_META ); ?>" id="<?php echo esc_attr( OTHER_META ); ?>" class="input" value="<?php echo esc_attr( $other ); ?>" size="25">
	</p>
	<p style="position:absolute;left:-9999px" aria-hidden="true">
		<label for="<?php echo esc_attr( HONEYPOT ); ?>">Website</label>
		<input type="text" name="<?php echo esc_attr( HONEYPOT ); ?>" id="<?php echo esc_attr( HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off">
	</p>
	<?php
}

/**
 * Rejects registrations that filled in the honeypot.
 *
 * @param \WP_Error $errors
 * @return \WP_Error
 */
function check_honeypot( \WP_Error $errors ): \WP_Error {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core's registration form has no nonce.
	if ( ! empty( $_POST[ HONEYPOT ] ) ) {
		$errors->add( 'gp_translator_spam', __( '<strong>Error:</strong> Registration failed. Please try again.', 'gp-translator-signup' ) );
	}
	return $errors;
}

/**
 * Saves the languages and marks the new user as a pending translator.
 *
 * @param int $user_id
 */
function save_registration( int $user_id ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core's registration form has no nonce.
	update_user_meta( $user_id, LANGUAGES_META, sanitize_languages( $_POST[ LANGUAGES_META ] ?? array() ) );
	update_user_meta( $user_id, OTHER_META, sanitize_text_field( wp_unslash( $_POST[ OTHER_META ] ?? '' ) ) );
	// phpcs:enable
	update_user_meta( $user_id, STATUS_META, 'pending' );
}

add_filter( 'wp_new_user_notification_email_admin', __NAMESPACE__ . '\\add_languages_to_admin_email', 10, 2 );

/**
 * Adds the languages and a review link to the "New user registration" email.
 *
 * @param array   $email
 * @param WP_User $user
 * @return array
 */
function add_languages_to_admin_email( array $email, WP_User $user ): array {
	if ( 'pending' !== get_user_meta( $user->ID, STATUS_META, true ) ) {
		return $email;
	}

	/* translators: %s: languages */
	$email['message'] .= "\r\n" . sprintf( __( 'Languages: %s', 'gp-translator-signup' ), user_languages_label( $user->ID ) ) . "\r\n";
	/* translators: %s: URL of the pending translators list */
	$email['message'] .= sprintf( __( 'Review pending translators: %s', 'gp-translator-signup' ), admin_url( 'users.php?' . STATUS_META . '=pending' ) ) . "\r\n";

	return $email;
}

// -----------------------------------------------------------------------------
// GlotPress permissions
// -----------------------------------------------------------------------------

// Before GlotPress's own pre-checks (priority 10), so a rejected translator is not let in by "everyone can translate".
add_filter( 'gp_pre_can_user', __NAMESPACE__ . '\\filter_permissions', 5, 2 );

/**
 * Approved translators can approve in their languages on every project; rejected ones cannot suggest.
 *
 * @param string|bool $verdict
 * @param array       $args    See GP_Permission::user_can().
 * @return string|bool
 */
function filter_permissions( $verdict, array $args ) {
	if ( is_bool( $verdict ) || empty( $args['user'] ) || ! $args['user'] instanceof WP_User ) {
		return $verdict;
	}

	$status = get_user_meta( $args['user']->ID, STATUS_META, true );

	if ( 'rejected' === $status && 'edit' === $args['action'] && 'translation-set' === $args['object_type'] ) {
		return false;
	}

	if ( 'approved' !== $status || 'approve' !== $args['action'] ) {
		return $verdict;
	}

	if ( 'translation-set' === $args['object_type'] ) {
		$set_id = (int) $args['object_id'];
	} elseif ( 'translation' === $args['object_type'] && isset( $args['extra']['translation']->translation_set_id ) ) {
		$set_id = (int) $args['extra']['translation']->translation_set_id;
	} else {
		return $verdict;
	}

	$set = isset( $args['extra']['set'] ) && (int) $args['extra']['set']->id === $set_id
		? $args['extra']['set']
		: GP::$translation_set->get( $set_id );

	if ( $set && in_array( $set->locale, user_languages( $args['user']->ID ), true ) ) {
		return true;
	}

	return $verdict;
}

// -----------------------------------------------------------------------------
// GlotPress header link
// -----------------------------------------------------------------------------

add_filter( 'gp_nav_menu_items', __NAMESPACE__ . '\\add_signup_link', 10, 2 );

/**
 * Adds "Sign up to translate" next to "Log in" when registration is open.
 *
 * @param array  $items    URL => label.
 * @param string $location
 * @return array
 */
function add_signup_link( array $items, string $location ): array {
	if ( 'side' === $location && ! is_user_logged_in() && get_option( 'users_can_register' ) ) {
		$items[ esc_url( wp_registration_url() ) ] = __( 'Sign up to translate', 'gp-translator-signup' );
	}
	return $items;
}

// -----------------------------------------------------------------------------
// Admin: users list
// -----------------------------------------------------------------------------

add_filter( 'manage_users_columns', __NAMESPACE__ . '\\add_users_column' );
add_filter( 'manage_users_custom_column', __NAMESPACE__ . '\\render_users_column', 10, 3 );
add_filter( 'user_row_actions', __NAMESPACE__ . '\\add_row_actions', 10, 2 );
add_filter( 'views_users', __NAMESPACE__ . '\\add_pending_view' );
add_action( 'pre_get_users', __NAMESPACE__ . '\\filter_users_by_status' );
add_action( 'admin_post_gp_translator_status', __NAMESPACE__ . '\\handle_status_change' );
add_action( 'admin_notices', __NAMESPACE__ . '\\render_admin_notices' );

/**
 * @param array $columns
 * @return array
 */
function add_users_column( array $columns ): array {
	$columns['gp_translator'] = __( 'Translator', 'gp-translator-signup' );
	return $columns;
}

/**
 * @param string $output
 * @param string $column
 * @param int    $user_id
 * @return string
 */
function render_users_column( $output, string $column, int $user_id ) {
	if ( 'gp_translator' !== $column ) {
		return $output;
	}
	$status = get_user_meta( $user_id, STATUS_META, true );
	if ( ! $status ) {
		return '&mdash;';
	}
	return sprintf( '<strong>%s</strong><br>%s', esc_html( statuses()[ $status ] ?? $status ), esc_html( user_languages_label( $user_id ) ) );
}

/**
 * Adds Approve / Reject links under translators in the users list.
 *
 * @param array   $actions
 * @param WP_User $user
 * @return array
 */
function add_row_actions( array $actions, WP_User $user ): array {
	$status = get_user_meta( $user->ID, STATUS_META, true );
	if ( ! $status || ! current_user_can( 'promote_user', $user->ID ) ) {
		return $actions;
	}

	foreach ( array( 'approved' => __( 'Approve', 'gp-translator-signup' ), 'rejected' => __( 'Reject', 'gp-translator-signup' ) ) as $new_status => $label ) {
		if ( $new_status === $status ) {
			continue;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=gp_translator_status&user_id=' . $user->ID . '&status=' . $new_status ),
			'gp_translator_status_' . $user->ID
		);
		$actions[ 'gp_translator_' . $new_status ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	return $actions;
}

/**
 * Adds a "Pending translators" view above the users list.
 *
 * @param array $views
 * @return array
 */
function add_pending_view( array $views ): array {
	$count = ( new WP_User_Query(
		array(
			'meta_key'    => STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
			'count_total' => true,
			'fields'      => 'ID',
			'number'      => 1,
		)
	) )->get_total();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
	$current = isset( $_GET[ STATUS_META ] ) && 'pending' === $_GET[ STATUS_META ];

	$views['gp_translator_pending'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url( admin_url( 'users.php?' . STATUS_META . '=pending' ) ),
		$current ? ' class="current" aria-current="page"' : '',
		esc_html__( 'Pending translators', 'gp-translator-signup' ),
		$count
	);

	return $views;
}

/**
 * Filters the users list by translator status.
 *
 * @param WP_User_Query $query
 */
function filter_users_by_status( WP_User_Query $query ): void {
	global $pagenow;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
	$status = isset( $_GET[ STATUS_META ] ) ? sanitize_key( $_GET[ STATUS_META ] ) : '';

	if ( ! is_admin() || 'users.php' !== $pagenow || ! isset( statuses()[ $status ] ) ) {
		return;
	}

	$query->set( 'meta_key', STATUS_META );
	$query->set( 'meta_value', $status );
}

/**
 * Handles the Approve / Reject links.
 */
function handle_status_change(): void {
	$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
	$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

	check_admin_referer( 'gp_translator_status_' . $user_id );

	if ( ! $user_id || ! isset( statuses()[ $status ] ) || ! current_user_can( 'promote_user', $user_id ) ) {
		wp_die( esc_html__( 'You are not allowed to change this translator.', 'gp-translator-signup' ), 403 );
	}

	set_status( $user_id, $status );

	wp_safe_redirect( add_query_arg( 'gp_translator_updated', $status, wp_get_referer() ?: admin_url( 'users.php' ) ) );
	exit;
}

/**
 * Shows a notice after Approve / Reject.
 */
function render_admin_notices(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$status = isset( $_GET['gp_translator_updated'] ) ? sanitize_key( $_GET['gp_translator_updated'] ) : '';
	if ( 'approved' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translator approved. They can now review translations in their languages.', 'gp-translator-signup' ) . '</p></div>';
	} elseif ( 'rejected' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translator rejected. They can no longer suggest translations.', 'gp-translator-signup' ) . '</p></div>';
	}
}

/**
 * Changes a translator's status, and emails them when they are approved.
 *
 * @param int    $user_id
 * @param string $status
 */
function set_status( int $user_id, string $status ): void {
	$previous = get_user_meta( $user_id, STATUS_META, true );
	update_user_meta( $user_id, STATUS_META, $status );

	if ( 'approved' !== $status || 'approved' === $previous ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	wp_mail(
		$user->user_email,
		/* translators: %s: site name */
		sprintf( __( '[%s] You can now review translations', 'gp-translator-signup' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ),
		sprintf(
			/* translators: 1: languages, 2: GlotPress URL */
			__( "Thanks for translating! You can now approve translations in: %1\$s.\n\nApproved translations are sent to every site using that language.\n\n%2\$s", 'gp-translator-signup' ),
			user_languages_label( $user_id ),
			gp_url_public_root()
		)
	);
}

// -----------------------------------------------------------------------------
// Admin: user profile
// -----------------------------------------------------------------------------

// Only on other users' profiles: translators must not be able to add languages to their own approval.
add_action( 'edit_user_profile', __NAMESPACE__ . '\\render_profile_fields' );
add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save_profile_fields' );

/**
 * Lets admins edit a translator's status and languages.
 *
 * @param WP_User $user
 */
function render_profile_fields( WP_User $user ): void {
	if ( ! current_user_can( 'promote_user', $user->ID ) ) {
		return;
	}
	$status = get_user_meta( $user->ID, STATUS_META, true );
	?>
	<h2><?php esc_html_e( 'Translator', 'gp-translator-signup' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="<?php echo esc_attr( STATUS_META ); ?>"><?php esc_html_e( 'Status', 'gp-translator-signup' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( STATUS_META ); ?>" id="<?php echo esc_attr( STATUS_META ); ?>">
					<option value=""><?php esc_html_e( 'Not a translator', 'gp-translator-signup' ); ?></option>
					<?php foreach ( statuses() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Approved translators can approve translations in their languages on every project. Rejected translators cannot suggest translations.', 'gp-translator-signup' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Languages', 'gp-translator-signup' ); ?></th>
			<td>
				<?php render_language_checkboxes( user_languages( $user->ID ) ); ?>
				<input type="hidden" name="<?php echo esc_attr( LANGUAGES_META ); ?>_present" value="1">
				<?php $other = (string) get_user_meta( $user->ID, OTHER_META, true ); ?>
				<?php if ( '' !== $other ) : ?>
					<?php /* translators: %s: languages typed in by the translator */ ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Also asked for: %s', 'gp-translator-signup' ), $other ) ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Saves the translator fields. Core has already checked the update-user nonce.
 *
 * @param int $user_id
 */
function save_profile_fields( int $user_id ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by core (update-user_{$user_id}).
	if ( ! current_user_can( 'promote_user', $user_id ) || ! isset( $_POST[ LANGUAGES_META . '_present' ] ) ) {
		return;
	}

	update_user_meta( $user_id, LANGUAGES_META, sanitize_languages( $_POST[ LANGUAGES_META ] ?? array() ) );

	$status = isset( $_POST[ STATUS_META ] ) ? sanitize_key( $_POST[ STATUS_META ] ) : '';
	// phpcs:enable
	if ( isset( statuses()[ $status ] ) ) {
		set_status( $user_id, $status );
	} else {
		delete_user_meta( $user_id, STATUS_META );
	}
}

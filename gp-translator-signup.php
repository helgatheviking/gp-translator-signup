<?php
/**
 * Plugin Name: Translator Sign-up for GlotPress
 * Description: Lets visitors sign up for GlotPress as translators or reviewers, with the languages they can translate. Translators can suggest translations straight away; reviewers can approve translations in their languages once an admin approves them.
 * Version:     0.2.0
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
use WP_Error;
use WP_User;
use WP_User_Query;

/**
 * User meta: GlotPress locale slugs the translator can translate.
 */
const LANGUAGES_META = 'gp_translator_languages';

/**
 * User meta: languages they asked for that have no translation set yet.
 */
const OTHER_META = 'gp_translator_other_languages';

/**
 * User meta: translator, pending, approved or rejected. Absent for users who did not sign up through the form.
 */
const STATUS_META = 'gp_translator_status';

/**
 * User meta: what a reviewer applicant told us about their translation experience.
 */
const EXPERIENCE_META = 'gp_translator_experience';

/**
 * User meta: path of the GlotPress project they signed up from.
 */
const APPLIED_FROM_META = 'gp_translator_applied_from';

/**
 * Form field: "translator" or "reviewer".
 */
const ROLE_FIELD = 'gp_translator_role';

/**
 * Hidden field that only bots fill in.
 */
const HONEYPOT = 'gp_translator_website';

/**
 * Returns the translator statuses and their labels.
 *
 * "rejected" means blocked: kept as the stored value so existing users keep their status.
 *
 * @return array<string, string>
 */
function statuses(): array {
	return array(
		'translator' => __( 'Translator', 'gp-translator-signup' ),
		'pending'    => __( 'Reviewer (pending)', 'gp-translator-signup' ),
		'approved'   => __( 'Reviewer', 'gp-translator-signup' ),
		'rejected'   => __( 'Blocked', 'gp-translator-signup' ),
	);
}

/**
 * Returns a user's translator status, or an empty string.
 *
 * @param int $user_id
 * @return string
 */
function user_status( int $user_id ): string {
	return (string) get_user_meta( $user_id, STATUS_META, true );
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
 * Returns a user's locale slugs.
 *
 * @param int $user_id
 * @return string[]
 */
function user_languages( int $user_id ): array {
	$languages = get_user_meta( $user_id, LANGUAGES_META, true );
	return is_array( $languages ) ? $languages : array();
}

/**
 * Returns a user's languages as readable names.
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
 * Returns the name of the project a user signed up from, or an empty string.
 *
 * @param int $user_id
 * @return string
 */
function user_applied_from_label( int $user_id ): string {
	$path = (string) get_user_meta( $user_id, APPLIED_FROM_META, true );
	if ( '' === $path ) {
		return '';
	}
	$project = GP::$project->by_path( $path );
	return $project ? $project->name : $path;
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
 * Returns the path of an existing, active GlotPress project, or an empty string.
 *
 * @param mixed $path Raw project path.
 * @return string
 */
function sanitize_project_path( $path ): string {
	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}
	$project = GP::$project->by_path( sanitize_text_field( wp_unslash( $path ) ) );
	return $project && $project->active ? $project->path : '';
}

/**
 * Reads the sign-up fields from the submitted form.
 *
 * @return array{role: string, languages: string[], other: string, experience: string, applied_from: string}
 */
function submitted_values(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the nonce where the form has one; core's registration form has none.
	return array(
		'role'         => isset( $_POST[ ROLE_FIELD ] ) && 'reviewer' === $_POST[ ROLE_FIELD ] ? 'reviewer' : 'translator',
		'languages'    => sanitize_languages( $_POST[ LANGUAGES_META ] ?? array() ),
		'other'        => sanitize_text_field( wp_unslash( $_POST[ OTHER_META ] ?? '' ) ),
		'experience'   => sanitize_textarea_field( wp_unslash( $_POST[ EXPERIENCE_META ] ?? '' ) ),
		'applied_from' => sanitize_project_path( $_POST[ APPLIED_FROM_META ] ?? '' ),
	);
	// phpcs:enable
}

/**
 * Adds errors for sign-up fields that don't add up.
 *
 * @param WP_Error $errors
 * @param array    $values See submitted_values().
 * @return WP_Error
 */
function validate_values( WP_Error $errors, array $values ): WP_Error {
	if ( 'reviewer' === $values['role'] && ! $values['languages'] ) {
		$errors->add( 'gp_translator_languages', __( '<strong>Error:</strong> Choose the languages you want to review.', 'gp-translator-signup' ) );
	}
	return $errors;
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

/**
 * Renders the role choice, languages and reviewer experience fields.
 *
 * @param array $values      See submitted_values().
 * @param bool  $choose_role Whether to ask translator or reviewer. Without it, the fields are a reviewer application.
 */
function render_signup_fields( array $values, bool $choose_role = true ): void {
	$is_reviewer = ! $choose_role || 'reviewer' === $values['role'];
	?>
	<?php if ( $choose_role ) : ?>
		<fieldset class="gp-translator-role" style="margin-bottom:16px">
			<legend><?php esc_html_e( 'I want to', 'gp-translator-signup' ); ?></legend>
			<label style="display:block;margin-bottom:4px">
				<input type="radio" name="<?php echo esc_attr( ROLE_FIELD ); ?>" value="translator" <?php checked( ! $is_reviewer ); ?>>
				<?php esc_html_e( 'Translate: suggest translations. You can start straight away.', 'gp-translator-signup' ); ?>
			</label>
			<label style="display:block;margin-bottom:4px">
				<input type="radio" name="<?php echo esc_attr( ROLE_FIELD ); ?>" value="reviewer" <?php checked( $is_reviewer ); ?>>
				<?php esc_html_e( 'Review: approve translations in my languages. Reviewers are approved by the site admin.', 'gp-translator-signup' ); ?>
			</label>
		</fieldset>
	<?php else : ?>
		<input type="hidden" name="<?php echo esc_attr( ROLE_FIELD ); ?>" value="reviewer">
	<?php endif; ?>

	<fieldset style="margin-bottom:16px">
		<legend><?php esc_html_e( 'Languages you can translate', 'gp-translator-signup' ); ?></legend>
		<?php render_language_checkboxes( $values['languages'] ); ?>
	</fieldset>

	<p>
		<label for="<?php echo esc_attr( OTHER_META ); ?>"><?php esc_html_e( 'Other languages (optional)', 'gp-translator-signup' ); ?></label><br>
		<input type="text" name="<?php echo esc_attr( OTHER_META ); ?>" id="<?php echo esc_attr( OTHER_META ); ?>" class="input" value="<?php echo esc_attr( $values['other'] ); ?>" size="25">
	</p>

	<p class="gp-translator-reviewer-fields"<?php echo $is_reviewer ? '' : ' hidden'; ?>>
		<label for="<?php echo esc_attr( EXPERIENCE_META ); ?>"><?php esc_html_e( 'Tell us about your translation experience (optional). Links to your WordPress.org profile or past work help.', 'gp-translator-signup' ); ?></label><br>
		<textarea name="<?php echo esc_attr( EXPERIENCE_META ); ?>" id="<?php echo esc_attr( EXPERIENCE_META ); ?>" rows="4" style="width:100%"><?php echo esc_textarea( $values['experience'] ); ?></textarea>
	</p>

	<?php if ( $choose_role ) : ?>
		<script>
		document.querySelectorAll( 'input[name="<?php echo esc_js( ROLE_FIELD ); ?>"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				radio.form.querySelector( '.gp-translator-reviewer-fields' ).hidden = 'reviewer' !== radio.value;
			} );
		} );
		</script>
	<?php endif; ?>
	<?php
}

/**
 * Renders the hidden spam-trap field.
 */
function render_honeypot(): void {
	?>
	<p style="position:absolute;left:-9999px" aria-hidden="true">
		<label for="<?php echo esc_attr( HONEYPOT ); ?>">Website</label>
		<input type="text" name="<?php echo esc_attr( HONEYPOT ); ?>" id="<?php echo esc_attr( HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off">
	</p>
	<?php
}

// -----------------------------------------------------------------------------
// Registration (wp-login.php and the /join page)
// -----------------------------------------------------------------------------

add_action( 'register_form', __NAMESPACE__ . '\\render_registration_fields' );
add_filter( 'registration_errors', __NAMESPACE__ . '\\check_registration' );
// Before WordPress sends its notifications (priority 10), so the admin email can list the languages.
add_action( 'register_new_user', __NAMESPACE__ . '\\save_registration', 5 );

/**
 * Adds the sign-up fields and the honeypot to wp-login.php?action=register.
 */
function render_registration_fields(): void {
	render_signup_fields( submitted_values() );
	render_honeypot();
}

/**
 * Rejects registrations that filled in the honeypot or are missing reviewer languages.
 *
 * Runs for wp-login.php and for the /join page, which registers through register_new_user().
 *
 * @param WP_Error $errors
 * @return WP_Error
 */
function check_registration( WP_Error $errors ): WP_Error {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core's registration form has no nonce.
	if ( ! empty( $_POST[ HONEYPOT ] ) ) {
		$errors->add( 'gp_translator_spam', __( '<strong>Error:</strong> Registration failed. Please try again.', 'gp-translator-signup' ) );
	}
	return validate_values( $errors, submitted_values() );
}

/**
 * Saves the sign-up fields for a new user.
 *
 * @param int $user_id
 */
function save_registration( int $user_id ): void {
	save_signup( $user_id, submitted_values() );
}

/**
 * Saves sign-up fields and sets the status: translators are active, reviewers wait for approval.
 *
 * @param int   $user_id
 * @param array $values See submitted_values().
 */
function save_signup( int $user_id, array $values ): void {
	update_user_meta( $user_id, LANGUAGES_META, $values['languages'] );
	update_user_meta( $user_id, OTHER_META, $values['other'] );

	if ( 'reviewer' === $values['role'] ) {
		update_user_meta( $user_id, EXPERIENCE_META, $values['experience'] );
	}
	if ( '' !== $values['applied_from'] ) {
		update_user_meta( $user_id, APPLIED_FROM_META, $values['applied_from'] );
	}

	update_user_meta( $user_id, STATUS_META, 'reviewer' === $values['role'] ? 'pending' : 'translator' );
}

/**
 * Lets an existing translator apply to review, and tells the admin.
 *
 * @param int   $user_id
 * @param array $values See submitted_values().
 */
function apply_to_review( int $user_id, array $values ): void {
	save_signup( $user_id, array_merge( $values, array( 'role' => 'reviewer' ) ) );

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

	wp_mail(
		get_option( 'admin_email' ),
		/* translators: %s: site name */
		sprintf( __( '[%s] New reviewer application', 'gp-translator-signup' ), $blogname ),
		/* translators: %s: username */
		sprintf( __( '%s applied to review translations.', 'gp-translator-signup' ), $user->user_login ) . "\r\n\r\n" . application_summary( $user_id )
	);
}

/**
 * Describes a reviewer application for admin emails.
 *
 * @param int $user_id
 * @return string
 */
function application_summary( int $user_id ): string {
	/* translators: %s: languages */
	$lines = array( sprintf( __( 'Languages: %s', 'gp-translator-signup' ), user_languages_label( $user_id ) ) );

	$applied_from = user_applied_from_label( $user_id );
	if ( '' !== $applied_from ) {
		/* translators: %s: project name */
		$lines[] = sprintf( __( 'Applied from: %s', 'gp-translator-signup' ), $applied_from );
	}

	$experience = (string) get_user_meta( $user_id, EXPERIENCE_META, true );
	if ( '' !== $experience ) {
		/* translators: %s: what the applicant wrote about their experience */
		$lines[] = sprintf( __( "Experience:\r\n%s", 'gp-translator-signup' ), $experience );
	}

	/* translators: %s: URL of the pending reviewers list */
	$lines[] = sprintf( __( 'Review pending reviewers: %s', 'gp-translator-signup' ), admin_url( 'users.php?' . STATUS_META . '=pending' ) );

	return implode( "\r\n", $lines ) . "\r\n";
}

add_filter( 'wp_new_user_notification_email_admin', __NAMESPACE__ . '\\add_signup_to_admin_email', 10, 2 );

/**
 * Adds the languages, and for reviewers the application, to the "New user registration" email.
 *
 * @param array   $email
 * @param WP_User $user
 * @return array
 */
function add_signup_to_admin_email( array $email, WP_User $user ): array {
	$status = user_status( $user->ID );

	if ( 'pending' === $status ) {
		$email['message'] .= "\r\n" . __( 'They applied to review translations.', 'gp-translator-signup' ) . "\r\n" . application_summary( $user->ID );
	} elseif ( 'translator' === $status ) {
		/* translators: %s: languages */
		$email['message'] .= "\r\n" . sprintf( __( 'Signed up to translate: %s', 'gp-translator-signup' ), user_languages_label( $user->ID ) ) . "\r\n";
	}

	return $email;
}

// -----------------------------------------------------------------------------
// GlotPress permissions
// -----------------------------------------------------------------------------

// Before GlotPress's own pre-checks (priority 10), so a blocked translator is not let in by "everyone can translate".
add_filter( 'gp_pre_can_user', __NAMESPACE__ . '\\filter_permissions', 5, 2 );

/**
 * Approved reviewers can approve in their languages on every project; blocked translators cannot suggest.
 *
 * @param string|bool $verdict
 * @param array       $args    See GP_Permission::user_can().
 * @return string|bool
 */
function filter_permissions( $verdict, array $args ) {
	if ( is_bool( $verdict ) || empty( $args['user'] ) || ! $args['user'] instanceof WP_User ) {
		return $verdict;
	}

	$status = user_status( $args['user']->ID );

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
// GlotPress: /join page, header link and project banners
// -----------------------------------------------------------------------------

add_action( 'gp_init', __NAMESPACE__ . '\\register_join_route' );

/**
 * Adds the /join page to GlotPress.
 */
function register_join_route(): void {
	require_once __DIR__ . '/includes/JoinRoute.php';

	GP::$router->prepend( '/join', array( JoinRoute::class, 'join_get' ), 'get' );
	GP::$router->prepend( '/join', array( JoinRoute::class, 'join_post' ), 'post' );
}

/**
 * Returns the URL of the /join page.
 *
 * @param string $project_path Project to come back to.
 * @param string $locale       Locale slug to pre-select.
 * @param array  $extra        More query args.
 * @return string
 */
function join_url( string $project_path = '', string $locale = '', array $extra = array() ): string {
	$args = array_filter(
		array_merge(
			array(
				'project' => $project_path,
				'locale'  => $locale,
			),
			$extra
		)
	);
	return gp_url( '/join', $args ?: null );
}

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
		$items[ esc_url( join_url() ) ] = __( 'Sign up to translate', 'gp-translator-signup' );
	}
	return $items;
}

add_action( 'gp_pre_tmpl_load', __NAMESPACE__ . '\\remember_page_context', 10, 2 );
add_action( 'gp_after_notices', __NAMESPACE__ . '\\render_banner' );

/**
 * Stores or returns the project and translation set of the page being shown.
 *
 * @param array|null $context New context.
 * @return array{project?: \GP_Project|null, set?: \GP_Translation_Set|null, locale?: \GP_Locale|null}
 */
function page_context( ?array $context = null ): array {
	static $current = array();
	if ( null !== $context ) {
		$current = $context;
	}
	return $current;
}

/**
 * Remembers the page when the projects list, a project or a translations page loads.
 *
 * The page template loads the header, so this runs before gp_after_notices.
 *
 * @param string $template
 * @param array  $args
 */
function remember_page_context( string $template, array $args ): void {
	if ( 'projects' === $template ) {
		page_context( array( 'project' => null ) );
		return;
	}
	if ( ! in_array( $template, array( 'project', 'translations' ), true ) || empty( $args['project'] ) ) {
		return;
	}
	// The project page gets GlotPress's get_defined_vars(), so a $locale left over from its loop over sets: ignore it.
	$is_set = 'translations' === $template;
	page_context(
		array(
			'project' => $args['project'],
			'set'     => $is_set ? ( $args['translation_set'] ?? null ) : null,
			'locale'  => $is_set ? ( $args['locale'] ?? null ) : null,
		)
	);
}

/**
 * Invites visitors to sign up, and translators to apply to review, on the projects list, project and translation pages.
 */
function render_banner(): void {
	$context = page_context();
	if ( ! array_key_exists( 'project', $context ) ) {
		return;
	}

	$project = $context['project'];
	$path    = $project ? $project->path : '';
	$set     = $context['set'] ?? null;
	$locale  = $set && isset( available_locales()[ $set->locale ] ) ? $set->locale : '';
	$name    = $context['locale']->english_name ?? '';

	if ( ! is_user_logged_in() ) {
		if ( ! get_option( 'users_can_register' ) ) {
			return;
		}
		if ( ! $project ) {
			$message = __( 'Help translate these projects into your language.', 'gp-translator-signup' );
		} elseif ( $name ) {
			/* translators: 1: language, 2: project name */
			$message = sprintf( __( 'Speak %1$s? Help translate %2$s.', 'gp-translator-signup' ), $name, $project->name );
		} else {
			/* translators: %s: project name */
			$message = sprintf( __( 'Help translate %s.', 'gp-translator-signup' ), $project->name );
		}
		$link = sprintf( '<a href="%s">%s</a>', esc_url( join_url( $path, $locale ) ), esc_html__( 'Sign up as a translator or reviewer', 'gp-translator-signup' ) );
	} else {
		$status = user_status( get_current_user_id() );

		// On a translations page, anyone who can't approve there is invited; elsewhere only sign-up translators are.
		if ( $set ) {
			if ( ! in_array( $status, array( '', 'translator' ), true ) || GP::$permission->current_user_can( 'approve', 'translation-set', $set->id ) ) {
				return;
			}
		} elseif ( 'translator' !== $status ) {
			return;
		}

		if ( ! $project ) {
			$message = __( 'Want to approve translations in your languages?', 'gp-translator-signup' );
		} elseif ( $name ) {
			/* translators: 1: language, 2: project name */
			$message = sprintf( __( 'Want to approve %1$s translations for %2$s?', 'gp-translator-signup' ), $name, $project->name );
		} else {
			/* translators: %s: project name */
			$message = sprintf( __( 'Want to approve translations for %s?', 'gp-translator-signup' ), $project->name );
		}
		$link = sprintf( '<a href="%s">%s</a>', esc_url( join_url( $path, $locale ) ), esc_html__( 'Apply to review', 'gp-translator-signup' ) );
	}

	printf( '<div class="notice gp-translator-signup-banner">%s %s</div>', esc_html( $message ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is escaped above.
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
	$status = user_status( $user_id );
	if ( ! $status ) {
		return '&mdash;';
	}

	$output = sprintf( '<strong>%s</strong><br>%s', esc_html( statuses()[ $status ] ?? $status ), esc_html( user_languages_label( $user_id ) ) );

	if ( 'pending' === $status ) {
		$applied_from = user_applied_from_label( $user_id );
		if ( '' !== $applied_from ) {
			/* translators: %s: project name */
			$output .= '<br>' . esc_html( sprintf( __( 'Applied from: %s', 'gp-translator-signup' ), $applied_from ) );
		}
		$experience = (string) get_user_meta( $user_id, EXPERIENCE_META, true );
		if ( '' !== $experience ) {
			$output .= '<br><em>' . esc_html( wp_trim_words( $experience, 25 ) ) . '</em>';
		}
	}

	return $output;
}

/**
 * Returns the status changes offered for a status, as new status => link label.
 *
 * @param string $status
 * @return array<string, string>
 */
function status_actions( string $status ): array {
	$block = array( 'rejected' => __( 'Block', 'gp-translator-signup' ) );

	switch ( $status ) {
		case 'pending':
			return array(
				'approved'   => __( 'Approve as reviewer', 'gp-translator-signup' ),
				'translator' => __( 'Decline', 'gp-translator-signup' ),
			) + $block;
		case 'approved':
			return array( 'translator' => __( 'Remove reviewer', 'gp-translator-signup' ) ) + $block;
		case 'rejected':
			return array( 'translator' => __( 'Unblock', 'gp-translator-signup' ) );
		default:
			return array( 'approved' => __( 'Make reviewer', 'gp-translator-signup' ) ) + $block;
	}
}

/**
 * Adds status links (approve, decline, block, ...) under translators in the users list.
 *
 * @param array   $actions
 * @param WP_User $user
 * @return array
 */
function add_row_actions( array $actions, WP_User $user ): array {
	$status = user_status( $user->ID );
	if ( ! $status || ! current_user_can( 'promote_user', $user->ID ) ) {
		return $actions;
	}

	foreach ( status_actions( $status ) as $new_status => $label ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=gp_translator_status&user_id=' . $user->ID . '&status=' . $new_status ),
			'gp_translator_status_' . $user->ID
		);
		$actions[ 'gp_translator_' . $new_status ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	return $actions;
}

/**
 * Adds a "Pending reviewers" view above the users list.
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
		esc_html__( 'Pending reviewers', 'gp-translator-signup' ),
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
 * Handles the status links.
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
 * Shows a notice after a status link.
 */
function render_admin_notices(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$status   = isset( $_GET['gp_translator_updated'] ) ? sanitize_key( $_GET['gp_translator_updated'] ) : '';
	$messages = array(
		'approved'   => __( 'Reviewer approved. They can now approve translations in their languages.', 'gp-translator-signup' ),
		'translator' => __( 'Done. They can suggest translations, but not approve them.', 'gp-translator-signup' ),
		'rejected'   => __( 'Translator blocked. They can no longer suggest translations.', 'gp-translator-signup' ),
	);
	if ( isset( $messages[ $status ] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $status ] ) . '</p></div>';
	}
}

/**
 * Changes a translator's status, and emails them when they become a reviewer.
 *
 * @param int    $user_id
 * @param string $status
 */
function set_status( int $user_id, string $status ): void {
	$previous = user_status( $user_id );
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
	$status       = user_status( $user->ID );
	$experience   = (string) get_user_meta( $user->ID, EXPERIENCE_META, true );
	$applied_from = user_applied_from_label( $user->ID );
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
				<p class="description"><?php esc_html_e( 'Translators can suggest translations. Reviewers can also approve translations in their languages on every project. Blocked translators cannot suggest translations.', 'gp-translator-signup' ); ?></p>
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
		<?php if ( '' !== $applied_from ) : ?>
			<tr>
				<th><?php esc_html_e( 'Signed up from', 'gp-translator-signup' ); ?></th>
				<td><?php echo esc_html( $applied_from ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( '' !== $experience ) : ?>
			<tr>
				<th><?php esc_html_e( 'Translation experience', 'gp-translator-signup' ); ?></th>
				<td><?php echo wp_kses_post( wpautop( make_clickable( esc_html( $experience ) ) ) ); ?></td>
			</tr>
		<?php endif; ?>
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

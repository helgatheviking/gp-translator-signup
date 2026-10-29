<?php
/**
 * The /join page: sign up as a translator or reviewer, or apply to review.
 *
 * @package GP_Translator_Signup
 */

namespace HelgaTheViking\GPTranslatorSignup;

defined( 'ABSPATH' ) || exit;

use GP;
use GP_Route;
use WP_Error;

/**
 * GlotPress route for /join.
 */
class JoinRoute extends GP_Route {

	/**
	 * Nonce action for the form.
	 */
	const NONCE = 'gp-translator-join';

	/**
	 * Loads templates from this plugin.
	 */
	public function __construct() {
		parent::__construct();
		$this->template_path = dirname( __DIR__ ) . '/templates/';
	}

	/**
	 * Shows the form, or where the user stands.
	 */
	public function join_get(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page state.
		$project_path = sanitize_project_path( $_GET['project'] ?? '' );
		$locale       = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : '';
		$registered   = ! empty( $_GET['registered'] );
		// phpcs:enable

		$values = array(
			'role'         => 'translator',
			'languages'    => sanitize_languages( array( $locale ) ),
			'other'        => '',
			'experience'   => '',
			'applied_from' => $project_path,
		);

		if ( is_user_logged_in() ) {
			$saved = user_languages( get_current_user_id() );
			if ( $saved ) {
				$values['languages'] = array_values( array_unique( array_merge( $saved, $values['languages'] ) ) );
			}
		}

		$this->render( $values, new WP_Error(), $registered );
	}

	/**
	 * Registers a new translator or reviewer, or takes a logged-in translator's application to review.
	 */
	public function join_post(): void {
		$values = submitted_values();

		if ( $this->invalid_nonce_and_redirect( self::NONCE, join_url( $values['applied_from'] ) ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$this->apply( $values );
			return;
		}

		if ( ! get_option( 'users_can_register' ) ) {
			$this->redirect_with_error( __( 'Sign-ups are closed.', 'gp-translator-signup' ), join_url( $values['applied_from'] ) );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
		// phpcs:enable

		// Runs check_registration() and save_registration() through core's registration hooks, and sends the set-password email.
		$user_id = register_new_user( $login, $email );

		if ( is_wp_error( $user_id ) ) {
			$this->render( $values, $user_id, false, compact( 'login', 'email' ) );
			return;
		}

		$this->redirect( join_url( $values['applied_from'], '', array( 'registered' => 1 ) ) );
	}

	/**
	 * Saves a logged-in translator's application to review.
	 *
	 * @param array $values See submitted_values().
	 */
	private function apply( array $values ): void {
		$user_id = get_current_user_id();

		if ( ! in_array( user_status( $user_id ), array( '', 'translator' ), true ) ) {
			$this->redirect( join_url( $values['applied_from'] ) );
			return;
		}

		$values['role'] = 'reviewer';
		$errors         = validate_values( new WP_Error(), $values );

		if ( $errors->has_errors() ) {
			$this->render( $values, $errors );
			return;
		}

		apply_to_review( $user_id, $values );

		$this->notices[] = __( 'Thanks! We’ll email you when your application to review is approved. In the meantime, you can keep suggesting translations.', 'gp-translator-signup' );
		$this->redirect( join_url( $values['applied_from'] ) );
	}

	/**
	 * Renders the page.
	 *
	 * @param array    $values     See submitted_values().
	 * @param WP_Error $errors     Errors to show above the form.
	 * @param bool     $registered Whether the visitor just signed up.
	 * @param array    $account    Username and email to refill.
	 */
	private function render( array $values, WP_Error $errors, bool $registered = false, array $account = array() ): void {
		$project = '' !== $values['applied_from'] ? GP::$project->by_path( $values['applied_from'] ) : null;

		if ( is_user_logged_in() ) {
			$state = array(
				'pending'  => 'pending',
				'approved' => 'reviewer',
				'rejected' => 'blocked',
			)[ user_status( get_current_user_id() ) ] ?? 'apply';
		} elseif ( $registered ) {
			$state = 'registered';
		} elseif ( get_option( 'users_can_register' ) ) {
			$state = 'register';
		} else {
			$state = 'closed';
		}

		$this->tmpl(
			'join',
			array(
				'state'   => $state,
				'values'  => $values,
				'errors'  => $errors->get_error_messages(),
				'project' => $project ?: null,
				'login'   => $account['login'] ?? '',
				'email'   => $account['email'] ?? '',
			)
		);
	}
}

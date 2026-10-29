<?php
/**
 * The /join page.
 *
 * @package GP_Translator_Signup
 *
 * @var string           $state   register, registered, closed, apply, pending, reviewer or blocked.
 * @var array            $values  See submitted_values().
 * @var string[]         $errors  Error messages (may contain basic HTML).
 * @var \GP_Project|null $project Project the visitor came from.
 * @var string           $login   Username to refill.
 * @var string           $email   Email to refill.
 */

namespace HelgaTheViking\GPTranslatorSignup;

defined( 'ABSPATH' ) || exit;

$heading = 'apply' === $state ? __( 'Apply to review translations', 'gp-translator-signup' ) : __( 'Sign up to translate', 'gp-translator-signup' );

gp_title( sprintf( '%s &lt; GlotPress', esc_html( $heading ) ) );
gp_breadcrumb( array( esc_html( $heading ) ) );
gp_tmpl_header();

$project_link = $project ? gp_link_get( gp_url_project( $project ), $project->name ) : '';
?>

<h2><?php echo esc_html( $heading ); ?></h2>

<?php if ( $project_link ) : ?>
	<?php /* translators: %s: link to the project */ ?>
	<p><?php echo wp_kses_post( sprintf( __( 'Help translate %s.', 'gp-translator-signup' ), $project_link ) ); ?></p>
<?php endif; ?>

<?php if ( $errors ) : ?>
	<div class="error">
		<?php foreach ( $errors as $error ) : ?>
			<p><?php echo wp_kses_post( $error ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( 'registered' === $state ) : ?>

	<p><?php esc_html_e( 'Thanks for signing up! Check your email for a link to set your password, then log in to start translating.', 'gp-translator-signup' ); ?></p>
	<p><?php esc_html_e( 'If you asked to review, we’ll email you once you’re approved. Until then, you can suggest translations.', 'gp-translator-signup' ); ?></p>
	<p><a class="button is-primary" href="<?php echo esc_url( wp_login_url( $project ? gp_url_project( $project ) : gp_url( '/projects' ) ) ); ?>"><?php esc_html_e( 'Log in', 'gp-translator-signup' ); ?></a></p>

<?php elseif ( 'closed' === $state ) : ?>

	<p><?php esc_html_e( 'Sign-ups are closed at the moment.', 'gp-translator-signup' ); ?></p>

<?php elseif ( 'pending' === $state ) : ?>

	<?php /* translators: %s: languages */ ?>
	<p><?php echo esc_html( sprintf( __( 'Your application to review is waiting for approval. Languages: %s.', 'gp-translator-signup' ), user_languages_label( get_current_user_id() ) ) ); ?></p>
	<p><?php esc_html_e( 'You can suggest translations in the meantime.', 'gp-translator-signup' ); ?></p>

<?php elseif ( 'reviewer' === $state ) : ?>

	<?php /* translators: %s: languages */ ?>
	<p><?php echo esc_html( sprintf( __( 'You’re a reviewer for: %s.', 'gp-translator-signup' ), user_languages_label( get_current_user_id() ) ) ); ?></p>
	<p><?php esc_html_e( 'To review another language, contact the site admin.', 'gp-translator-signup' ); ?></p>

<?php elseif ( 'blocked' === $state ) : ?>

	<p><?php esc_html_e( 'Your account can’t suggest translations. Contact the site admin if you think this is a mistake.', 'gp-translator-signup' ); ?></p>

<?php else : ?>

	<form method="post" action="<?php echo esc_url( join_url() ); ?>" style="max-width:40em">
		<?php if ( 'register' === $state ) : ?>
			<p>
				<label for="user_login"><?php esc_html_e( 'Username', 'gp-translator-signup' ); ?></label><br>
				<input type="text" name="user_login" id="user_login" value="<?php echo esc_attr( $login ); ?>" autocomplete="username" required>
			</p>
			<p>
				<label for="user_email"><?php esc_html_e( 'Email', 'gp-translator-signup' ); ?></label><br>
				<input type="email" name="user_email" id="user_email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" required>
			</p>
			<?php render_signup_fields( $values ); ?>
			<?php render_honeypot(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'You can already suggest translations. Reviewers can also approve translations in their languages, on every project. The site admin approves reviewers.', 'gp-translator-signup' ); ?></p>
			<?php render_signup_fields( $values, false ); ?>
		<?php endif; ?>

		<input type="hidden" name="<?php echo esc_attr( APPLIED_FROM_META ); ?>" value="<?php echo esc_attr( $values['applied_from'] ); ?>">
		<?php gp_route_nonce_field( JoinRoute::NONCE ); ?>

		<p>
			<input class="button is-primary" type="submit" value="<?php echo esc_attr( 'register' === $state ? __( 'Sign up', 'gp-translator-signup' ) : __( 'Apply to review', 'gp-translator-signup' ) ); ?>">
		</p>

		<?php if ( 'register' === $state ) : ?>
			<p>
				<?php esc_html_e( 'We’ll email you a link to set your password.', 'gp-translator-signup' ); ?>
				<?php
				printf(
					/* translators: %s: log in link */
					esc_html__( 'Already have an account? %s', 'gp-translator-signup' ),
					'<a href="' . esc_url( wp_login_url( join_url( $values['applied_from'] ) ) ) . '">' . esc_html__( 'Log in', 'gp-translator-signup' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</form>

<?php endif; ?>

<?php
gp_tmpl_footer();

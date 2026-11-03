<?php
/**
 * Plugin Name:     Chalandria One
 * Plugin URI:      https://github.com/thunderrabbit/chalandria-one
 * Description:     Rob's first try at allowing us to play Chalandria from games.chatforest.com in a shortcode
 * Author:          Rob Nugen
 * Author URI:      https://www.robnugen.com/
 * Text Domain:     chalandria-one
 * Domain Path:     /languages
 * Version:         0.3.2
 *
 * @package         Chalandria_One
 */

// Your code starts here.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define(
	'CHALANDRIA_ONE_DEFAULT_BASE_URL',
	'https://games.chatforest.com'
);

function chalandria_one_register_settings()
{

	// Add the section to reading settings so we can add our
	// fields to it
	add_settings_section(
		'chalandria_one_setting_section',
		'Chalandria Settings',
		'chalandria_one_setting_section_callback_function',
		'chatforest_games'
	);

	// Add the field with the names and function to use for our new
	// settings, put it in our new section
	add_settings_field(
		'chalandria_one_base',
		'Which Games server?',
		'chalandria_one_setting_callback_function',
		'chatforest_games',
		'chalandria_one_setting_section'
	);

	// Register our setting so that $_POST handling is done for us and
	// our callback function just has to echo the <input>
	register_setting(
		'chatforest_games',
		'chalandria_one_base',
		array(
			'type' => 'string',
			'default' => CHALANDRIA_ONE_DEFAULT_BASE_URL,
			'sanitize_callback' => 'chatforest_games_sanitize_url_text_field',
		)
	);

}

/**
 * Check URL saved at Settings -> Chatforest Games -> Chalandria
 */
function chatforest_games_sanitize_url_text_field($url_raw)
{
	# Get the default

	$url = sanitize_url($url_raw, ['https']);

	if (!empty($url)) {
		return $url;
	}

	add_settings_error(
		'chalandria_one_base',
		'invalid_base_url',
		"Y'all got any more of that SSL?"
	);

	return sanitize_url(
		CHALANDRIA_ONE_DEFAULT_BASE_URL
	);

}

function chalandria_one_setting_section_callback_function()
{
	// No introductory text needed.
}
function chalandria_one_setting_callback_function() {
	$value = get_option(
		'chalandria_one_base',
		CHALANDRIA_ONE_DEFAULT_BASE_URL
	);

	echo '<input type="url"
		id="chalandria_one_base"
		name="chalandria_one_base"
		placeholder="'. esc_attr(CHALANDRIA_ONE_DEFAULT_BASE_URL) .'"
		value="' . esc_attr($value) . '"
		class="regular-text">';
}


function chatforest_games_add_settings_page() {
	add_options_page(
		'Chatforest Games',                    // Page title.
		'Chatforest Games',                    // Settings menu label.
		'manage_options',                      // Required permission.
		'chatforest',                      // Page identifier.
		'chatforest_games_settings_page'         // Function that displays it.
	);
}
add_action( 'admin_menu', 'chatforest_games_add_settings_page' );

function chatforest_games_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Chatforest Games</h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'chatforest_games' );
			do_settings_sections( 'chatforest_games' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}


add_action('admin_init', 'chalandria_one_register_settings');

function chalandria_one_render() {
	$logged_in = is_user_logged_in();
	$wp_host = wp_parse_url(home_url(),PHP_URL_HOST);
	$gcf_domain = wp_parse_url(get_option('chalandria_one_base'), PHP_URL_HOST);

	$intro = "<hr>";
	$gcf_url = get_option('chalandria_one_base')."/connect/?site=".$wp_host."&game=chalandria";
	$content = '<p>' .
		sprintf(
			/* translators: `%1$s`: Opening link tag, `%2$s`: Closing link tag. */
			esc_html__('%1$sClick here%2$s to authenticate on %3$s.', 'chalandria-one'),
			'<a rel="noopener" href="' . esc_url($gcf_url) . '">',
			'</a>',
			$gcf_domain
		) . '</p>';
	$forment = '<form method="post" action ="' . esc_url(admin_url('admin-post.php')) . '">' .
					'<input type="hidden" name="action" value="chalandria_one_connect_to_games_cf">' .
					'<input type="text" name="code" placeholder="xxxx-xxxx" />' .
					'<br><button type="submit">' . esc_html__( 'Let\'s Play!', 'chalandria-one' ) . '</button>'.
				'</form>';
	$outro = "<hr>";
	if($logged_in) {
		return
			$intro.
			$content.
			$forment.
			$outro;
	} else {
		$login_url =  wp_login_url( get_permalink() );
		return sprintf(
			/* translators: `%1$s`: Opening link tag, `%2$s`: Closing link tag. */
			esc_html__('%1$sLog in%2$s to test Chalandria plugin.', 'chalandria-one'),
			'<a rel="noopener" href="' . esc_url($login_url) . '">',
			'</a>'
		);
	}
}


add_shortcode('this_is_chalandria', 'chalandria_one_render');

# `admin_post_` is prepended to the value of attribute `value` in `input` name="action"
# i.e. search `chalandria_one_connect_to_games_cf` above to see why this is called `admin_post_chalandria_one_connect_to_games_cf` here
add_action(
	'admin_post_chalandria_one_connect_to_games_cf',
	'detected_button_click'
);

function detected_button_click() {
	$games_url = get_option(
		'chalandria_one_base',
		''		// default URL is registered by `register_setting()`
	);
	$games_code_redeem_url = esc_url($games_url . "/connect/api.php?do=redeem");
	$redeem_code_response =
			wp_remote_post(
				$games_code_redeem_url,
					array(
						'body' => array(
							'code' => sanitize_text_field( wp_unslash($_POST['code'])),
							'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
						),
					)
				);
	if (is_wp_error($redeem_code_response)) {
		// URL is wrong or unavailable
		wp_die('Error connecting to ' . esc_url($games_url));
	}

	wp_die("<pre>".print_r($redeem_code_response,true)."</pre>");
}

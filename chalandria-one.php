<?php
/**
 * Plugin Name:     Chalandria One
 * Plugin URI:      https://github.com/thunderrabbit/chalandria-one
 * Description:     Rob's first try at allowing us to play Chalandria from games.chatforest.com in a shortcode
 * Author:          Rob Nugen
 * Author URI:      https://www.robnugen.com/
 * Text Domain:     chalandria-one
 * Domain Path:     /languages
 * Version:         0.1.1
 *
 * @package         Chalandria_One
 */

// Your code starts here.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function chalandria_one_register_settings()
{

	// Add the section to reading settings so we can add our
	// fields to it
	add_settings_section(
		'chalandria_one_setting_section',
		'Chalandria Settings',
		'chalandria_one_setting_section_callback_function',
		'reading'
	);

	// Add the field with the names and function to use for our new
	// settings, put it in our new section
	add_settings_field(
		'chalandria_one_base',
		'Which Games server?',
		'chalandria_one_setting_callback_function',
		'reading',
		'chalandria_one_setting_section'
	);

	// Register our setting so that $_POST handling is done for us and
	// our callback function just has to echo the <input>
	register_setting(
		'reading',
		'chalandria_one_base',
		array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

}

function chalandria_one_setting_section_callback_function()
{
	// No introductory text needed.
}
function chalandria_one_setting_callback_function() {
	$value = get_option(
		'chalandria_one_base',
		'https://games.chatforest.com'
	);

	echo '<input type="url"
		id="chalandria_one_base"
		name="chalandria_one_base"
		value="' . esc_attr($value) . '"
		class="regular-text">';
}
add_action('admin_init', 'chalandria_one_register_settings');

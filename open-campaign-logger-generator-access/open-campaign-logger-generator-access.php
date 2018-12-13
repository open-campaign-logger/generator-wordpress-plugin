<?php
/*
Plugin Name:  CL Generator Access Plugin
Plugin URI:   https://github.com/open-campaign-logger/generator-wordpress-plugin
Description:  Campaign Logger Generator Service available as WordPress plugin
Version:      0.2
Author:       Jochen Linnemann
Author URI:   http://jochenlinnemann.de/
License:      Apache 2.0
License URI:  https://www.apache.org/licenses/LICENSE-2.0
 */

if (!defined('WPINC')) {
    die;
}

add_action('admin_init', 'cl_gen_access_admin_init');
add_action('admin_menu', 'cl_gen_access_admin_menu');
add_action('wp_enqueue_scripts', 'cl_gen_access_enqueue_scripts');
add_action('wp_ajax_cl_gen_ajax_refresh', 'cl_gen_access_ajax_refresh');
add_action('wp_ajax_nopriv_cl_gen_ajax_refresh', 'cl_gen_access_ajax_refresh');
add_action('init', 'cl_gen_access_init');

// ADMIN INIT

function cl_gen_access_admin_init()
{
    $new_id = get_option('new_id');
    $new_json = get_option('new_json');

    $all_ids = json_decode(get_option('all_ids'));
    if ($all_ids == NULL) {
        $all_ids = array();
        add_option('all_ids', json_encode($all_ids), null, 'no');
    }

    if ($new_id && $new_json) {
        array_push($all_ids, $new_id);
        $all_ids = array_unique($all_ids);
        update_option('all_ids', json_encode($all_ids));
        update_option('new_id', NULL);
        update_option('new_json', NULL);

        $new_setting_id = 'cl-gen-' . $new_id;
        if (get_option($new_setting_id)) {
            update_option($new_setting_id, $new_json);
        }
        else {
            add_option($new_setting_id, $new_json, null, 'no');
        }
    }

    add_settings_section('cl_gen_access_settings_section1', 'Register New Generator', 'cl_gen_access_settings_section1_callback', 'cl_gen_access_settings');
    add_settings_section('cl_gen_access_settings_section2', 'Registered Generators', 'cl_gen_access_settings_section2_callback', 'cl_gen_access_settings');

    add_settings_field('new_id', 'Generator ID', 'field1_callback', 'cl_gen_access_settings', 'cl_gen_access_settings_section1', array('id' => 'new_id'));
    add_settings_field('new_json', 'Generator JSON', 'field1_callback', 'cl_gen_access_settings', 'cl_gen_access_settings_section1', array('id' => 'new_json', 'kind' => 'multiline'));
    register_setting('cl_gen_access_settings', 'new_id');
    register_setting('cl_gen_access_settings', 'new_json');

    $remaining_ids = array();
    foreach ($all_ids as $id) {
        $setting_id = 'cl-gen-' . $id;
        $json = get_option($setting_id);
        if ($json == NULL || $json == '') {
            delete_option($setting_id);
            continue;
        }

        add_settings_field($setting_id, 'Generator "' . $id . '"', 'field2_callback', 'cl_gen_access_settings', 'cl_gen_access_settings_section2', array('setting_id' => $setting_id));
        register_setting('cl_gen_access_settings', $setting_id);

        array_push($remaining_ids, $id);
    }

    update_option('all_ids', json_encode($remaining_ids));
}

function cl_gen_access_settings_section1_callback()
{
    echo 'Use letters, numbers, underlines, and dashes for ID; especially don\'t use any whitespace.';
}

function cl_gen_access_settings_section2_callback()
{
    echo 'To remove a generator from the registry simply empty its content.';
}

function field1_callback($arguments)
{
    $id = $arguments['id'];
    $kind = $arguments['kind'];

    if ($kind == 'multiline') {
        echo '<textarea name="' . $id . '" id="' . $id . '">' . get_option($id) . '</textarea>';
    }
    else {
        echo '<input name="' . $id . '" id="' . $id . '" type="text" value="' . get_option($id) . '" />';
    }
}

function field2_callback($arguments)
{
    $setting_id = $arguments['setting_id'];

    echo '<textarea name="' . $setting_id . '" id="' . $setting_id . '">' . get_option($setting_id) . '</textarea>';
}

// ADMIN MENU

function cl_gen_access_admin_menu()
{
    add_submenu_page(
        'options-general.php',
        'CL Generator Access Settings',
        'CL Generator Access',
        'manage_options',
        'cl_gen_access_settings',
        'cl_gen_access_settings_page_html'
    );
}

function cl_gen_access_settings_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    ?>
        <div class="wrap">
            <h1>CL Generator Access Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('cl_gen_access_settings');
                do_settings_sections('cl_gen_access_settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
    <?php

}

// ENQUEUE SCRIPTS

function cl_gen_access_enqueue_scripts()
{
    wp_enqueue_script(
        'ajax-script',
        plugins_url('/jquery.js', __FILE__),
        array('jquery')
    );
    $cl_gen_nonce = wp_create_nonce('cl_gen_nonce');
    wp_localize_script('ajax-script', 'cl_gen_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => $cl_gen_nonce,
    ));
}

// AJAX REFRESH

function cl_gen_access_ajax_refresh()
{
    check_ajax_referer('cl_gen_nonce');

    $id = $_POST['id'];
    wp_send_json(cl_gen_access_generate_with_id($id));

    wp_die();
}

// INIT

function cl_gen_access_init()
{
    add_shortcode('cl-gen', 'cl_gen_access_shortcode');
}

function cl_gen_access_shortcode($attr)
{
    $id = $attr['id'];

    return '<div>[<a href="javascript:clGenUpdateResult(\'' . $id . '\');">update</a>] <div id="cl-gen-id-' . $id . '">' . cl_gen_access_generate_with_id($id) . '</div></div>';
}

function cl_gen_access_generate_with_id($id)
{
    $setting_id = 'cl-gen-' . $id;
    $json = get_option($setting_id);

    return cl_gen_access_generate($json);
}

function cl_gen_access_generate($generatorJson)
{
    $response = wp_remote_post("https://generator.campaign-logger.com", array(
        'method' => 'POST',
        'timeout' => 45,
        'redirection' => 5,
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => array(),
        'body' => $generatorJson,
        'cookies' => array()
    ));

    $resultText = '';
    if (is_wp_error($response)) {
        $resultText = 'An error occurred calling the CL generator service';
    }
    else {
        $json = $response['body'];
        $data = json_decode($json);
        $resultText = $data->htmlResult;
    }

    return $resultText;
}

?>

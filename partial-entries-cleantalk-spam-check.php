<?php
/*
Plugin Name: Gravity Forms Partial Entries CleanTalk Spam Check
Description: Trigger CleanTalk spam checks on partial Gravity Forms entries, focusing on email fields, and move spam entries to the Spam status with a CleanTalk payload note.
Version: 1.3
Author: Torn Digital
Author URI: https://torndigital.com
Plugin URI: https://github.com/TornMarketing/gf-partial-entries-cleantalk-spam-check/
License: GPL2
*/

// Hook into partial entries save
add_filter('gform_partialentries_post_save', function($entry, $form) {
    // Loop through all fields in the form
    foreach ($form['fields'] as $field) {
        // Check if the field is of type 'email' or has an admin label "email"
        if ($field->type === 'email' || strtolower($field->adminLabel) === 'email') {
            $field_id = $field->id; // Get the field ID
            $email = rgar($entry, 'input_' . $field_id); // Get the value of the field

            // If an email address is present, check it with CleanTalk
            if ($email) {
                $cleantalk_response = gf_cleantalk_check_email($email);

                if ($cleantalk_response['is_spam']) {
                    // Move the entry to spam
                    GFAPI::update_entry_property($entry['id'], 'status', 'spam');

                    // Add a note with the CleanTalk payload
                    GFAPI::add_note(
                        $entry['id'],
                        0, // User ID 0 indicates a system-generated note
                        'CleanTalk',
                        sprintf(
                            "Spam detected for email %s. CleanTalk response: %s",
                            $email,
                            json_encode($cleantalk_response['payload'], JSON_PRETTY_PRINT)
                        )
                    );

                    error_log("Spam detected and entry marked as spam for email: $email");
                    break; // Stop further processing for this entry
                }
            }
        }
    }

    return $entry; // Return the (potentially modified) entry
}, 10, 2);

// Function to trigger CleanTalk API check
function gf_cleantalk_check_email($email) {
    $api_key = gf_get_cleantalk_api_key();

    if (!$api_key) {
        error_log('CleanTalk API Key not found or CleanTalk plugin is not configured.');
        return ['is_spam' => false, 'payload' => []]; // Skip the check if no API key is available
    }

    // Prepare the payload for the API request
    $payload = [
        'method_name' => 'check_message',
        'auth_key' => $api_key,
        'message' => '',
        'sender_email' => $email,
        'sender_ip' => $_SERVER['REMOTE_ADDR'],
    ];

    // Send a request to the CleanTalk API
    $response = wp_remote_post('https://moderate.cleantalk.org/api2.0', [
        'body' => json_encode($payload),
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ]);

    // Handle errors and parse the response
    if (is_wp_error($response)) {
        error_log('CleanTalk API error: ' . $response->get_error_message());
        return ['is_spam' => false, 'payload' => $payload];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    $is_spam = isset($body['allow']) && $body['allow'] == 0;

    return [
        'is_spam' => $is_spam,
        'payload' => $payload,
    ]; // Return spam status and the API request payload
}

// Function to retrieve the CleanTalk API key from WordPress options
function gf_get_cleantalk_api_key() {
    $cleantalk_settings = get_option('cleantalk_settings');

    if (!empty($cleantalk_settings['apikey'])) {
        return $cleantalk_settings['apikey'];
    } else {
        return false; // Return false if the API key is not found
    }
}

// Admin notice if Gravity Forms or Partial Entries plugin is not active
add_action('admin_notices', function() {
    if (!class_exists('GFAPI') || !is_plugin_active('gravityforms-partial-entries/gravityforms-partial-entries.php')) {
        echo '<div class="notice notice-warning is-dismissible">
            <p><strong>Gravity Forms Partial Entries CleanTalk Spam Check:</strong> Please ensure Gravity Forms and the Partial Entries add-on are activated for this plugin to function correctly.</p>
        </div>';
    }
});

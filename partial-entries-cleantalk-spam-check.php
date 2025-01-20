<?php
/*
Plugin Name: Gravity Forms Partial Entries CleanTalk Spam Check
Description: Trigger CleanTalk spam checks on partial Gravity Forms entries, focusing on email fields, and move spam entries to the Spam status with detailed auditing.
Version: 1.6
Author: Torn Digital
Author URI: https://torndigital.com
Plugin URI: https://torndigital.com
License: GPL2
*/

// Hook into partial entries save
add_filter('gform_partialentries_post_save', function($entry, $form) {
    // Check if the entry has a valid ID
    if (empty($entry['id'])) {
        error_log("Partial Entry Error: Entry ID is missing or invalid.");
        return $entry;
    }

    // Loop through all fields in the form
    foreach ($form['fields'] as $field) {
        // Check if the field is of type 'email' or has an admin label "email"
        if ($field->type === 'email' || strtolower($field->adminLabel) === 'email') {
            $field_id = $field->id; // Get the field ID
            $email = rgar($entry, 'input_' . $field_id); // Get the value of the field

            // If an email address is present, check it with CleanTalk
            if ($email) {
                $cleantalk_response = gf_cleantalk_check_email($email);

                // Attempt to log the CleanTalk response as a note
                $note_result = gf_add_partial_entry_note($entry['id'], sprintf(
                    "CleanTalk API Query for email %s: %s",
                    $email,
                    json_encode($cleantalk_response, JSON_PRETTY_PRINT)
                ));

                if (is_wp_error($note_result)) {
                    error_log("Error adding note to partial entry {$entry['id']}: " . $note_result->get_error_message());
                }

                // If the email is marked as spam, move the entry to spam
                if ($cleantalk_response['is_spam']) {
                    $update_status = GFAPI::update_entry_property($entry['id'], 'status', 'spam');
                    if (is_wp_error($update_status)) {
                        error_log("Error moving partial entry {$entry['id']} to spam: " . $update_status->get_error_message());
                    } else {
                        error_log("Spam detected and partial entry {$entry['id']} marked as spam for email: $email");
                    }
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
        $error_message = 'CleanTalk API Key not found or CleanTalk plugin is not configured.';
        error_log($error_message);

        return [
            'is_spam' => false,
            'error' => $error_message,
            'payload' => [],
        ]; // Return an error response
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
        $error_message = 'CleanTalk API error: ' . $response->get_error_message();
        error_log($error_message);

        return [
            'is_spam' => false,
            'error' => $error_message,
            'payload' => $payload,
        ]; // Return an error response
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    $is_spam = isset($body['allow']) && $body['allow'] == 0;

    return [
        'is_spam' => $is_spam,
        'api_response' => $body,
        'payload' => $payload,
    ]; // Return spam status, API response, and the payload
}

// Function to add a note to a partial entry
function gf_add_partial_entry_note($entry_id, $note_content) {
    if (method_exists('GFAPI', 'add_note')) {
        return GFAPI::add_note(
            $entry_id,
            0, // User ID 0 indicates a system-generated note
            'CleanTalk',
            $note_content
        );
    } else {
        // Fallback: Log to the error log
        error_log("Failed to add note to partial entry $entry_id. GFAPI::add_note not available.");
        return new WP_Error('add_note_not_available', 'GFAPI::add_note is not supported for partial entries.');
    }
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

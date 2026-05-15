<?php
if (!defined('ABSPATH')) {
    exit;
}

function wcusage_data_import_export_tables() {
    if (wcu_fs()->can_use_premium_code()) {
        $tables = [
            'Activity' => 'wcusage_activity',
            'Campaigns' => 'wcusage_campaigns',
            'Clicks' => 'wcusage_clicks',
            'Direct Links' => 'wcusage_directlinks',
            'MLA Invites' => 'wcusage_mlainvites',
            'Payouts' => 'wcusage_payouts',
        ];
    } else {
        $tables = [
            'Activity' => 'wcusage_activity',
            'Clicks' => 'wcusage_clicks',
        ];
    }
    return $tables;
}

function wcusage_data_import_export_table_installers() {
    return array(
        'wcusage_activity' => array(
            'installer' => 'wcusage_install_activity_tables',
            'version_option' => 'wcusage_activity_db_version',
        ),
        'wcusage_campaigns' => array(
            'installer' => 'wcusage_install_campaigns_tables',
            'version_option' => 'wcusage_campaigns_db_version',
        ),
        'wcusage_clicks' => array(
            'installer' => 'wcusage_install_clicks_tables',
            'version_option' => 'wcusage_clicks_db_version',
        ),
        'wcusage_directlinks' => array(
            'installer' => 'wcusage_install_directlinks_tables',
            'version_option' => 'wcusage_directlinks_db_version',
        ),
        'wcusage_mlainvites' => array(
            'installer' => 'wcusage_install_mlainvites_tables',
            'version_option' => 'wcusage_mlainvites_db_version',
        ),
        'wcusage_payouts' => array(
            'installer' => 'wcusage_install_payouts_tables',
            'version_option' => 'wcusage_db_version',
        ),
    );
}

function wcusage_data_import_export_table_exists($table) {
    global $wpdb;

    $table_name = $wpdb->prefix . $table;

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
}

function wcusage_data_import_export_ensure_table($table) {
    if (wcusage_data_import_export_table_exists($table)) {
        return true;
    }

    $installers = wcusage_data_import_export_table_installers();

    if (!isset($installers[$table]) || !function_exists($installers[$table]['installer'])) {
        return false;
    }

    if (!empty($installers[$table]['version_option'])) {
        delete_option($installers[$table]['version_option']);
    }

    call_user_func($installers[$table]['installer']);

    return wcusage_data_import_export_table_exists($table);
}

function wcusage_data_import_export_get_table_columns($table) {
    global $wpdb;

    if (!wcusage_data_import_export_ensure_table($table)) {
        return array();
    }

    $table_name = $wpdb->prefix . $table;

    return $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function wcusage_data_import_export_normalize_csv_headers($headers) {
    if (!is_array($headers)) {
        return array();
    }

    $headers = array_map(function($header) {
        $header = is_string($header) ? $header : (string) $header;
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        return trim($header);
    }, $headers);

    while (!empty($headers) && end($headers) === '') {
        array_pop($headers);
    }

    return array_values($headers);
}

function wcusage_data_import_export_header_error($headers, $expected_headers) {
    $expected = !empty($expected_headers) ? implode(', ', $expected_headers) : 'none';
    $received = !empty($headers) ? implode(', ', $headers) : 'none';

    wp_die(
        sprintf(
            esc_html__('Error: The CSV file does not have the expected headers. Expected: %1$s. Received: %2$s.', 'woo-coupon-usage'),
            esc_html($expected),
            esc_html($received)
        ),
        'Error',
        array( 'response' => 500, 'back_link' => true )
    );
}

function wcusage_data_import_export_page() {

    // Check if user is administrator
    if ( ! wcusage_check_admin_access() ) {
        wp_die('Error: Permission denied.');
    }

    global $wpdb;

    $tables = wcusage_data_import_export_tables();

    ?>

    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

    <?php

    echo '<div class="wrap admin-tools">';
    do_action( 'wcusage_hook_dashboard_page_header', '');
    echo '<div class="wcusage-tools">';
    echo '<h1>Import/Export Database Tables</h1>';
    echo '<p>Use this tool to import or export the custom database tables for the plugin.</p>';
    echo '<p>When importing the CSV file, this will overwrite the whole database table with the new data.</p>';
    echo '<p>Please be cautious and <strong>make sure to take backups</strong> before importing.</p>';

    foreach ($tables as $key => $table) {
        echo '<div class="import-export-container">';
        echo '<h2 style="margin: 0;">' . esc_html($key) . ' (' . esc_html($table) . ')';
        echo ' <button class="button button-secondary toggle-content" style="margin-left: 10px; margin-top: -5px; float: right;">Show</button>';
        echo '</h2>';
        echo '<div class="content" style="display: none;">'; // Initial display is set to none to hide the content

        // Export
        $nonce_export = wp_create_nonce('export-nonce-' . $table);
        echo '<p style="font-weight: bold;">Export:</p><a href="' . esc_url(add_query_arg(['table' => $table, 'export' => '1', 'nonce' => $nonce_export], esc_url(admin_url()))) . '" class="button button-primary">Export CSV</a>';

        // Import
        echo '<p style="font-weight: bold;">Import:</p><form method="post" enctype="multipart/form-data" class="import-form">';
        echo '<input type="file" name="import_file" id="import_file" />';
        echo '<input type="hidden" name="table" value="' . esc_attr($table) . '">';
        wp_nonce_field('import-nonce-' . $table, 'import_nonce');
        echo '<p style="margin-bottom: 0;"><input type="submit" name="submit" id="submit" class="button button-primary" value="Import CSV"></p>';
        echo '</form>';
        echo '</div>'; // .content
        echo '</div>'; // .import-export-container
    }
    
    echo '</div>';
    echo '</div>';
    ?>

    <br/><br/>
    
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_tools')); ?>">Go back to tools ></a></p>

    <?php
    // Add some basic inline CSS for the admin page
    echo "
    <style>
        .import-export-container {
            max-width: 400px;
            background: #fff;
            padding: 20px 0;
            margin-top: 20px;
            border-radius: 5px;
        }
        .import-form {
            margin-top: 10px;
        }
        .import-form input[type='file'] {
            margin-right: 20px;
        }
    </style>
    ";

    // Add jQuery code to handle show/hide of content
    echo "
    <script>
    jQuery(document).ready(function($) {
        $('.toggle-content').on('click', function(e) {
            e.preventDefault();
            $(this).parent().next('.content').slideToggle();
            $(this).text(function(i, text){
                return text === 'Show' ? 'Hide' : 'Show';
            })
        });
    });
    </script>
    ";
}

add_action('admin_init', 'wcusage_handle_export_import');
function wcusage_handle_export_import() {

    global $wpdb;

    $tables = wcusage_data_import_export_tables();

    $export_table = isset($_GET['table']) ? sanitize_key(wp_unslash($_GET['table'])) : '';
    $export_nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
    
    if (isset($_GET['export']) && $export_table && $export_nonce && wp_verify_nonce($export_nonce, 'export-nonce-' . $export_table)) {

        // Check if user is administrator
        if ( ! wcusage_check_admin_access() ) {
            wp_die('Error: Permission denied. Failed to export data.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // Check if table in array
        $table = $export_table;
        if (!in_array($table, $tables, true)) {
            wp_die('Error: Failed to find the table ' . esc_html($table), 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        $columns = wcusage_data_import_export_get_table_columns($table);
        if (empty($columns)) {
            wp_die('Error: Failed to prepare the table schema for export.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // Check File
        $filename = sanitize_file_name($table . '.csv');
        $table_name = $wpdb->prefix . $table;

        $data = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        if (!empty($wpdb->last_error)) {
            wp_die('Error: Failed to export data. ' . esc_html($wpdb->last_error), 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename=' . $filename);

        $fp = fopen('php://output', 'w');
        fputcsv($fp, $columns);

        if (!empty($data)) {
            foreach ($data as $row) {
                $row = array_intersect_key($row, array_flip($columns));
                $row = array_merge(array_fill_keys($columns, ''), $row);
                fputcsv($fp, $row);
            }
        }

        fclose($fp);
        exit;
    }

    $import_table = isset($_POST['table']) ? sanitize_key(wp_unslash($_POST['table'])) : '';
    $import_nonce = isset($_POST['import_nonce']) ? sanitize_text_field(wp_unslash($_POST['import_nonce'])) : '';

    if ($import_table && isset($_FILES['import_file']) && wp_verify_nonce($import_nonce, 'import-nonce-' . $import_table)) {
        
        // Check if user is administrator
        if ( ! wcusage_check_admin_access() ) {
            wp_die('Error: Permission denied. Failed to import data.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // Check if table in array
        $table = $import_table;
        if (!in_array($table, $tables, true)) {
            wp_die(sprintf(esc_html__('Error: Failed to find the table %s', 'woo-coupon-usage'), esc_html($table)), 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // File
        $file = $_FILES['import_file'];

        if($file['error'] > 0) {
            wp_die('Error: ' . esc_html($file['error']), 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        if(empty($file['tmp_name'])) {
            wp_die('Error: Please upload a file.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // Check file
        $allowed_file_types = array('csv'); // Allowed file extensions
        $allowed_mime_types = array('text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel'); // Allowed MIME types for CSV
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_mime_type = mime_content_type($file['tmp_name']);
        if (!in_array(strtolower($file_extension), $allowed_file_types) || !in_array($file_mime_type, $allowed_mime_types)) {
            wp_die('Error: Invalid file type. Only CSV files are allowed.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        $expected_headers = wcusage_data_import_export_get_table_columns($table);
        if (empty($expected_headers)) {
            wp_die('Error: Failed to prepare the database table for import.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        // File Content Checks
        $headers = array();
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $headers = wcusage_data_import_export_normalize_csv_headers(fgetcsv($handle, 0, ","));
            if ($headers !== $expected_headers) {
                wcusage_data_import_export_header_error($headers, $expected_headers);
            }
        } else {
            wp_die('Error: Failed to open the file.', 'Error',  array( 'response' => 500, 'back_link' => true ));
        }

        $table_name = $wpdb->prefix . $table;
        $wpdb->query("TRUNCATE TABLE `{$table_name}`"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {

            if (count($data) === 1 && (is_null($data[0]) || trim((string) $data[0]) === '')) {
                continue;
            }

            $headers_count = count($headers);
            $data_count = count($data);

            if ($data_count > $headers_count) {
                $extra_values = array_slice($data, $headers_count);
                $extra_values = array_filter($extra_values, function($value) {
                    return trim((string) $value) !== '';
                });

                if (!empty($extra_values)) {
                    error_log('CSV row skipped for table ' . esc_html($table) . ': row has more values than headers.');
                    continue;
                }

                $data = array_slice($data, 0, $headers_count);
            } elseif ($data_count < $headers_count) {
                $data = array_pad($data, $headers_count, '');
            }

            $insert_data = array_combine($headers, $data);
            if (!$insert_data) {
                error_log('CSV row skipped for table ' . esc_html($table) . ': row could not be matched to headers.');
                continue;
            }

            // Sanitize each column before inserting
            foreach ($insert_data as $column => $value) {
                $sanitized_value = sanitize_text_field($value);
                $insert_data[$column] = $sanitized_value;
            }

            $datetime_columns = ['date', 'datepaid', 'dateaccepted', 'datecreated']; // Replace these with your datetime column names
            foreach ($datetime_columns as $datetime_column) {
                if (isset($insert_data[$datetime_column])) {
                    $formatted_date = date_create_from_format('d/m/Y H:i', trim($insert_data[$datetime_column])); // Note the change in format
                    if($formatted_date) {
                        $insert_data[$datetime_column] = $formatted_date->format('Y-m-d H:i:s');
                    } else {
                        error_log('Error: Incorrect date format for ' . $datetime_column . '. The date was: ' . $insert_data[$datetime_column]);
                    }
                }
            }

            $result = $wpdb->insert($wpdb->prefix . $table, $insert_data);
            if ($result === false) {
                error_log('DB Insert Error for table ' . $wpdb->prefix . esc_html($table) . ': ' . $wpdb->last_error);
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Import completed for table: '.esc_html($table).'</p></div>';

        fclose($handle);
    }
}

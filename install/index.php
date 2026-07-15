<?php

// Include the installer settings
include_once 'install/includes/install_settings.php';


require_once '../users/includes/user_spice_ver.php';

// Helper functions
function redirect($location = null)
{
    if ($location) {
        if (!headers_sent()) {
            header('Location: ' . $location);
            exit();
        } else {
            echo '<script type="text/javascript">';
            echo 'window.location.href="' . $location . '";';
            echo '</script>';
            echo '<noscript>';
            echo '<meta http-equiv="refresh" content="0;url=' . $location . '" />';
            echo '</noscript>';
            exit;
        }
    }
}

function randomString($len)
{
    $len = $len++;
    $string = "";
    $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    for ($i = 0; $i < $len; $i++)
        $string .= substr($chars, rand(0, strlen($chars) - 1), 1);
    return $string;
}

function rrmdir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object))
                    rrmdir($dir . "/" . $object);
                else
                    unlink($dir . "/" . $object);
            }
        }
        rmdir($dir);
    }
}

/**
 * Since PHP 8.1 mysqli throws on error by default, but it still returns false when the
 * driver's report mode has been switched off. Normalising both into "false, plus a
 * collected message" is what lets the installer survive a failing statement instead of
 * dying on the first one with an uncaught mysqli_sql_exception.
 */
function tryQuery($link, $sql, array &$errors)
{
    try {
        $result = mysqli_query($link, $sql);
        if ($result === false) {
            $errors[] = mysqli_error($link);
        }
        return $result;
    } catch (mysqli_sql_exception $e) {
        $errors[] = $e->getMessage();
        return false;
    }
}

/**
 * Same normalisation for connecting: mysqli_connect throws rather than returning false.
 */
function tryConnect($dbh, $dbu, $dbp, $dbn, $port, &$error)
{
    try {
        return mysqli_connect($dbh, $dbu, $dbp, $dbn, $port);
    } catch (mysqli_sql_exception $e) {
        $error = $e->getMessage();
        return false;
    }
}

/**
 * Runs sql.sql one statement at a time and returns the MySQL errors it hit, so a
 * partly-applied import can be reported as such instead of as a bad password.
 */
function importSchema($link, $sqlfile)
{
    $templine = '';
    $sqlErrors = [];

    foreach (file($sqlfile) as $line) {
        if (substr($line, 0, 2) == '--' || $line == '')
            continue;

        $templine .= $line;
        if (substr(trim($line), -1, 1) == ';') {
            tryQuery($link, $templine, $sqlErrors);
            $templine = '';
        }
    }

    return $sqlErrors;
}

/**
 * sql.sql is the source of truth for which tables belong to UserSpice and how many
 * rows a clean import leaves in each. Parsing it keeps both lists from drifting.
 */
function schemaTables($sqlfile)
{
    preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`([^`]+)`/i', file_get_contents($sqlfile), $m);
    return $m[1];
}

function schemaSeedCounts($sqlfile)
{
    $counts = [];
    preg_match_all('/INSERT INTO\s+`([^`]+)`.*?;\s*$/ims', file_get_contents($sqlfile), $inserts, PREG_SET_ORDER);

    foreach ($inserts as $insert) {
        // Every VALUES tuple in sql.sql begins its own line.
        $counts[$insert[1]] = ($counts[$insert[1]] ?? 0) + preg_match_all('/^\(/m', $insert[0]);
    }

    return $counts;
}

/**
 * No other application ships these names, so seeing one proves a UserSpice import
 * ran against this database. The rest of the schema uses names generic enough
 * (logs, settings, email, pages) to collide with somebody else's tables.
 */
function hasUserSpiceFingerprint(array $tables)
{
    foreach ($tables as $table) {
        if (strpos($table, 'us_') === 0 || $table === 'user_permission_matches' || $table === 'permission_page_matches') {
            return true;
        }
    }
    return false;
}

function existingSchemaTables($link, array $schemaTables)
{
    $errors = [];
    $present = [];

    if ($res = tryQuery($link, 'SHOW TABLES', $errors)) {
        while ($row = mysqli_fetch_row($res)) {
            $present[] = $row[0];
        }
    }
    // Keep schema order so the manifest reads the same way every time.
    return array_values(array_intersect($schemaTables, $present));
}

function tableRowCounts($link, array $tables)
{
    $errors = [];
    $counts = [];

    foreach ($tables as $table) {
        $res = tryQuery($link, "SELECT COUNT(*) FROM `" . $table . "`", $errors);
        $counts[$table] = $res ? (int) mysqli_fetch_row($res)[0] : -1; // -1 means unreadable
    }
    return $counts;
}

/**
 * Every table sql.sql touches is InnoDB, which applies each multi-row INSERT
 * atomically. A failed import therefore leaves each table it reached either fully
 * seeded or completely empty -- no count in between is reachable. Anything else was
 * written by something other than this installer: another application whose table
 * shares one of our generic names (logs, settings, email, pages), or a live
 * UserSpice site that a fresh deploy has been pointed at. Either way, do not drop it.
 */
function rowCountIsUnexpected($rows, $seed)
{
    return $rows < 0 || ($rows !== 0 && $rows !== $seed);
}

function resetBlockers(array $counts, array $expected)
{
    $blockers = [];

    foreach ($counts as $table => $rows) {
        $seed = $expected[$table] ?? 0;

        if ($rows < 0) {
            $blockers[] = "Could not read the row count for <code>" . htmlspecialchars($table) . "</code>.";
        } elseif (rowCountIsUnexpected($rows, $seed)) {
            $blockers[] = "<code>" . htmlspecialchars($table) . "</code> holds " . number_format($rows)
                . " rows, but a clean import leaves " . number_format($seed) . ".";
        }
    }

    return $blockers;
}

/**
 * Describes what a reset would drop: every UserSpice table present, its row count,
 * and the count a clean import would have produced. A foreign table sharing one of
 * our generic names shows up here as an unexplained row count, which is the only
 * thing that catches a collision no name-matching rule can.
 */
function buildResetManifest($link, $sqlfile)
{
    $existing = existingSchemaTables($link, schemaTables($sqlfile));
    $counts   = tableRowCounts($link, $existing);
    $expected = schemaSeedCounts($sqlfile);
    $blockers = resetBlockers($counts, $expected);

    $tables = [];
    foreach ($existing as $table) {
        $seed = $expected[$table] ?? 0;
        $tables[$table] = [
            'actual'     => $counts[$table],
            'expected'   => $seed,
            'unexpected' => rowCountIsUnexpected($counts[$table], $seed),
        ];
    }

    return [
        'tables'   => $tables,
        'blockers' => $blockers,
        'offer'    => $existing && hasUserSpiceFingerprint($existing) && !$blockers,
    ];
}

/**
 * The shipped users/init.php carries no credentials; finalize writes them. Their
 * presence is therefore proof that an install already completed here.
 */
function installIsComplete($config_file)
{
    return is_file($config_file) && strpos(file_get_contents($config_file), "'host'") !== false;
}

// Get current step
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

// Initialize variables
$isPost = !empty($_POST);
$errors = 0;
$phpWarn = 0;
$go = 0;
$success = false;
$dbError = '';
$needCreateDb = false;
$dbConnectionPartial = false;
$missingFields = false;
$adminError = '';
$importFailed = false;
$resetManifest = null;
$resetRefused = '';

// An install that already wrote its credentials must not be re-run: finalizing a
// second time would overwrite them, and step 2 is reachable by anyone until the
// install folder is deleted. Step 3 stays open because finalize redirects into it.
if (installIsComplete($config_file) && $step != 3) {
    $step = 99;
}

// Step 2 form processing
if ($step != 99 && (isset($_POST['test']) || isset($_POST['submit']) || isset($_POST['tryToCreate']) || isset($_POST['resetDb']))) {
    // Make sure we have all required fields
    if (empty($_POST['dbh']) || empty($_POST['dbu']) || empty($_POST['dbn']) || empty($_POST['port']) || empty($_POST['timezone'])) {
        $missingFields = true;
    } else {
        /**
         * SANITIZATION LAYER
         * We strip single quotes and backslashes from structural fields to prevent 
         * breaking out of the 'key' => 'value' array syntax in the config file.
         */
        $dbh  = str_replace(["'", "\\"], "", $_POST['dbh']);
        $dbu  = str_replace(["'", "\\"], "", $_POST['dbu']);
        $dbn  = str_replace(["'", "\\"], "", $_POST['dbn']);
        $port = intval($_POST['port']); // Force integer to kill string injection
        $tz   = str_replace(["'", "\\"], "", $_POST['timezone']);

        // Password: keep raw version for DB connections, escaped version for config file
        $dbp_raw = $_POST['dbp'] ?? '';
        // Escape for single-quoted PHP string context (order matters: backslashes first)
        $dbp_escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $dbp_raw);

        if (isset($_POST['submit'])) {
            if (empty($_POST['admin_email']) || !filter_var($_POST['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $adminError = "Please provide a valid admin email address.";
            } elseif (empty($_POST['admin_password']) || empty($_POST['admin_confirm_password'])) {
                $adminError = "Please provide and confirm an admin password.";
            } elseif ($_POST['admin_password'] !== $_POST['admin_confirm_password']) {
                $adminError = "Admin passwords do not match.";
            } elseif (strlen($_POST['admin_password']) < 8) {
                $adminError = "Admin password must be at least 8 characters.";
            }
        }

        if (isset($_POST['submit']) && $adminError !== '') {
            // The tables were imported by the previous step, but the config file has
            // not been touched yet, so re-render the admin form to be corrected.
            $success = true;
            $go = 1;
        } elseif (isset($_POST['submit'])) {
            // Rebuild the config from the shipped stub on every finalize. Appending to
            // whatever is already there would write a second set of credentials onto a
            // half-written file if this step ever runs twice.
            $end = "',";

            $config = file_get_contents("install/chunks/chunk0.php")
                . "'host'         => '" . $dbh . $end . PHP_EOL
                . "'username'     => '" . $dbu . $end . PHP_EOL
                . "'password'     => '" . $dbp_escaped . $end . PHP_EOL
                . "'db'           => '" . $dbn . $end . PHP_EOL
                . "'port'         => '" . $port . $end . PHP_EOL
                . file_get_contents("install/chunks/chunk1.php")
                . '$timezone_string = \'' . $tz . "';" . PHP_EOL
                . file_get_contents("install/chunks/chunk2.php");

            file_put_contents($config_file, $config);

            // Prepare admin data
            $admin_username = !empty($_POST['admin_username']) ? $_POST['admin_username'] : 'admin';
            $admin_email = $_POST['admin_email'];
            $admin_fname = !empty($_POST['admin_fname']) ? $_POST['admin_fname'] : '';
            $admin_lname = !empty($_POST['admin_lname']) ? $_POST['admin_lname'] : '';

            // Update the admin account in the database
            try {
                // Create connection (use raw password for actual DB connection)
                $link = mysqli_connect($dbh, $dbu, $dbp_raw, $dbn, $port);

                if ($link) {
                    // Load Input class so password is sanitized the same way as login
                    if (!class_exists('Input')) {
                        require_once __DIR__ . '/../users/classes/Input.php';
                    }
                    $admin_pass = Input::sanitize($_POST['admin_password']);
                    $password_hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 14]);

                    $jd = date('Y-m-d H:i:s');
                    $update_query = "UPDATE users SET
                        username = ?,
                        email = ?,
                        `password` = ?,
                        fname = ?,
                        lname = ?,
                        join_date = ?,
                        force_pr = 0
                        WHERE id = 1";

                    $stmt = mysqli_prepare($link, $update_query);
                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssssss", // one for each bound variable
                        $admin_username,
                        $admin_email,
                        $password_hash,
                        $admin_fname,
                        $admin_lname,
                        $jd
                    );

                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    mysqli_close($link);
                }
            } catch (Exception $e) {
                $dbError = "Error updating admin account: " . $e->getMessage();
            }

            redirect("index.php?step=3");
        } elseif (isset($_POST['resetDb'])) {
            // Clear the tables a failed import left behind, then import again. The
            // manifest the user agreed to is re-derived here; the posted form is
            // never trusted to say what is safe to drop.
            $connectError = '';
            $link = tryConnect($dbh, $dbu, $dbp_raw, $dbn, $port, $connectError);

            if (!$link) {
                $dbConnectionPartial = false;
                $dbError = "Server connection failed: " . $connectError;
            } else {
                $dbConnectionPartial = true;
                $resetManifest = buildResetManifest($link, $sqlfile);

                if (!$resetManifest['offer']) {
                    $importFailed = true;
                    $resetRefused = "The database no longer looks safe to clear, so nothing was dropped.";
                } else {
                    $dropErrors = [];
                    tryQuery($link, 'SET FOREIGN_KEY_CHECKS = 0', $dropErrors);
                    foreach (array_keys($resetManifest['tables']) as $table) {
                        tryQuery($link, "DROP TABLE IF EXISTS `" . $table . "`", $dropErrors);
                    }
                    tryQuery($link, 'SET FOREIGN_KEY_CHECKS = 1', $dropErrors);

                    if (!empty($dropErrors)) {
                        $importFailed = true;
                        $dbError = implode("; ", $dropErrors);
                        $resetManifest = buildResetManifest($link, $sqlfile);
                    } else {
                        $sqlErrors = importSchema($link, $sqlfile);

                        if (empty($sqlErrors)) {
                            $success = true;
                            $go = 1;
                        } else {
                            $importFailed = true;
                            $dbError = implode("; ", $sqlErrors);
                            $resetManifest = buildResetManifest($link, $sqlfile);
                        }
                    }
                }
            }
        } else {
            // Test database connection
            $success = false; // Start with assumption of failure

            try {
                // First connection attempt - test connection to MySQL server without specifying database
                $dsn = "mysql:host=$dbh;port=$port;charset=utf8";
                $opt = array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                );

                $pdo = new PDO($dsn, $dbu, $dbp_raw, $opt);
                $dbConnectionPartial = true; // Server connection successful

                // Now check if the database exists
                try {
                    // Try to connect specifically to the database
                    $dsn_with_db = "mysql:host=$dbh;port=$port;dbname=$dbn;charset=utf8";
                    $pdo_with_db = new PDO($dsn_with_db, $dbu, $dbp_raw, $opt);

                    // If we get here, the database exists and we can connect to it
                    $success = true;
                    $go = 1;

                    // Import SQL if requested
                    if (isset($_POST['test']) && !isset($_POST['tryToCreate'])) {
                        $connectError = '';
                        $link = tryConnect($dbh, $dbu, $dbp_raw, $dbn, $port, $connectError);
                        if ($link) {
                            $sqlErrors = importSchema($link, $sqlfile);

                            if (!empty($sqlErrors)) {
                                // The server and database are fine; only the import failed.
                                // Clear $go so the form renders again and they can retry.
                                $success = false;
                                $go = 0;
                                $importFailed = true;
                                $dbError = implode("; ", $sqlErrors);
                                $resetManifest = buildResetManifest($link, $sqlfile);
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Database doesn't exist or can't connect to it
                    $needCreateDb = true;

                    // Create database if requested
                    if (isset($_POST['tryToCreate'])) {
                        try {
                            $pdo->exec("CREATE DATABASE `$dbn`");

                            // Re-test connection with the new database
                            $dsn_with_db = "mysql:host=$dbh;port=$port;dbname=$dbn;charset=utf8";
                            $pdo_with_db = new PDO($dsn_with_db, $dbu, $dbp_raw, $opt);

                            // Database created successfully
                            $success = true;
                            $go = 1;
                            $needCreateDb = false;

                            // Import SQL after creating database
                            $connectError = '';
                            $link = tryConnect($dbh, $dbu, $dbp_raw, $dbn, $port, $connectError);
                            if ($link) {
                                $sqlErrors = importSchema($link, $sqlfile);

                                if (!empty($sqlErrors)) {
                                    $success = false;
                                    $go = 0;
                                    $importFailed = true;
                                    $dbError = implode("; ", $sqlErrors);
                                    $resetManifest = buildResetManifest($link, $sqlfile);
                                }
                            }
                        } catch (PDOException $e) {
                            $dbError = "Failed to create database: " . $e->getMessage();
                        }
                    }
                }
            } catch (PDOException $e) {
                // Can't connect to the server at all
                $dbConnectionPartial = false;
                $dbError = "Server connection failed: " . $e->getMessage();
            }
        }
    }
}

// Step 3 cleanup process
if ($step == 3 && isset($_POST['cleanup'])) {
    // Buffer any warnings/output so headers can still be sent cleanly
    ob_start();

    // Update init.php with random strings (do this first, before deleting anything)
    $str = file_get_contents('../users/init.php');
    if ($str !== false) {
        $str = str_replace('pmqesoxiw318374csb', randomString(20), $str);
        $str = str_replace("'session_name' => 'user'", "'session_name' => '" . randomString(20) . "'", $str);
        file_put_contents('../users/init.php', $str);
    }

    // Delete installation files
    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    // Delete license.php
    if (file_exists('license.php')) {
        @unlink('license.php');
    }

    // Remove install subdirectory (contains settings, sql, chunks)
    if (is_dir("install")) {
        rrmdir("install");
    }

    // Remove the install folder itself
    $installDir = __DIR__;
    // Delete any remaining files in the install folder
    foreach (glob($installDir . '/*') as $remaining) {
        if (is_file($remaining)) {
            @unlink($remaining);
        }
    }
    @rmdir($installDir);

    // Discard any buffered warnings/output and redirect
    ob_end_clean();
    header('Location: ../users/updates/index.php?installing=true');
    exit();
}

// HTML Output Begins
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>UserSpice Installation</title>
    <style>
        :root {
            --primary: #1a3a8f;
            --secondary: #6c757d;
            --success: #1cc88a;
            --danger: #e74a3b;
            --warning: #f6c23e;
            --info: #36b9cc;
            --light: #f8f9fc;
            --dark: #5a5c69;
            --white: #fff;
        }

        * {
            box-sizing: border-box;
        }

        /* Real-time security feedback styles */
        .form-control.is-valid-security {
            border-color: var(--success) !important;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3e%3cpath fill='%231cc88a' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .form-control.is-valid {
            border-color: var(--success) !important;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3e%3cpath fill='%231cc88a' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .security-feedback {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.5;
            color: #212529;
            margin: 0;
            padding: 0;
            background-color: var(--light);
        }

        .container {
            width: 100%;
            max-width: 1140px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary) 0%, #102d73 100%);
            color: var(--white);
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .header h1 {
            margin: 0;
            font-size: 2rem;
        }

        .header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
        }

        .card {
            background: var(--white);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            margin-bottom: 2rem;
            border: 1px solid #e3e6f0;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background-color: #f8f9fc;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-body {
            padding: 1.5rem;
        }

        .progress-bar {
            display: flex;
            margin-bottom: 2rem;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            padding: 1rem;
            position: relative;
        }

        .progress-step:not(.active) {
            background-color: #e3e6f0;
            color: var(--dark);
        }

        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            background-color: inherit;
            clip-path: polygon(0 0, 50% 50%, 0 100%);
            z-index: 1;
        }

        .progress-step.active {
            background-color: var(--primary);
            color: var(--white);
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            display: block;
            width: 100%;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            line-height: 1.5;
            color: #495057;
            background-color: var(--white);
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(26, 58, 143, 0.25);
        }

        .form-control.is-invalid {
            border-color: var(--danger);
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%23dc3545' viewBox='0 0 12 12'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .invalid-feedback {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 80%;
            color: var(--danger);
        }

        .is-invalid~.invalid-feedback {
            display: block;
        }

        .btn {
            display: inline-block;
            font-weight: 400;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: 0.25rem;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            cursor: pointer;
        }

        .btn-primary {
            color: var(--white);
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #122d6e;
            border-color: #0f2860;
        }

        .btn-success {
            color: var(--white);
            background-color: var(--success);
            border-color: var(--success);
        }

        .btn-success:hover {
            background-color: #17a673;
            border-color: #169b6b;
        }

        .btn-danger {
            color: var(--white);
            background-color: var(--danger);
            border-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: #e02d1b;
            border-color: #d52a1a;
        }

        .btn-lg {
            padding: 0.5rem 1rem;
            font-size: 1.25rem;
            line-height: 1.5;
            border-radius: 0.3rem;
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .alert {
            position: relative;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .alert-warning {
            color: #856404;
            background-color: #fff3cd;
            border-color: #ffeeba;
        }

        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }

        .alert-primary {
            color: #004085;
            background-color: #cce5ff;
            border-color: #b8daff;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-success {
            color: var(--success);
        }

        .text-danger {
            color: var(--danger);
        }

        .text-warning {
            color: var(--warning);
        }

        .mb-1 {
            margin-bottom: 0.25rem;
        }

        .mb-2 {
            margin-bottom: 0.5rem;
        }

        .mb-3 {
            margin-bottom: 1rem;
        }

        .mb-4 {
            margin-bottom: 1.5rem;
        }

        .mb-5 {
            margin-bottom: 3rem;
        }

        .mt-1 {
            margin-top: 0.25rem;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        .mt-3 {
            margin-top: 1rem;
        }

        .mt-4 {
            margin-top: 1.5rem;
        }

        .mt-5 {
            margin-top: 3rem;
        }

        table {
            width: 100%;
            margin-bottom: 1rem;
            color: #212529;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 0.5rem;
            vertical-align: top;
            border-top: 1px solid #e3e6f0;
        }

        table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #e3e6f0;
            background-color: #f8f9fc;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .footer {
            background-color: #f8f9fc;
            padding: 1.5rem 0;
            margin-top: 2rem;
            text-align: center;
            color: #858796;
            border-top: 1px solid #e3e6f0;
        }

        /* For timezone select styling */
        select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3E%3Cpath fill='%23343a40' d='M2 0l4 4-4 4z' transform='rotate(90 4 4)'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 8px 10px;
        }

        /* Loading spinner */
        .btn-loading {
            pointer-events: none;
            opacity: 0.75;
        }

        .btn-loading .spinner {
            display: inline-block;
            width: 1em;
            height: 1em;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: -0.125em;
            margin-right: 0.4em;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .progress-step:not(:last-child)::after {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container">
            <h1 style="text-align:center;"><?php echo $app_name; ?> <?php echo $user_spice_ver ?> Installation</h1>
        </div>
    </div>

    <div class="container">
        <!-- Progress Bar -->
        <div class="progress-bar">
            <div class="progress-step <?php echo ($step == 1) ? 'active' : ''; ?>">
                <a href="index.php" style="text-decoration:none;">
                    <?php echo $step1; ?>
                </a>
            </div>
            <div class="progress-step <?php echo ($step == 2) ? 'active' : ''; ?>">
                <a href="index.php?step=2" style="text-decoration:none;">
                    <?php echo $step2; ?>
                </a>
            </div>
            <div class="progress-step <?php echo ($step == 3) ? 'active' : ''; ?>">
                <?php echo $step3; ?>
            </div>
        </div>

        <?php if ($step == 99) : ?>
            <!-- Already installed: refuse to run rather than overwrite a live config -->
            <div class="card">
                <div class="card-header">
                    Already Installed
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <strong>UserSpice is already installed here.</strong> The installer will not run again, because
                        finalizing a second time would overwrite the database credentials in <code>users/init.php</code>.
                    </div>
                    <p>If you finished installing but never removed the install files, do that now &mdash; leaving this
                        folder in place lets anyone on the internet reach the installer.</p>
                    <p class="mb-0">To reinstall from scratch, delete <code>users/init.php</code>, restore it from a fresh
                        copy of UserSpice, and drop your database by hand first.</p>
                    <div class="text-center mt-4">
                        <a href="index.php?step=3" class="btn btn-danger btn-lg">Cleanup Install Files</a>
                    </div>
                </div>
            </div>

        <?php elseif ($step == 1) : ?>
            <!-- Step 1: Welcome and Requirements Check -->
            <div class="card">
                <div class="card-header">
                    Welcome to <?php echo $app_name; ?> Installation
                </div>
                <div class="card-body">
                    <p>This program will walk you through the entire process of configuring <?php echo $app_name; ?>. Before you proceed, you might want to make sure that you're ready to do the install.</p>

                    <p>If you have not already created a new <strong style="color: var(--danger);">database</strong>, please do so at this time. Make sure that you have the Host Name, Username, Password, and Database name handy, as you will need them to complete the install. Note that if your database user has permission to create databases on your server, the installer can create the database for you in the next step.</p>

                    <h3 class="mt-4 mb-3">System Requirement Check</h3>

                    <?php
                    // Check PHP version
                    if (version_compare(phpversion(), $php_min, '<')) {
                        // PHP version isn't high enough
                        echo '<div class="alert alert-danger">We\'re sorry, but your PHP version is out of date. Please update to PHP ' . $php_min . ' or later to continue. <a href="http://php.net/" target="_blank">PHP Website</a></div>';
                    } else {
                    ?>
                        <p>Your PHP version meets the minimum system requirements of <?php echo $php_min; ?> or later, but you need to make sure your system meets all the rest of the requirements. If you see any red text in the table below, please correct those issues before installing.</p>

                        <table class="table-striped" width="100%">
                            <thead>
                                <tr>
                                    <th width="50%" style="text-align:left;">Requirement</th>
                                    <th width="50%" style="text-align:left;">State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>PHP version required to install >= <?php echo $php_min; ?></td>
                                    <td class="font-weight-bold">
                                        <?php if (phpversion() < $php_min) {
                                            echo '<span class="text-danger">No</span>';
                                            $errors = 1;
                                        } else {
                                            echo '<span class="text-success">Yes</span>';
                                            $errors = 0;
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        PHP version recommended >= <?php echo $php_ver; ?>
                                    </td>
                                    <td class="font-weight-bold">
                                        <?php if (phpversion() < $php_ver) {
                                            echo '<span class="text-danger">No</span>';
                                            $phpWarn = 1;
                                        } else {
                                            echo '<span class="text-success">Yes</span>';
                                            $phpWarn = 0;
                                        } ?>
                                </tr>
                                <tr>
                                    <td>XML support</td>
                                    <td class="font-weight-bold">
                                        <?php if (extension_loaded('xml')) {
                                            echo '<span class="text-success">Available</span>';
                                        } else {
                                            echo '<span class="text-danger">Unavailable</span>';
                                            $errors = 1;
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>MySQLi support</td>
                                    <td class="font-weight-bold">
                                        <?php if (function_exists('mysqli_connect')) {
                                            echo '<span class="text-success">Available</span>';
                                        } else {
                                            echo '<span class="text-danger">Unavailable</span>';
                                            $errors = 1;
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>PDO support</td>
                                    <td class="font-weight-bold">
                                        <?php if (class_exists('PDO')) {
                                            echo '<span class="text-success">Available</span>';
                                        } else {
                                            echo '<span class="text-danger">Unavailable</span>';
                                            $errors = 1;
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Is <?php echo $config_file; ?> writeable?</td>
                                    <td class="font-weight-bold">
                                        <?php
                                        clearstatcache();
                                        if (@file_exists($config_file) && @is_writable($config_file)) {
                                            echo '<span class="text-success">Writeable</span>';
                                        } else {
                                            $errors = 1;
                                        ?>
                                            <span class="text-danger">Unwriteable</span><br>
                                            It is really important that you be able to write to the init file! If you don't know how to chmod your init file, <a href="//userspice.com/installation-issues/" target="_blank">please read this guide at UserSpice.com.</a>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Is z_us_root.php writeable?</td>
                                    <td class="font-weight-bold">
                                        <?php
                                        if (@file_exists('../z_us_root.php') && @is_writable('../z_us_root.php')) {
                                            echo '<span class="text-success">Writeable</span>';
                                        } else {
                                            $errors = 1;
                                        ?>
                                            <span class="text-danger">Unwriteable</span><br>
                                            Please chmod this file to 0755 before installing.
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Is users/ folder writeable?</td>
                                    <td class="font-weight-bold">
                                        <?php
                                        if (@is_dir('../users') && @is_writable('../users')) {
                                            echo '<span class="text-success">Writeable</span>';
                                        } else {
                                            $errors = 1;
                                        ?>
                                            <span class="text-danger">Unwriteable</span><br>
                                            Please chmod this folder to 0755 before installing.
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Is usersc/ folder writeable?</td>
                                    <td class="font-weight-bold">
                                        <?php
                                        if (@is_dir('../usersc') && @is_writable('../usersc')) {
                                            echo '<span class="text-success">Writeable</span>';
                                        } else {
                                            $errors = 1;
                                        ?>
                                            <span class="text-danger">Unwriteable</span><br>
                                            Please chmod this folder to 0755 before installing.
                                        <?php } ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <h3 class="mt-4 mb-3">Additional Recommended Settings</h3>

                        <p><?php echo $app_name; ?> will most likely work regardless of the settings below, however these settings are suggested.</p>

                        <table class="table-striped" width="100%">
                            <thead>
                                <tr>
                                    <th width="50%" style="text-align:left;">Setting</th>
                                    <th width="25%" style="text-align:left;">Recommended</th>
                                    <th width="25%" style="text-align:left;">Actual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>CURL Enabled (For Updates and Plugins)</td>
                                    <td>YES</td>
                                    <td class="font-weight-bold">
                                        <?php if (extension_loaded("CURL") == true) {
                                            echo '<span class="text-success">YES</span>';
                                        } else {
                                            echo '<span class="text-danger">NO</span>';
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Zip Enabled (For Updates and Plugins)</td>
                                    <td>YES</td>
                                    <td class="font-weight-bold">
                                        <?php if (extension_loaded("ZIP") == true) {
                                            echo '<span class="text-success">YES</span>';
                                        } else {
                                            echo '<span class="text-danger">NO</span>';
                                        } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>UserSpice Folder Writeable</td>
                                    <td>YES</td>
                                    <td class="font-weight-bold">
                                        <?php if (@is_writeable("../z_us_root.php") == true) {
                                            echo '<span class="text-success">YES</span>';
                                        } else {
                                            echo '<span class="text-danger">NO</span>';
                                        } ?>
                                    </td>
                                </tr>
                                <?php
                                function get_php_setting($val)
                                {
                                    $r = (ini_get($val) == '1' ? 1 : 0);
                                    return $r ? 'ON' : 'OFF';
                                }

                                $php_recommended_settings = array(
                                    array('Safe Mode', 'safe_mode', 'OFF'),
                                    array('Display Errors (Recommended during Development)', 'display_errors', 'ON'),
                                    array('File Uploads', 'file_uploads', 'ON'),
                                    array('Register Globals', 'register_globals', 'OFF'),
                                    array('Output Buffering', 'output_buffering', 'OFF'),
                                    array('Session Auto Start', 'session.auto_start', 'OFF'),
                                );

                                foreach ($php_recommended_settings as $phprec) {
                                ?>
                                    <tr>
                                        <td><?php echo $phprec[0]; ?></td>
                                        <td><?php echo $phprec[2]; ?></td>
                                        <td class="font-weight-bold">
                                            <?php if (get_php_setting($phprec[1]) == $phprec[2]) {
                                                echo '<span class="text-success">' . get_php_setting($phprec[1]) . '</span>';
                                            } else {
                                                echo '<span class="text-danger">' . get_php_setting($phprec[1]) . '</span>';
                                            } ?>
                                        </td>
                                    </tr>
                                <?php
                                }
                                ?>

                            </tbody>
                        </table>

                        <?php if ($errors === 0) { ?>
                            <div class="mt-4 mb-3 text-center">
                                <p><strong>By clicking continue, you agree with the terms of the <a href="license.php" target="_blank"><?php echo $app_name; ?> License.</a></strong></p>
                                <a href="index.php?step=2" class="btn btn-primary btn-lg">Continue</a>
                            </div>
                        <?php } elseif ($errors === 1) { ?>
                            <div class="alert alert-danger mt-4">
                                You have errors listed in the System Requirement Check that must be corrected before continuing. If you have an unwritable <?php echo $config_file; ?>, it is suggested that you chmod that file to 666 for installation and then chmod it to 644 after installation. <a href="//userspice.com/installation-issues/" target="_blank">please read this guide</a>.
                            </div>
                        <?php } ?>

                        <?php if ($phpWarn === 1) { ?>
                            <div class="alert alert-warning mt-4">
                                Your PHP is out of date and you are using an unsupported version. UserSpice may work fine, but if you have the option to update to 8.0 or greater, it is strongly suggested that you do.
                            </div>
                        <?php } ?>

                    <?php } // End of PHP version check 
                    ?>
                </div>
            </div>

        <?php elseif ($step == 2) : ?>
            <!-- Step 2: Database Setup -->
            <div class="card">
                <div class="card-header">
                    Database Configuration
                </div>
                <div class="card-body">
                    <?php if ($isPost && isset($missingFields) && $missingFields != false) : ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> Please fill out all required fields.

                        </div>
                    <?php endif; ?>

                    <?php if ($isPost) : ?>
                        <?php if ($importFailed) : ?>
                            <?php if (!empty($resetRefused)) : ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($resetRefused); ?></div>
                            <?php else : ?>
                                <div class="alert alert-warning">
                                    Connected to <strong><?php echo htmlspecialchars($dbn); ?></strong> successfully, but the
                                    <strong>table import failed</strong>. Your credentials are fine &mdash; this is usually a
                                    permissions problem, or tables left behind by an earlier attempt.
                                    <?php if (!empty($dbError)) : ?>
                                        <hr>
                                        <small><strong>MySQL said:</strong> <?php echo htmlspecialchars($dbError); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($resetManifest && $resetManifest['tables']) : ?>
                                <div class="alert alert-secondary">
                                    <p class="mb-2">
                                        <strong><?php echo count($resetManifest['tables']); ?></strong> UserSpice tables are
                                        present in <strong><?php echo htmlspecialchars($dbn); ?></strong>. Each row count is shown
                                        against what a clean import produces. A table holding some other number of rows was not
                                        written by this installer.
                                    </p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead>
                                                <tr><th>Table</th><th class="text-right">Rows</th><th class="text-right">Expected</th><th></th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($resetManifest['tables'] as $table => $row) : ?>
                                                    <tr<?php echo $row['unexpected'] ? ' class="table-warning"' : ''; ?>>
                                                        <td><code><?php echo htmlspecialchars($table); ?></code></td>
                                                        <td class="text-right"><?php echo $row['actual'] < 0 ? '?' : number_format($row['actual']); ?></td>
                                                        <td class="text-right text-muted"><?php echo number_format($row['expected']); ?></td>
                                                        <td><?php echo $row['unexpected'] ? '&larr; unexpected' : ''; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted d-block mt-2">A count of 0 is normal &mdash; it means the table was
                                        created before the import failed, but never populated.</small>
                                </div>

                                <?php if ($resetManifest['blockers']) : ?>
                                    <div class="alert alert-danger">
                                        <strong>This database will not be cleared.</strong> It holds rows the installer did not
                                        write, so dropping these tables could destroy data that does not belong to UserSpice.
                                        <ul class="mb-0 mt-2">
                                            <?php foreach ($resetManifest['blockers'] as $blocker) : ?>
                                                <li><?php echo $blocker; // assembled from literals, escaped names and integers ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <hr>
                                        Back this database up, then either drop the tables by hand or point the installer at an empty database.
                                    </div>
                                <?php elseif (!$resetManifest['offer']) : ?>
                                    <div class="alert alert-danger">
                                        <strong>This database will not be cleared.</strong> None of the tables present are
                                        unmistakably UserSpice&#39;s, so the installer cannot prove they are safe to drop.
                                    </div>
                                <?php else : ?>
                                    <p>
                                        Fix the cause of the failure &mdash; usually the database user&#39;s permissions &mdash; then
                                        clear these tables and import again.
                                    </p>

                                    <form action="" method="post" id="resetForm">
                                        <input type="hidden" name="step" value="2">
                                        <input type="hidden" name="dbh" value="<?php echo htmlspecialchars($dbh); ?>">
                                        <input type="hidden" name="dbu" value="<?php echo htmlspecialchars($dbu); ?>">
                                        <input type="hidden" name="dbp" value="<?php echo htmlspecialchars($dbp_raw); ?>">
                                        <input type="hidden" name="dbn" value="<?php echo htmlspecialchars($dbn); ?>">
                                        <input type="hidden" name="port" value="<?php echo htmlspecialchars($port); ?>">
                                        <input type="hidden" name="timezone" value="<?php echo htmlspecialchars($tz); ?>">

                                        <div class="form-group form-check">
                                            <input type="checkbox" class="form-check-input" id="resetConfirm" required>
                                            <label class="form-check-label" for="resetConfirm">
                                                I understand these <?php echo count($resetManifest['tables']); ?> tables will be
                                                permanently dropped from <strong><?php echo htmlspecialchars($dbn); ?></strong>.
                                            </label>
                                        </div>

                                        <div class="text-center mt-3">
                                            <button type="submit" name="resetDb" value="1" class="btn btn-danger" id="btnReset">Drop these tables and import again</button>
                                            <a href="index.php?step=2" class="btn btn-secondary ml-2">Leave them alone</a>
                                        </div>
                                    </form>
                                    <script>
                                    (function() {
                                        var form = document.getElementById('resetForm');
                                        var btn = document.getElementById('btnReset');
                                        var submitting = false;

                                        form.addEventListener('submit', function(event) {
                                            if (submitting) {
                                                event.preventDefault();
                                                return;
                                            }
                                            submitting = true;
                                            btn.classList.add('btn-loading');
                                            btn.innerHTML = '<span class="spinner"></span> Dropping tables and importing, please wait...';
                                        });
                                    })();
                                    </script>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ((isset($_POST['test']) || isset($_POST['resetDb'])) && !$success && !$needCreateDb) : ?>
                            <div class="alert alert-danger">
                                Database connection <strong>unsuccessful</strong>! Please check your credentials and try again.
                                <?php if (!empty($dbError)) : ?>
                                    <br>Error: <?php echo htmlspecialchars($dbError); ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif (!empty($_POST) && isset($needCreateDb) && $needCreateDb) : ?>
                            <form action="" method="post" id="createDbForm">
                                <input type="hidden" name="step" value="2">
                                <input type="hidden" name="test" value="1">
                                <input type="hidden" name="dbh" value="<?php echo htmlspecialchars($dbh); ?>">
                                <input type="hidden" name="dbu" value="<?php echo htmlspecialchars($dbu); ?>">
                                <input type="hidden" name="dbp" value="<?php echo htmlspecialchars($dbp_raw); ?>">
                                <input type="hidden" name="dbn" value="<?php echo htmlspecialchars($dbn); ?>">
                                <input type="hidden" name="port" value="<?php echo htmlspecialchars($port); ?>">
                                <input type="hidden" name="timezone" value="<?php echo htmlspecialchars($tz); ?>">

                                <div class="alert alert-primary">
                                    Your credentials appear to be correct but the database name "<strong><?php echo htmlspecialchars($dbn); ?></strong>" is not found.<br>
                                    If you would like to attempt to create it, please hit "Yes". Otherwise, edit your information and try again.
                                </div>

                                <div class="text-center">
                                    <button type="submit" name="tryToCreate" value="1" class="btn btn-success" id="btnCreate">Yes, Create it For Me</button>
                                    <a href="index.php?step=2" class="btn btn-danger ml-2">No, Let Me Change My Info</a>
                                </div>
                            </form>
                            <script>
                            (function() {
                                var form = document.getElementById('createDbForm');
                                var btn = document.getElementById('btnCreate');
                                var submitting = false;

                                form.addEventListener('submit', function(event) {
                                    if (submitting) {
                                        event.preventDefault();
                                        return;
                                    }
                                    submitting = true;
                                    btn.classList.add('btn-loading');
                                    btn.innerHTML = '<span class="spinner"></span> Creating database and importing tables...';
                                });
                            })();
                            </script>
                        <?php elseif (isset($dbConnectionPartial) && $dbConnectionPartial && !$success) : ?>
                            <div class="alert alert-warning">
                                Database server connection <strong>successful</strong>, but could not connect to or create the database. Please check your database name and user permissions.
                                <?php if (!empty($dbError)) : ?>
                                    <br>Error: <?php echo htmlspecialchars($dbError); ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($go === 1) : ?>
                            <div class="alert alert-success">
                                Database connection successful and tables imported! You can now finalize your installation.
                            </div>

                            <?php if (!empty($adminError)) : ?>
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> <?php echo htmlspecialchars($adminError); ?>
                                </div>
                            <?php endif; ?>

                            <form action="" method="post" id="adminForm">
                                <input type="hidden" name="step" value="2">
                                <input type="hidden" name="dbh" value="<?php echo htmlspecialchars($dbh); ?>">
                                <input type="hidden" name="dbu" value="<?php echo htmlspecialchars($dbu); ?>">
                                <input type="hidden" name="dbp" value="<?php echo htmlspecialchars($dbp_raw); ?>">
                                <input type="hidden" name="dbn" value="<?php echo htmlspecialchars($dbn); ?>">
                                <input type="hidden" name="port" value="<?php echo htmlspecialchars($port); ?>">
                                <input type="hidden" name="timezone" value="<?php echo htmlspecialchars($tz); ?>">

                                <h4 class="mb-3">Admin Account Setup</h4>
                                <p>Please setup your admin account.</p>

                                <div class="form-group">
                                    <label for="admin_username">Admin Username</label>
                                    <input type="text" class="form-control" id="admin_username" name="admin_username" placeholder="admin" value="<?php echo htmlspecialchars($_POST['admin_username'] ?? 'admin'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="admin_email">Admin Email</label>
                                    <input type="email" class="form-control" id="admin_email" name="admin_email" placeholder="" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="admin_password">Admin Password</label>
                                    <input type="password" class="form-control" id="admin_password" name="admin_password" placeholder="Minimum 8 characters" required>
                                </div>

                                <div class="form-group">
                                    <label for="admin_confirm_password">Confirm Password</label>
                                    <input type="password" class="form-control" id="admin_confirm_password" name="admin_confirm_password" placeholder="Confirm password" required>
                                </div>

                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const passwordField = document.getElementById('admin_password');
                                        const confirmField = document.getElementById('admin_confirm_password');
                                        const feedbackDiv = document.createElement('div');

                                        // Add feedback div after confirm password field
                                        confirmField.parentNode.appendChild(feedbackDiv);
                                        feedbackDiv.className = 'invalid-feedback';
                                        feedbackDiv.style.display = 'none';

                                        // Function to validate passwords
                                        function validatePasswords() {
                                            const password = passwordField.value;
                                            const confirmPassword = confirmField.value;

                                            // Reset visual states
                                            passwordField.classList.remove('is-invalid', 'is-valid');
                                            confirmField.classList.remove('is-invalid', 'is-valid');
                                            feedbackDiv.style.display = 'none';

                                            // Block submission natively so the browser refuses the POST
                                            // and reports it, rather than the form silently doing nothing.
                                            confirmField.setCustomValidity(
                                                password !== confirmPassword ? 'Passwords do not match' : ''
                                            );

                                            // Only validate when confirm password has at least 7 chars
                                            if (confirmPassword.length >= 7) {
                                                if (password.length < 8) {
                                                    passwordField.classList.add('is-invalid');
                                                    feedbackDiv.textContent = 'Password must be at least 8 characters';
                                                    feedbackDiv.style.display = 'block';
                                                    return false;
                                                } else if (password !== confirmPassword) {
                                                    confirmField.classList.add('is-invalid');
                                                    feedbackDiv.textContent = 'Passwords do not match';
                                                    feedbackDiv.style.display = 'block';
                                                    return false;
                                                } else {
                                                    // Valid case
                                                    passwordField.classList.add('is-valid');
                                                    confirmField.classList.add('is-valid');
                                                    return true;
                                                }
                                            }

                                            return true;
                                        }

                                        // Add event listeners
                                        confirmField.addEventListener('input', validatePasswords);
                                        passwordField.addEventListener('input', validatePasswords);
                                    });
                                </script>

                                <div class="form-group">
                                    <label for="admin_fname">First Name</label>
                                    <input type="text" class="form-control" id="admin_fname" name="admin_fname" placeholder="First Name" value="<?php echo htmlspecialchars($_POST['admin_fname'] ?? ''); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="admin_lname">Last Name</label>
                                    <input type="text" class="form-control" id="admin_lname" name="admin_lname" placeholder="Last Name" value="<?php echo htmlspecialchars($_POST['admin_lname'] ?? ''); ?>" required>
                                </div>

                                <div class="text-center mt-4">
                                    <button type="submit" name="submit" class="btn btn-success btn-lg" id="btnFinalize">Finalize Installation</button>
                                </div>
                            </form>
                            <script>
                            (function() {
                                var form = document.getElementById('adminForm');
                                var btn = document.getElementById('btnFinalize');
                                var submitting = false;

                                // Bind to submit, not click: a click that fails the browser's
                                // required/email validation must leave the button usable.
                                form.addEventListener('submit', function(event) {
                                    if (submitting) {
                                        event.preventDefault();
                                        return;
                                    }
                                    submitting = true;
                                    btn.classList.add('btn-loading');
                                    btn.innerHTML = '<span class="spinner"></span> Finalizing installation, please wait...';
                                });
                            })();
                            </script>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$isPost || ($isPost && !$go && !$needCreateDb)) : ?>
                        <form action="" method="post" id="dbForm" novalidate>
                            <div class="form-group">
                                <input type="hidden" name="step" value="2">
                                <label for="timezone">Region/Timezone (required)</label>
                                <select required class="form-control" id="timezone" name="timezone">
                                    <option value="" disabled selected>--Select Timezone--</option>
                                    <?php
                                    $regions = array(
                                        'Africa' => DateTimeZone::AFRICA,
                                        'America' => DateTimeZone::AMERICA,
                                        'Antarctica' => DateTimeZone::ANTARCTICA,
                                        'Asia' => DateTimeZone::ASIA,
                                        'Atlantic' => DateTimeZone::ATLANTIC,
                                        'Australia' => DateTimeZone::AUSTRALIA,
                                        'Europe' => DateTimeZone::EUROPE,
                                        'Indian' => DateTimeZone::INDIAN,
                                        'Pacific' => DateTimeZone::PACIFIC
                                    );
                                    $timezones = array();
                                    foreach ($regions as $name => $mask) :
                                        $zones = DateTimeZone::listIdentifiers($mask);
                                        foreach ($zones as $timezone) :
                                            $time = new DateTime("now", new DateTimeZone($timezone));
                                            $ampm = ($time->format('H') > 12 ? ' (' . $time->format('g:i a') . ')' : '');
                                            $cleanTz = str_replace('_', ' ', substr($timezone, strlen($name) + 1));
                                            $timezones[$name][$timezone] = $cleanTz . ' - ' . $time->format('H:i') . $ampm;
                                        endforeach;
                                    endforeach;

                                    foreach ($timezones as $region => $list) :
                                        echo '<optgroup label="' . $region . '">' . "\n";
                                        foreach ($list as $timezone => $name) :
                                            echo '<option value="' . $timezone . '"';
                                            if (isset($_POST['timezone']) && $_POST['timezone'] == $timezone) :
                                                echo ' selected="selected"';
                                            endif;
                                            echo '>' . $name . '</option>' . "\n";
                                        endforeach;
                                        echo '</optgroup>' . "\n";
                                    endforeach;
                                    ?>
                                </select>
                                <div class="invalid-feedback">Please select a timezone</div>
                            </div>

                            <div class="form-group">
                                <label for="dbh">Database Host (required)</label>
                                <input required class="form-control" type="text" id="dbh" name="dbh" value="<?php echo isset($_POST['dbh']) ? htmlspecialchars($_POST['dbh']) : 'localhost'; ?>" placeholder="localhost">
                                <div class="invalid-feedback">Please provide a database host</div>
                                <small class="text-muted">Typically 'localhost' or an IP address</small>
                            </div>

                            <div class="form-group">
                                <label for="port">Database Port (required)</label>
                                <input required class="form-control" type="text" id="port" name="port" value="<?php echo isset($_POST['port']) ? htmlspecialchars($_POST['port']) : '3306'; ?>" placeholder="3306">
                                <div class="invalid-feedback">Please provide a database port</div>
                                <small class="text-muted">Default MySQL port is 3306</small>
                            </div>

                            <div class="form-group">
                                <label for="dbu">Database Username (required)</label>
                                <input required class="form-control" type="text" id="dbu" name="dbu" value="<?php echo isset($_POST['dbu']) ? htmlspecialchars($_POST['dbu']) : ''; ?>" placeholder="Database username">
                                <div class="invalid-feedback">Please provide a database username</div>
                            </div>

                            <div class="form-group">
                                <label for="dbp">Database Password (usually required)</label>
                                <input class="form-control" type="password" id="dbp" name="dbp" value="<?php echo isset($_POST['dbp']) ? htmlspecialchars($_POST['dbp']) : ''; ?>" placeholder="Database password">
                            </div>

                            <div class="form-group">
                                <label for="dbn">Database Name (required)</label>
                                <input required class="form-control" type="text" id="dbn" name="dbn" value="<?php echo isset($_POST['dbn']) ? htmlspecialchars($_POST['dbn']) : ''; ?>" placeholder="Database name">
                                <div class="invalid-feedback">Please provide a database name</div>
                                <small class="text-muted">The database must exist or your user must have permissions to create it</small>
                            </div>

                            <div class="text-center mt-4">
                                <button type="submit" name="test" class="btn btn-primary btn-lg" id="btnInstall">Attempt to Install</button>
                            </div>
                        </form>

                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                // Auto-select a timezone based on browser detection if none is selected
                                const timezoneSelect = document.getElementById('timezone');
                                if (!timezoneSelect.value) {
                                    try {
                                        const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                                        for (let i = 0; i < timezoneSelect.options.length; i++) {
                                            if (timezoneSelect.options[i].value === browserTz) {
                                                timezoneSelect.selectedIndex = i;
                                                break;
                                            }
                                        }
                                    } catch (e) {
                                        console.log("Timezone detection failed:", e);
                                    }
                                }

                                const form = document.getElementById('dbForm');
                                const dangerousChars = /['\\]/; // Single quote or backslash

                                // Fields that should not contain dangerous characters (password is exempt)
                                const securityFields = ['dbh', 'dbu', 'dbn'];

                                // Real-time validation for security-sensitive fields
                                securityFields.forEach(function(fieldId) {
                                    const field = document.getElementById(fieldId);
                                    if (!field) return;

                                    field.addEventListener('input', function() {
                                        validateSecurityField(this);
                                    });
                                });

                                function validateSecurityField(field) {
                                    // Remove previous feedback if exists
                                    const existingFeedback = field.parentNode.querySelector('.security-feedback');
                                    if (existingFeedback) {
                                        existingFeedback.remove();
                                    }

                                    field.classList.remove('is-invalid', 'is-valid-security');

                                    if (field.value.trim() === '') {
                                        return true; // Empty is handled by required validation
                                    }

                                    if (dangerousChars.test(field.value)) {
                                        field.classList.add('is-invalid');

                                        // Add specific feedback
                                        const feedback = document.createElement('div');
                                        feedback.className = 'invalid-feedback security-feedback';
                                        feedback.style.display = 'block';
                                        feedback.textContent = "Single quotes (') and backslashes (\\) are not allowed";
                                        field.parentNode.insertBefore(feedback, field.nextSibling);

                                        return false;
                                    } else {
                                        field.classList.add('is-valid-security');
                                        return true;
                                    }
                                }

                                // Form submission validation
                                form.addEventListener('submit', function(event) {
                                    let isValid = true;

                                    // Check required fields
                                    const requiredFields = form.querySelectorAll('[required]');
                                    requiredFields.forEach(function(field) {
                                        if (!field.value.trim()) {
                                            field.classList.add('is-invalid');
                                            isValid = false;
                                        }
                                    });

                                    // Check security fields for dangerous characters
                                    securityFields.forEach(function(fieldId) {
                                        const field = document.getElementById(fieldId);
                                        if (field && !validateSecurityField(field)) {
                                            isValid = false;
                                        }
                                    });

                                    if (!isValid) {
                                        event.preventDefault();
                                        event.stopPropagation();
                                    } else {
                                        var btn = document.getElementById('btnInstall');
                                        btn.classList.add('btn-loading');
                                        btn.innerHTML = '<span class="spinner"></span> Attempting to install, please wait...';
                                    }
                                });

                                // Remove invalid class on input for non-security fields
                                const inputs = form.querySelectorAll('input, select');
                                inputs.forEach(function(input) {
                                    if (!securityFields.includes(input.id)) {
                                        input.addEventListener('input', function() {
                                            if (this.value.trim()) {
                                                this.classList.remove('is-invalid');
                                            }
                                        });
                                    }
                                });
                            });
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($step == 3) : ?>
            <!-- Step 3: Cleanup -->
            <div class="card">
                <div class="card-header">
                    Final Cleanup
                </div>
                <div class="card-body text-center">
                    <h3 class="mb-4">Congratulations!</h3>

                    <div class="alert alert-success">
                        <p>Your UserSpice installation is complete. You can now cleanup the install files and begin using your software.</p>
                    </div>

                    <p>If you have any problems, you can edit the init.php directly or reinstall the app.</p>

                    <form action="" method="post">
                        <input type="hidden" name="step" value="3">
                        <button type="submit" name="cleanup" class="btn btn-danger btn-lg mt-3" id="btnCleanup">Cleanup Install Files</button>
                    </form>
                    <script>
                    document.getElementById('btnCleanup').addEventListener('click', function() {
                        this.classList.add('btn-loading');
                        this.innerHTML = '<span class="spinner"></span> Cleaning up, please wait...';
                    });
                    </script>
                </div>
            </div>
        <?php endif; ?>

        <div class="footer">
            <div class="container">
                <p>&copy; 2015-<?php echo date("Y"); ?> UserSpice. All Rights Reserved.</p>
            </div>
        </div>
    </div>
</body>

</html>
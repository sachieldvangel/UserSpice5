<?php
/**
 * TOTP encryption helpers for UserSpice
 * ------------------------------------
 *  • Crypto back-end probe  (sodium → sodium_compat → OpenSSL AES-256-GCM)
 *  • Key-file generation & init
 *  • Encrypt / decrypt helpers
 *  • Git-ignore helper
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ *
 |  1.  ENGINE DETECTION                                              |
 * ------------------------------------------------------------------ */

/**
 * Returns the crypto engine to use: 'sodium', 'openssl', or null (none).
 */
function totp_crypto_engine(): ?string
{
    // Native libsodium
    if (function_exists('sodium_crypto_secretbox')) {
        return 'sodium';
    }

    // sodium_compat polyfill (global class ParagonIE_Sodium_Compat)
    if (class_exists('ParagonIE_Sodium_Compat')) {
        return 'sodium';
    }

    // PHP’s OpenSSL extension with AES-256-GCM
    if (
        defined('OPENSSL_VERSION_TEXT')
        && in_array(
            'aes-256-gcm',
            array_map('strtolower', openssl_get_cipher_methods()),
            true
        )
    ) {
        return 'openssl';
    }

    return null;
}

/** Whether TOTP can be enabled on this host. */
function totp_is_crypto_available(): bool
{
    return totp_crypto_engine() !== null;
}


/* ------------------------------------------------------------------ *
 |  2.  KEY-FILE INIT                                                 |
 * ------------------------------------------------------------------ */

/**
 * Ownership / permission facts about the key file, for diagnostics.
 *
 * Every lookup is best-effort: posix_* is not compiled into every PHP build
 * and open_basedir can hide stat() results, so each value stays null when we
 * cannot determine it honestly.
 *
 * @return array{perms:?string,owner:?string,group:?string,runas_user:?string,runas_group:?string}
 */
function totp_key_file_owner_info(string $totpKeyFile): array
{
    $info = [
        'perms'       => null,
        'owner'       => null,
        'group'       => null,
        'runas_user'  => null,
        'runas_group' => null,
    ];

    $perms = @fileperms($totpKeyFile);
    if ($perms !== false) {
        $info['perms'] = substr(sprintf('%o', $perms), -4);
    }

    $uid = @fileowner($totpKeyFile);
    if ($uid !== false) {
        $info['owner'] = (string) $uid;
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && !empty($pw['name'])) {
                $info['owner'] = $pw['name'];
            }
        }
    }

    $gid = @filegroup($totpKeyFile);
    if ($gid !== false) {
        $info['group'] = (string) $gid;
        if (function_exists('posix_getgrgid')) {
            $gr = @posix_getgrgid($gid);
            if (is_array($gr) && !empty($gr['name'])) {
                $info['group'] = $gr['name'];
            }
        }
    }

    /* Who PHP actually runs as. get_current_user() is deliberately not used as
       a fallback -- it reports the owner of the script file, not the process. */
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $me = @posix_getpwuid(posix_geteuid());
        if (is_array($me)) {
            if (!empty($me['name'])) {
                $info['runas_user'] = $me['name'];
            }
            if (isset($me['gid']) && function_exists('posix_getgrgid')) {
                $gr = @posix_getgrgid((int) $me['gid']);
                if (is_array($gr) && !empty($gr['name'])) {
                    $info['runas_group'] = $gr['name'];
                }
            }
        }
    }

    return $info;
}

/** One-line "permissions are 0400, owner is sachiel:sachiel, PHP runs as www-data". */
function totp_key_file_diagnostics(string $totpKeyFile): string
{
    $info = totp_key_file_owner_info($totpKeyFile);
    $bits = [];

    if ($info['perms'] !== null) {
        $bits[] = 'permissions are ' . $info['perms'];
    }
    if ($info['owner'] !== null && $info['group'] !== null) {
        $bits[] = 'owner is ' . $info['owner'] . ':' . $info['group'];
    }
    if ($info['runas_user'] !== null) {
        $bits[] = 'PHP is running as ' . $info['runas_user'];
    }

    return $bits ? implode(', ', $bits) : '';
}

/** The chgrp/chmod a site owner should run to hand the key file to PHP. */
function totp_key_file_fix_command(string $totpKeyFile): string
{
    $info  = totp_key_file_owner_info($totpKeyFile);
    $group = $info['runas_group'] ?: ($info['runas_user'] ?: 'www-data');

    return 'chgrp ' . $group . ' ' . $totpKeyFile . ' && chmod 440 ' . $totpKeyFile;
}

/**
 * Inspect -- and optionally load -- the key file without ever being fatal.
 *
 * require/include of an unreadable file is a fatal E_COMPILE_ERROR that no
 * try/catch can trap, so readability is probed with is_readable() *before*
 * the file is pulled in. Callers get a state they can report instead of a
 * white screen.
 *
 * States:
 *   'ok'         key is loaded and TOTP_ENC_KEY is defined
 *   'missing'    nothing at that path (safe to generate one)
 *   'unreadable' file is there but the web server cannot read it
 *   'error'      the file threw or failed to parse while being included
 *   'invalid'    the file loaded but never defined TOTP_ENC_KEY
 *
 * @param bool $load Pass false to probe without including the file.
 * @return array{state:string,message:string,fix:string,command:string}
 */
function totp_key_file_status(string $totpKeyFile, bool $load = true): array
{
    $status = static function (string $state, string $message, string $fix = '', string $command = ''): array {
        return ['state' => $state, 'message' => $message, 'fix' => $fix, 'command' => $command];
    };

    /* Already loaded this request, or defined in init.php / a .env loader. */
    if (defined('TOTP_ENC_KEY')) {
        return $status('ok', 'The TOTP encryption key is loaded.');
    }

    if (!file_exists($totpKeyFile)) {
        return $status(
            'missing',
            'The TOTP key file does not exist yet.',
            'It is generated automatically the first time TOTP is enabled, as long as '
                . dirname($totpKeyFile) . ' is writable by PHP.'
        );
    }

    if (!is_readable($totpKeyFile)) {
        $diag = totp_key_file_diagnostics($totpKeyFile);

        return $status(
            'unreadable',
            'The TOTP key file exists but the web server cannot read it'
                . ($diag !== '' ? ' (' . $diag . ')' : '') . '.',
            'Give the user or group PHP runs as read access. Do not delete the file: '
                . 'the TOTP secrets already stored in your database can only be decrypted with this key.',
            totp_key_file_fix_command($totpKeyFile)
        );
    }

    if (!$load) {
        return $status('ok', 'The TOTP key file is present and readable.');
    }

    try {
        require_once $totpKeyFile;
    } catch (Throwable $e) {
        return $status(
            'error',
            'The TOTP key file could not be loaded: ' . $e->getMessage(),
            'The file is readable but broken. Restore it from your backup -- the secrets '
                . 'already stored in your database can only be decrypted with the original key.'
        );
    }

    if (!defined('TOTP_ENC_KEY')) {
        return $status(
            'invalid',
            'The TOTP key file loaded but does not define TOTP_ENC_KEY.',
            'Restore the file from your backup if you have one. Deleting it lets UserSpice '
                . 'generate a fresh key, but every existing TOTP secret becomes undecryptable '
                . 'and those users must re-enroll.'
        );
    }

    return $status('ok', 'The TOTP encryption key is loaded.');
}

/**
 * Initialise encryption; generate/load key file; validate engine.
 *
 * @param string $totpKeyFile  Absolute path to usersc/includes/totp_key.php
 * @param bool   $migration    True during core migrations (non-fatal mode)
 *
 * @throws RuntimeException when the key cannot be established (never fatal --
 *         callers can catch this and degrade instead of white-screening).
 */
function totp_init_encryption(string $totpKeyFile, bool $migration = false): void
{
    if (!defined('TOTP_ENC_KEY')) {

        $status = totp_key_file_status($totpKeyFile);

        if ($status['state'] === 'missing') {
            // ► Autogenerate key file
            totp_generate_key_file($totpKeyFile);

        } elseif ($status['state'] !== 'ok') {
            /* Never regenerate here. A file we cannot read or parse may still
               hold the only key that decrypts the secrets in the database. */
            $detail = trim($status['message'] . ' ' . $status['fix']);
            if ($status['command'] !== '') {
                $detail .= ' Try: ' . $status['command'];
            }
            error_log('TOTP: ' . $detail);

            if ($migration) {
                return; // migrations stay non-fatal
            }
            throw new RuntimeException($detail);
        }

        // ► Warn if engine changed
        if (
            defined('TOTP_CRYPTO_ENGINE')
            && TOTP_CRYPTO_ENGINE !== totp_crypto_engine()
        ) {
            $old = TOTP_CRYPTO_ENGINE;
            $new = totp_crypto_engine();

            if ($new === null) {
                throw new RuntimeException(
                    "TOTP crypto engine '$old' no longer available; install missing extension."
                );
            }

            error_log(
                "TOTP NOTICE: crypto engine changed from '$old' to '$new'. " .
                "Old secrets will be re-encrypted on first use."
            );
        }
    }

    /* Final validation (skip during non-interactive migrations) */
    if (!$migration) {
        if (!defined('TOTP_ENC_KEY')) {
            throw new RuntimeException(
                'TOTP_ENC_KEY is not defined after init. The key file at ' . $totpKeyFile
                . ' could not be created -- check that ' . dirname($totpKeyFile) . ' is writable by PHP.'
            );
        }
        if (!totp_is_crypto_available()) {
            throw new RuntimeException('No crypto backend available for TOTP');
        }
    }
}



/**
 * Generate a new usersc/includes/totp_key.php (read-only, git-ignored)
 *
 * PREFERRED APPROACH: Use a .env file with TOTP_ENC_KEY and TOTP_CRYPTO_ENGINE
 * constants loaded via your environment configuration. This method is used as
 * a fallback when no .env configuration is detected.
 */
function totp_generate_key_file(string $totpKeyFile): void
{
    global $never_generate_totp_key_file;
    $never_generate_totp_key_file = $never_generate_totp_key_file ?? false;

    if (!totp_is_crypto_available()) {
        totp_disable_and_log('Cannot generate TOTP key: no crypto backend available.');
        return;
    }
    if ($never_generate_totp_key_file) {
        return; // developer override
    }

    /* Generate 32-byte master key */
    $rawKey       = random_bytes(32);
    $b64Key       = base64_encode($rawKey);
    $cryptoEngine = totp_crypto_engine();
    $dt           = date('Y-m-d\TH:i:sP');

    $php = <<<PHP
<?php
/**
 * TOTP Encryption Configuration
 *
 * SECURITY WARNING: DO NOT COMMIT THIS FILE.
 * Recommended permissions: 0400
 *
 * PREFERRED APPROACH: Store these values in your .env file instead:
 * TOTP_ENC_KEY=XXXXXXXXXXXXXXXXXXXXXXXX
 * TOTP_CRYPTO_ENGINE={$cryptoEngine}
 *
 * This file is designed to be used when an env file is not possible.
 * If you have an env file, you should delete this file and load your
 * constants from your .env file instead.
 * We've automatically added this file to your .gitignore (if one exists).
 *
 * --------------------------------------------------------------------
 * EXAMPLE: Loading constants from a .env file
 * --------------------------------------------------------------------
 * If you are using a library like 'vlucas/phpdotenv', you can load
 * the constants in your application's bootstrap file like this:
 *
 * require_once '/path/to/vendor/autoload.php';
 * \$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
 * \$dotenv->load();
 *
 * // Define constants from environment variables
 * define('TOTP_ENC_KEY', \$_ENV['TOTP_ENC_KEY']);
 * define('TOTP_CRYPTO_ENGINE', \$_ENV['TOTP_CRYPTO_ENGINE']);
 * 
 * // Then delete the constants from this file
 * --------------------------------------------------------------------
 *
 * Generated: {$dt}
 */
const TOTP_ENC_KEY = '{$b64Key}';
const TOTP_CRYPTO_ENGINE = '{$cryptoEngine}';

// To migrate servers, you may define TOTP_FORCE_CRYPTO_ENGINE
// const TOTP_FORCE_CRYPTO_ENGINE = 'sodium'; // or 'openssl'
PHP;

    if (file_put_contents($totpKeyFile, $php, LOCK_EX) === false) {
        totp_disable_and_log('Failed to write totp_key.php – check directory permissions.');
        return;
    }
    chmod($totpKeyFile, 0400);

    /* Make sure Git ignores it (automatically added if .gitignore exists) */
    totp_add_to_gitignore($totpKeyFile);

    /* Define for immediate use in current request */
    define('TOTP_ENC_KEY', $b64Key);
    define('TOTP_CRYPTO_ENGINE', $cryptoEngine);
}
/**
 * Gracefully disable TOTP when key file generation fails.
 * Persists to DB so the setting sticks until an admin re-enables it.
 */
function totp_disable_and_log(string $reason): void
{
    global $settings, $db;

    error_log("TOTP DISABLED: $reason");

    if (isset($settings)) {
        $settings->totp = 0;
    }

    if (isset($db)) {
        $db->update('settings', 1, ['totp' => 0]);
    }
}

/**
 * Append the key file to .gitignore (idempotent)
 */
function totp_add_to_gitignore(string $totpKeyFile): void
{
    global $abs_us_root, $us_url_root;

    $gitignore = $abs_us_root . $us_url_root . '.gitignore';
    if (!file_exists($gitignore)) {
        return;
    }

    $relative = str_replace($abs_us_root . $us_url_root, '', $totpKeyFile);
    $contents = file_get_contents($gitignore);
    if (strpos($contents, $relative) === false) {
        file_put_contents($gitignore, PHP_EOL . $relative . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}


/* ------------------------------------------------------------------ *
 |  3.  ACTIVE ENGINE (respect forced override)                       |
 * ------------------------------------------------------------------ */

function totp_get_active_crypto_engine(): ?string
{
    if (defined('TOTP_FORCE_CRYPTO_ENGINE')) {
        $forced = TOTP_FORCE_CRYPTO_ENGINE;

        switch ($forced) {
            case 'sodium':
                if (
                    function_exists('sodium_crypto_secretbox')
                    || class_exists('ParagonIE_Sodium_Compat')
                ) {
                    return 'sodium';
                }
                break;

            case 'openssl':
                if (
                    defined('OPENSSL_VERSION_TEXT')
                    && in_array(
                        'aes-256-gcm',
                        array_map('strtolower', openssl_get_cipher_methods()),
                        true
                    )
                ) {
                    return 'openssl';
                }
                break;
        }
        throw new RuntimeException("Forced crypto engine '$forced' not available");
    }

    return totp_crypto_engine();
}


/* ------------------------------------------------------------------ *
 |  4.  ENCRYPT / DECRYPT                                             |
 * ------------------------------------------------------------------ */

/** Encrypt plaintext with the site master key */
function totp_encrypt(string $plaintext): string
{
    if (!defined('TOTP_ENC_KEY')) {
        throw new RuntimeException('TOTP_ENC_KEY not defined; call totp_init_encryption() first.');
    }

    $key = base64_decode(TOTP_ENC_KEY, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Invalid TOTP_ENC_KEY');
    }

    switch (totp_get_active_crypto_engine()) {
        case 'sodium':
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct    = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return base64_encode($nonce . $ct);

        case 'openssl':
            $iv  = random_bytes(12);  // 96-bit IV for GCM
            $tag = '';
            $ct  = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($ct === false) {
                throw new RuntimeException('OpenSSL encryption failed');
            }
            return base64_encode($iv . $tag . $ct);

        default:
            throw new RuntimeException('No crypto backend available');
    }
}

/** Decrypt cipher-blob */
function totp_decrypt(string $blob): string
{
    if (!defined('TOTP_ENC_KEY')) {
        throw new RuntimeException('TOTP_ENC_KEY not defined; call totp_init_encryption() first.');
    }

    $key = base64_decode(TOTP_ENC_KEY, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Invalid TOTP_ENC_KEY');
    }

    $bin = base64_decode($blob, true);
    if ($bin === false) {
        throw new RuntimeException('Invalid encrypted blob (base64 decode failed)');
    }

    /* 1️⃣ Try current engine */
    $pt = totp_decrypt_with_engine($bin, $key, totp_get_active_crypto_engine());

    /* 2️⃣ If that fails, try legacy engine stored in key file */
    if (
        $pt === false
        && defined('TOTP_CRYPTO_ENGINE')
        && TOTP_CRYPTO_ENGINE !== totp_get_active_crypto_engine()
    ) {
        $pt = totp_decrypt_with_engine($bin, $key, TOTP_CRYPTO_ENGINE);
    }

    if ($pt === false) {
        throw new RuntimeException('TOTP secret decryption failed');
    }

    return $pt;
}

/**
 * Internal helper – decrypt using a specified engine
 *
 * @return string|false  plaintext or false on failure
 */
function totp_decrypt_with_engine(string $bin, string $key, ?string $engine)
{
    switch ($engine) {
        case 'sodium':
            if (
                !function_exists('sodium_crypto_secretbox_open')
                && !class_exists('ParagonIE_Sodium_Compat')
            ) {
                return false;
            }
            $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct    = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return sodium_crypto_secretbox_open($ct, $nonce, $key);

        case 'openssl':
            if (
                !defined('OPENSSL_VERSION_TEXT')
                || !in_array(
                    'aes-256-gcm',
                    array_map('strtolower', openssl_get_cipher_methods()),
                    true
                )
            ) {
                return false;
            }
            if (strlen($bin) < 28) {
                return false; // too short for IV+TAG+CT
            }
            $iv  = substr($bin, 0, 12);
            $tag = substr($bin, 12, 16);
            $ct  = substr($bin, 28);
            return openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        default:
            return false;
    }
}

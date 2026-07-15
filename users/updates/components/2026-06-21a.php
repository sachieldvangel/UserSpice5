<?php
// 2026-06-21a — Cloak credential-management policy.
//
// Passkey and TOTP enrolment now require step-up re-authentication for normal
// users (see passkeys.php / totp_management.php / passkey_parser.php). For a
// *cloaked* admin, whether they may manage the impersonated user's passkeys or
// TOTP is governed by usersc/includes/custom_cloak_policy.php (secure default:
// deny both). cloakCanManage() treats a missing file as deny, so this migration
// is not load-bearing — it only drops the documented template onto existing
// installs so site owners can discover and flip the knob. File creation only;
// a failure here must not block the migration, and an existing file is left
// untouched (it may contain the admin's customisations).
$policyFile = $abs_us_root . $us_url_root . 'usersc/includes/custom_cloak_policy.php';

if (!file_exists($policyFile)) {
    $template = <<<'EOT'
<?php
/*
 * Custom Cloak Policy
 * -------------------
 * Controls what a *cloaked* admin (one who used "log in as" / impersonation to
 * become another user) may do on that user's credential-management pages.
 *
 * Background: enrolling a passkey or TOTP factor is as sensitive as changing a
 * password. For a normal logged-in user, UserSpice gates those pages behind
 * step-up re-authentication (forceReauth / reauth.php). A cloaked admin has
 * already authenticated to start the cloak, so they are NOT asked to re-auth
 * again — instead, whether they may manage the impersonated user's passkeys or
 * TOTP at all is decided HERE.
 *
 * Secure default: BOTH are false. While cloaked, an admin cannot register or
 * delete the impersonated user's passkeys, nor enrol/disable their TOTP. They
 * are redirected back to the account page with a notice. (Admins can still
 * reset passwords as before — this file governs passkeys/TOTP only.)
 *
 * A missing file or an unset variable is treated as false, so deleting this
 * file is equivalent to denying both.
 *
 * Set either to true to ALLOW a cloaked admin to manage that factor for the
 * user they are impersonating. When either is true, the Security Dashboard
 * flags it as a deliberate, relaxed setting.
 */

$cloak_can_manage_passkeys = false; // allow cloaked admin to manage the target's passkeys?
$cloak_can_manage_totp     = false; // allow cloaked admin to manage the target's TOTP?
EOT;

    if (@file_put_contents($policyFile, $template) !== false) {
        logger(1, 'System Updates', 'Created usersc/includes/custom_cloak_policy.php (deny-by-default).');
    } else {
        logger(1, 'System Updates', 'Could not create usersc/includes/custom_cloak_policy.php - check file permissions. Cloaked passkey/TOTP management defaults to deny regardless.');
    }
}

include($abs_us_root . $us_url_root . "users/updates/components/_complete.php");

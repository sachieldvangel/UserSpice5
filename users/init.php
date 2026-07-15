<?php
/**
 * UserSpice is not configured yet.
 *
 * The installer rewrites this whole file from install/chunks/ once you supply your
 * database credentials. Until then it holds no configuration, so every page that
 * includes it lands here. Send visitors to the installer rather than failing with
 * an undefined-config error.
 *
 * If you are restoring a site, do not copy this file over a working install --
 * it will destroy the credentials the installer wrote.
 */

$abs_us_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
$self_path = explode('/', $_SERVER['PHP_SELF'] ?? '');
$self_path_length = count($self_path);
$us_url_root = '/';

for ($i = 1; $i < $self_path_length; $i++) {
	array_splice($self_path, $self_path_length - $i, $i);
	$candidate = implode('/', $self_path) . '/';

	if (file_exists($abs_us_root . $candidate . 'z_us_root.php')) {
		$us_url_root = $candidate;
		break;
	}
}

if (file_exists($abs_us_root . $us_url_root . 'install/index.php')) {
	if (!headers_sent()) {
		header('Location: ' . $us_url_root . 'install/index.php');
		exit;
	}
	exit('<p>UserSpice is not installed yet. <a href="' . htmlspecialchars($us_url_root) . 'install/index.php">Run the installer</a>.</p>');
}

exit('<p>UserSpice is not configured and the installer is missing. Restore users/init.php from a backup.</p>');

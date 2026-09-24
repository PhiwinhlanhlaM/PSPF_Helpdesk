<?php
/**
 * Shared <head> contents for every Vehicle Booking page.
 *
 * Usage (inside <head>, before any page-specific <style>):
 *     <?php $pageTitle = 'My Vehicle Requests'; require __DIR__ . '/partials/head.php'; ?>
 *
 * Optional variables:
 *     $pageTitle  string  Text shown before " · PSPF Transport Booking" in the tab.
 *     $legacyCss  bool    Load the older style5.css (default true). Auth pages set false.
 *
 * All libraries are served from vehicle_booking/assets so pages keep working
 * without internet access and never pick up an unplanned CDN upgrade.
 */
$vbAsset = static function (string $path): string {
    $file = dirname(__DIR__) . '/assets/' . $path;
    $ver  = is_file($file) ? filemtime($file) : '1';
    return 'assets/' . $path . '?v=' . $ver;
};
$vbTitle = isset($pageTitle) && $pageTitle !== ''
    ? $pageTitle . ' · PSPF Transport Booking'
    : 'PSPF Transport Booking';
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($vbTitle) ?></title>
    <link rel="icon" type="image/png" href="PSPFlogo.png">

    <link rel="stylesheet" href="<?= $vbAsset('lib/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= $vbAsset('lib/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= $vbAsset('lib/fontawesome/css/all.min.css') ?>">
<?php if ($legacyCss ?? true): ?>
    <link rel="stylesheet" href="style5.css">
<?php endif; ?>
    <link rel="stylesheet" href="<?= $vbAsset('css/theme.css') ?>">

    <!-- Loaded in <head> (not deferred) so inline page scripts can use `bootstrap.*` straight away. -->
    <script src="<?= $vbAsset('lib/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= $vbAsset('js/app.js') ?>"></script>

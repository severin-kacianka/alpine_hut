<?php
// layout.php — shared page head/nav/footer for hut_search.php, where2go.php, and slotfinder.php.
// Each page keeps its own PHP logic and page-specific <style> block; this file only unifies the
// shared shell (doctype/head/header/nav/footer) and the common stylesheet link.

/**
 * Render the shared <head>, <header>, and navigation bar. Leaves the document in HTML output
 * mode (PHP short-echo tags below), so the caller continues straight into its own markup.
 */
function render_page_head(string $title, string $heading, string $subtitle, string $active, string $extra_head = ''): void {
    $pages = [
        'hut_search' => ['href' => 'hut_search.php', 'label' => 'Hut Search'],
        'where2go'   => ['href' => 'where2go.php',   'label' => 'Where to Go'],
        'slotfinder' => ['href' => 'slotfinder.php', 'label' => 'Slot Finder'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="style.css">
<?php if ($extra_head !== ''): ?>
  <?= $extra_head ?>

<?php endif; ?>
</head>
<body>
<header>
  <h1><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>
  <p><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>
  <nav class="page-nav">
<?php foreach ($pages as $key => $page): ?>
    <a class="nav-tab<?= $key === $active ? ' nav-tab-active' : '' ?>" href="<?= htmlspecialchars($page['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($page['label'], ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
  </nav>
</header>

<?php
}

/**
 * Render the shared footer and closing tags. $credits are pre-built HTML strings appended, in
 * order, after the common "Data: SAC & OpenStreetMap" credit, one per API the page itself uses.
 */
function render_page_foot(array $credits = []): void {
    $lines = array_merge(
        ['Data: <a href="https://www.sac-cas.ch" target="_blank">SAC</a> &amp; <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a>'],
        $credits
    );
    ?>
<footer>
  <?= implode(" &mdash;\n  ", $lines) ?>

</footer>
</body>
</html>
<?php
}

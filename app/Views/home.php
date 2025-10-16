<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'KayraPHP', ENT_QUOTES, 'UTF-8') ?></title>

    <!-- ✅ External stylesheet -->
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="card">
    <h1><?= htmlspecialchars($title ?? 'Welcome', ENT_QUOTES, 'UTF-8') ?></h1>
    <p>This is the home page rendered successfully using <strong>KayraPHP</strong>.</p>
    <a href="/docs" class="btn">📘 View Documentation</a>
</div>

<?php if (is_dev()): ?>
    <div class="footer-dev">⚙️ Development Mode</div>
<?php endif; ?>

<?php echo is_dev() ? "DEV MODE" : "PRODUCTION MODE"; ?>

</body>
</html>
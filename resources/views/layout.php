<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?? 'KayraPHP' ?></title>
</head>
<body>
<header><h1>KayraPHP Framework</h1></header>
<main><?= $slot ?? '' ?></main>
<footer>Performance-first PHP</footer>
</body>
</html>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Administration — Connexion</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<main class="admin-shell admin-login-shell">
    <section class="admin-card">
        <p class="admin-eyebrow">Administration sécurisée</p>
        <h1>Connexion opérateur</h1>
        <p class="admin-lead">Accédez aux dossiers d’identité de votre organisation.</p>

        <?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
            <div class="admin-alert" role="alert"><?= esc($errorMessage) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/login" autocomplete="on">
            <?= csrf_field() ?>

            <label for="tenant">Organisation</label>
            <input id="tenant" name="tenant" type="text" maxlength="80" autocomplete="organization" required>

            <label for="email">Courriel</label>
            <input id="email" name="email" type="email" maxlength="191" autocomplete="username" required>

            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Se connecter</button>
        </form>

        <p class="admin-footnote">Les erreurs de connexion restent volontairement génériques.</p>
    </section>
</main>
</body>
</html>

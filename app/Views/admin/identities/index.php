<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Identités citoyennes | Administration</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<main class="admin-page">
    <header class="admin-topbar">
        <div>
            <p class="admin-eyebrow">Administration sécurisée</p>
            <h1>Vérification des identités</h1>
            <p class="admin-muted"><?= esc($tenantName) ?> · <?= esc($displayName) ?></p>
        </div>
        <form method="post" action="/admin/logout" class="admin-inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="admin-secondary">Se déconnecter</button>
        </form>
    </header>

    <section class="admin-panel">
        <div class="admin-panel-head">
            <div>
                <h2>File de traitement</h2>
                <p class="admin-muted">La liste n’affiche pas le NINU/CIN ni le téléphone. Ouvrez un dossier pour consulter les données sensibles.</p>
            </div>
            <?php if ($canManage): ?>
                <span class="admin-badge">Droits de décision actifs</span>
            <?php endif; ?>
        </div>

        <form method="get" action="/admin/identites" class="admin-filter-form">
            <label for="status">Statut</label>
            <select id="status" name="status">
                <?php foreach (['pending' => 'En attente', 'verified' => 'Vérifié', 'rejected' => 'Rejeté'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filtrer</button>
        </form>

        <?php if ($rows === []): ?>
            <div class="admin-empty">Aucun dossier pour ce statut.</div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Statut</th>
                        <th>Documents</th>
                        <th>Soumis le</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><code><?= esc(substr((string) $row['uuid'], 0, 12)) ?>…</code></td>
                            <td><span class="admin-status admin-status-<?= esc((string) $row['verification_status']) ?>"><?= esc((string) $row['verification_status']) ?></span></td>
                            <td><?= esc((string) $row['document_count']) ?></td>
                            <td><?= esc((string) $row['created_at']) ?> UTC</td>
                            <td><a class="admin-link" href="/admin/identites/<?= rawurlencode((string) $row['uuid']) ?>">Ouvrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

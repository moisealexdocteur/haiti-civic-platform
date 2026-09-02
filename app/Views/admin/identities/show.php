<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dossier d’identité — Administration</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<main class="admin-page">
    <header class="admin-topbar">
        <div>
            <p class="admin-eyebrow">Administration sécurisée</p>
            <h1>Dossier d’identité</h1>
            <p class="admin-muted"><?= esc($tenantName) ?> · <?= esc($displayName) ?></p>
        </div>
        <div class="admin-actions-row">
            <a class="admin-secondary admin-button-link" href="/admin/identites">Retour à la file</a>
            <form method="post" action="/admin/logout" class="admin-inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="admin-secondary">Se déconnecter</button>
            </form>
        </div>
    </header>

    <?php if ($decisionOk): ?>
        <div class="admin-alert admin-alert-success">La décision a été enregistrée.</div>
    <?php endif; ?>

    <?php if (is_string($decisionError) && $decisionError !== ''): ?>
        <div class="admin-alert" role="alert"><?= esc($decisionError) ?></div>
    <?php endif; ?>

    <section class="admin-grid-two">
        <article class="admin-panel">
            <div class="admin-panel-head">
                <div>
                    <h2>Identité</h2>
                    <p class="admin-muted">Données déchiffrées uniquement après contrôle de l’autorisation.</p>
                </div>
                <span class="admin-status admin-status-<?= esc((string) $identity['verification_status']) ?>"><?= esc((string) $identity['verification_status']) ?></span>
            </div>

            <dl class="admin-details">
                <div><dt>Référence</dt><dd><code><?= esc((string) $identity['uuid']) ?></code></dd></div>
                <div><dt>NINU / CIN</dt><dd class="admin-sensitive"><?= esc((string) $identity['ninu']) ?></dd></div>
                <div><dt>Téléphone</dt><dd class="admin-sensitive"><?= esc((string) ($identity['phone'] ?? 'Non fourni')) ?></dd></div>
                <div><dt>Consentement</dt><dd><?= esc((string) $identity['consent_version']) ?></dd></div>
                <div><dt>Consenti le</dt><dd><?= esc((string) $identity['consented_at']) ?> UTC</dd></div>
                <div><dt>Vérifié le</dt><dd><?= esc((string) ($identity['verified_at'] ?? '—')) ?></dd></div>
            </dl>
        </article>

        <article class="admin-panel">
            <h2>Décision</h2>
            <?php if (! $canManage): ?>
                <div class="admin-empty">Votre rôle permet la consultation, mais pas la modification du statut.</div>
            <?php elseif ($identity['verification_status'] === 'pending'): ?>
                <form method="post" action="/admin/identites/<?= rawurlencode((string) $identity['uuid']) ?>/statut" class="admin-decision-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to_status" value="verified">
                    <p>Confirmez la validation uniquement après vérification manuelle des pièces.</p>
                    <button type="submit" class="admin-success">Valider l’identité</button>
                </form>
                <hr>
                <form method="post" action="/admin/identites/<?= rawurlencode((string) $identity['uuid']) ?>/statut" class="admin-decision-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to_status" value="rejected">
                    <label for="reason_code">Motif du rejet</label>
                    <input id="reason_code" name="reason_code" type="text" maxlength="80" required placeholder="Ex. document_illisible">
                    <button type="submit" class="admin-danger">Rejeter le dossier</button>
                </form>
            <?php elseif ($identity['verification_status'] === 'rejected'): ?>
                <form method="post" action="/admin/identites/<?= rawurlencode((string) $identity['uuid']) ?>/statut" class="admin-decision-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to_status" value="pending">
                    <p>Remettre ce dossier dans la file pour un nouvel examen.</p>
                    <button type="submit">Remettre en attente</button>
                </form>
            <?php else: ?>
                <div class="admin-empty">Le statut vérifié est terminal dans la politique actuelle.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="admin-panel">
        <h2>Pièces de vérification</h2>
        <?php if ($identity['documents'] === []): ?>
            <div class="admin-empty">Aucune pièce enregistrée.</div>
        <?php else: ?>
            <div class="admin-document-grid">
                <?php foreach ($identity['documents'] as $document): ?>
                    <article class="admin-document-card">
                        <strong><?= esc((string) $document['document_type']) ?></strong>
                        <span>Révision <?= esc((string) $document['revision_no']) ?></span>
                        <span><?= esc((string) ($document['content_type'] ?? 'type inconnu')) ?></span>
                        <span><?= esc((string) ($document['size_bytes'] ?? '—')) ?> octets</span>
                        <a class="admin-link" target="_blank" rel="noopener" href="/admin/identites/<?= rawurlencode((string) $identity['uuid']) ?>/documents/<?= rawurlencode((string) $document['uuid']) ?>">Ouvrir la pièce</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-grid-two">
        <article class="admin-panel">
            <h2>Historique de vérification</h2>
            <?php if ($identity['events'] === []): ?>
                <div class="admin-empty">Aucun événement.</div>
            <?php else: ?>
                <ol class="admin-timeline">
                    <?php foreach ($identity['events'] as $event): ?>
                        <li>
                            <strong><?= esc((string) $event['event_type']) ?></strong>
                            <span><?= esc((string) $event['occurred_at']) ?> UTC</span>
                            <?php if ($event['from_status'] !== null || $event['to_status'] !== null): ?>
                                <span><?= esc((string) ($event['from_status'] ?? '—')) ?> → <?= esc((string) ($event['to_status'] ?? '—')) ?></span>
                            <?php endif; ?>
                            <?php if ($event['reason_code'] !== null): ?>
                                <span>Motif : <?= esc((string) $event['reason_code']) ?></span>
                            <?php endif; ?>
                            <span>Acteur : <?= esc((string) ($event['actor_display_name'] ?? 'Public / système')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </article>

        <article class="admin-panel">
            <h2>Traçabilité d’audit</h2>
            <?php if ($identity['audit'] === []): ?>
                <div class="admin-empty">Aucune entrée d’audit liée.</div>
            <?php else: ?>
                <ol class="admin-timeline">
                    <?php foreach ($identity['audit'] as $audit): ?>
                        <li>
                            <strong><?= esc((string) $audit['event']) ?></strong>
                            <span><?= esc((string) $audit['occurred_at']) ?> UTC</span>
                            <span>Acteur : <?= esc((string) $audit['actor_type']) ?></span>
                            <span>Requête : <code><?= esc((string) ($audit['request_id'] ?? '—')) ?></code></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </article>
    </section>
</main>
</body>
</html>

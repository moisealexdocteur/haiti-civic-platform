<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.usersHeading')) ?></h2>
        <p><?= esc(lang('Admin.usersLead')) ?></p>
    </div>
</section>

<?php if ($saved): ?>
    <p class="alert alert-ok" role="status"><?= esc(lang('Admin.userSaved')) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
<?php endif; ?>

<?php if ($canManage): ?>
    <details class="panel disclosure-panel">
        <summary><?= esc(lang('Admin.addUser')) ?></summary>
        <form method="post" action="/admin/utilisateurs" class="form-grid form-grid-spaced" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field">
                <label for="display-name"><?= esc(lang('Admin.displayName')) ?></label>
                <input id="display-name" name="display_name" maxlength="160" autocomplete="name" required>
            </div>
            <div class="field">
                <label for="user-email"><?= esc(lang('Admin.email')) ?></label>
                <input id="user-email" name="email" type="email" maxlength="191" autocomplete="email" required>
            </div>
            <div class="field">
                <label for="user-locale"><?= esc(lang('Admin.preferredLanguage')) ?></label>
                <select id="user-locale" name="locale" required>
                    <option value="ht"><?= esc(lang('Admin.languageHt')) ?></option>
                    <option value="fr"><?= esc(lang('Admin.languageFr')) ?></option>
                </select>
            </div>
            <div class="field">
                <label for="role-id"><?= esc(lang('Admin.role')) ?></label>
                <select id="role-id" name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= esc((string) $role['id'], 'attr') ?>"><?= esc((string) $role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field span-two">
                <label for="initial-password"><?= esc(lang('Admin.temporaryPassword')) ?></label>
                <input id="initial-password" name="password" type="password" minlength="14" maxlength="256" autocomplete="new-password" required>
                <p class="hint"><?= esc(lang('Admin.temporaryPasswordHelp')) ?></p>
            </div>
            <label class="confirm span-two">
                <input type="checkbox" name="is_owner" value="1">
                <span><?= esc(lang('Admin.makeOwner')) ?></span>
            </label>
            <div class="span-two">
                <button type="submit" class="btn btn-fit"><?= esc(lang('Admin.createUser')) ?></button>
            </div>
        </form>
    </details>
<?php endif; ?>

<section class="panel">
    <h2><?= esc(lang('Admin.membersTitle')) ?></h2>
    <div class="table-wrap">
        <table class="queue users-table">
            <thead>
            <tr>
                <th><?= esc(lang('Admin.name')) ?></th>
                <th><?= esc(lang('Admin.role')) ?></th>
                <th><?= esc(lang('Admin.status')) ?></th>
                <th><?= esc(lang('Admin.lastLogin')) ?></th>
                <?php if ($canManage): ?><th><span class="sr-only"><?= esc(lang('Admin.status')) ?></span></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php $active = (string) $user['status'] === 'active'; ?>
                <tr>
                    <td>
                        <b><?= esc((string) $user['display_name']) ?></b>
                        <span class="table-subline"><?= esc((string) $user['email']) ?></span>
                        <?php if ((int) $user['is_owner'] === 1): ?><span class="pill pill-owner"><?= esc(lang('Admin.owner')) ?></span><?php endif; ?>
                    </td>
                    <td><?= esc((string) ($user['roles'] ?: lang('Admin.noRole'))) ?></td>
                    <td><span class="pill <?= $active ? 'pill-enabled' : 'pill-disabled' ?>"><?= esc(lang($active ? 'Admin.active' : 'Admin.inactive')) ?></span></td>
                    <td><?= esc((string) ($user['last_login_at'] ?? lang('Admin.never'))) ?></td>
                    <?php if ($canManage): ?>
                        <td>
                            <form method="post" action="/admin/utilisateurs/<?= rawurlencode((string) $user['uuid']) ?>/statut">
                                <?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= $active ? 'inactive' : 'active' ?>">
                                <button type="submit" class="text-button <?= $active ? 'text-critical' : '' ?>"><?= esc(lang($active ? 'Admin.disable' : 'Admin.enable')) ?></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

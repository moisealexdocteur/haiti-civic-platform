# Interface administrative de vérification des identités

## Objet

Le Sprint 5 ajoute une interface administrative légère et tenant-scopée pour traiter les dossiers citoyens créés par le portail public.

Le périmètre couvre l’authentification d’un opérateur, la file de traitement, la fiche détaillée, la consultation protégée des pièces, les décisions de vérification et la traçabilité. Il ne couvre pas l’OTP réel, l’ONI/CEP externe, la reconnaissance faciale ni le stockage objet de production.

## Bootstrap du premier administrateur

La commande `php spark admin:bootstrap <tenant-slug> <email> <display-name>` est réservée au bootstrap d’un tenant qui ne possède encore aucun utilisateur.

Le mot de passe est transmis uniquement au processus via `ADMIN_BOOTSTRAP_PASSWORD`. La commande refuse :

- un tenant inexistant ou inactif ;
- un tenant possédant déjà une appartenance utilisateur ;
- un courriel déjà présent dans la table globale `users` ;
- un rôle `identity_admin` déjà présent ;
- un catalogue incomplet des permissions identité.

Le bootstrap crée un utilisateur actif, son appartenance tenant avec `is_owner = 1`, le rôle `identity_admin`, les permissions `identity.view` et `identity.manage`, puis une entrée d’audit `admin.bootstrap_created` avec acteur `system`.

Ce mécanisme n’est pas un système général de gestion des opérateurs. Les ajouts ultérieurs d’utilisateurs doivent passer par les services RBAC prévus pour le tenant.

## Authentification et session

L’authentification administrative vérifie simultanément :

- le tenant actif et non supprimé ;
- l’utilisateur actif et non supprimé ;
- l’appartenance tenant active ;
- le courriel ;
- le mot de passe avec `password_verify`.

Après authentification, la session conserve des identifiants tenant et utilisateur. Le filtre `adminauth` ne se contente pas de la présence des valeurs en session : il revérifie que l’utilisateur, le tenant et l’appartenance sont toujours actifs. Une désactivation invalide donc les requêtes administratives suivantes.

Les formulaires de connexion, déconnexion et décision utilisent CSRF.

## RBAC

`identity.view` est requis pour :

- ouvrir la file ;
- consulter une fiche ;
- déchiffrer NINU/CIN et téléphone ;
- consulter les documents protégés ;
- afficher l’historique et la traçabilité liée au dossier.

`identity.manage` est requis pour modifier le statut.

Un rôle de lecture seule peut donc inspecter un dossier sans pouvoir appeler le service de décision.

## Isolation tenant

Toutes les recherches administratives utilisent le `tenant_id` issu de la session validée. Les routes exposent des UUID et non les IDs numériques internes.

Une identité ou une pièce d’un autre tenant ne doit pas être résolue par le service du tenant courant. Le service de décision recherche également l’UUID dans le tenant courant avant de transmettre l’ID interne au service d’écriture existant.

## Données sensibles

Le NINU/CIN et le téléphone restent chiffrés en base. Ils sont déchiffrés uniquement après contrôle `identity.view` et ne sont pas inclus dans les journaux d’audit.

Les pages administratives sensibles utilisent `Cache-Control: no-store, private, max-age=0`.

## Documents

Les pièces restent sous `writable/uploads/identity/...`, jamais sous `public/`.

La lecture se fait via un contrôleur protégé. Avant de servir un fichier, le service :

1. vérifie le tenant, l’identité, l’UUID du document et son statut `active` ;
2. exige une référence `local://` conforme ;
3. reconstruit le chemin à partir des UUID tenant/citoyen et de l’identifiant d’objet ;
4. vérifie que le chemin réel reste sous le répertoire autorisé ;
5. compare la taille attendue ;
6. compare le SHA-256 avec `hash_equals` lorsqu’il est présent ;
7. sert le type MIME stocké parmi les types supportés.

La réponse utilise notamment `X-Content-Type-Options: nosniff` et `Cache-Control: no-store`.

Le stockage local est une cible POC. La cible production reste un stockage objet chiffré et séparé.

## Machine d’état et décisions

Les changements de statut ne sont pas faits en SQL dans le contrôleur. `AdminIdentityDecisionService` résout l’identité dans le tenant puis appelle `CitizenIdentityWriteService::transitionVerificationStatus()`.

Transitions actuelles :

- `pending -> verified` ;
- `pending -> rejected`, avec motif obligatoire ;
- `rejected -> pending` ;
- `verified` est terminal dans la politique actuelle.

Chaque décision réussie produit un événement `identity.verification_status_changed` et une entrée `citizen_identity.verification_status_changed` dans la chaîne d’audit.

## Validation POC effectuée

Sur le tenant `demo-citoyen`, le parcours manuel a validé :

- refus de la file sans session ;
- reprise interactive après mauvais mot de passe sans fermer la session SSH ;
- ouverture de la file `pending` ;
- déchiffrement contrôlé du dossier témoin ;
- présence des trois pièces ;
- refus du document sans session ;
- lecture autorisée avec SHA-256 identique ;
- rejet sans motif sans changement d’état ;
- `pending -> rejected` avec motif ;
- `rejected -> pending` ;
- événements et audit associés ;
- retour du dossier dans la file `pending` ;
- `/health` restant opérationnel.

## Limites avant production Internet

Le Sprint 5 ne doit pas être considéré comme un dispositif d’authentification production complet. Il reste notamment à prévoir dans le durcissement production : MFA administrateur, limitation des tentatives de connexion, politique de rotation/récupération des accès, supervision des connexions, secrets et sauvegardes de production, ainsi que stockage documentaire objet chiffré.

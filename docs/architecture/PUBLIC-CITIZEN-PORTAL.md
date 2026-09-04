# Portail citoyen public — Sprint 4

## Objectif

Le portail public permet à un citoyen de déposer un dossier de vérification d’identité dans le contexte d’un tenant actif. Le dossier est créé avec le statut `pending` et ne peut pas être validé, rejeté ou modifié par le visiteur public.

## Parcours

1. résolution du tenant actif par son `slug` public ;
2. choix de langue français / créole haïtien ;
3. saisie du NINU/CIN ;
4. saisie du téléphone haïtien ;
5. consentement explicite et versionné ;
6. dépôt CIN recto ;
7. dépôt CIN verso ;
8. dépôt portrait/selfie ;
9. validation serveur ;
10. création transactionnelle du dossier `pending` ;
11. écran de confirmation avec UUID public du dossier.

Le portrait est destiné uniquement à une vérification manuelle. Aucune reconnaissance faciale n’est utilisée.

## Frontière de confiance publique

Le contrôleur public n’accorde aucune permission `identity.manage` et ne crée aucun faux utilisateur d’administration. Le service `PublicIdentitySubmissionService` possède une surface volontairement limitée : création d’un nouveau dossier `pending`, insertion de ses trois références documentaires, création d’un événement métier et écriture dans l’audit.

Il n’expose aucune opération de lecture d’autres identités, de changement de statut, de validation, de rejet ou d’adhésion politique.

## Données sensibles

Le NINU/CIN est normalisé avant traitement, chiffré avec le service cryptographique existant et associé à une empreinte HMAC-SHA-256 déterministe tenant-scopée pour la déduplication.

Le téléphone est normalisé au format haïtien canonique puis chiffré. Le NINU/CIN, le téléphone, leurs ciphertexts et l’empreinte NINU ne doivent jamais être placés dans les URLs, messages utilisateurs ou contextes d’audit.

## Documents

Pour le POC :

- CIN recto : JPEG, PNG ou PDF ;
- CIN verso : JPEG, PNG ou PDF ;
- portrait : JPEG ou PNG ;
- limite applicative : 5 Mio par fichier ;
- limite globale POST : 18 Mio.

Le MIME est déterminé côté serveur avec `finfo`. Le nom fourni par le navigateur n’est pas utilisé comme nom de stockage.

Les fichiers sont copiés sous `writable/uploads/identity/<tenant-uuid>/<citizen-uuid>/` avec un identifiant aléatoire de 256 bits et des permissions restrictives. Ils ne sont jamais placés sous `public/`.

MariaDB conserve uniquement : type documentaire, révision, référence opaque `local://...`, MIME, taille, SHA-256, statut et horodatage. Aucun binaire n’est stocké en base.

En production, cet adaptateur local devra pouvoir être remplacé par un stockage objet privé et chiffré sans changer le contrat métier.

## Transaction et rollback

La création de l’identité, les métadonnées documentaires, l’événement de vérification et l’audit sont exécutés dans une transaction tenant-scopée protégée par le verrou d’audit existant.

Si un document est invalide ou si une écriture DB/audit échoue, la transaction est annulée et les fichiers déjà copiés pendant cette tentative sont supprimés.

## Audit

Une soumission publique crée :

- événement métier `identity.public_submitted` avec `actor_user_id = NULL` ;
- audit `citizen_identity.public_submitted` avec `actor_type = public` et `actor_user_id = NULL`.

Le contexte d’audit contient uniquement des métadonnées non sensibles nécessaires à la traçabilité : statut, présence du téléphone, version du consentement et types de documents.

## CSRF et runtime

La route POST du portail est explicitement protégée par le filtre CSRF CodeIgniter. L’auto-routing reste désactivé. Le runtime POC utilisé pour l’acceptation est en `CI_ENVIRONMENT=production`, afin que le Debug Toolbar ne soit pas injecté dans les pages recevant des données d’identité.

## Isolation multi-tenant

La déduplication NINU/CIN est tenant-scopée. Un même NINU synthétique peut donc exister dans deux tenants distincts avec des empreintes différentes, sans créer de jointure transversale entre organisations.

Aucune déduplication globale inter-tenant n’est revendiquée par ce Sprint.

## Préparation OTP

Le Sprint 4 ajoute uniquement l’abstraction de transport OTP sous `App\\Services\\Otp` :

- `OtpChannel` définit les canaux `whatsapp` et `sms` ;
- `OtpDeliveryRequest` transporte temporairement le destinataire normalisé, le code et sa durée de vie ;
- `OtpTransportInterface` définit le contrat d’un fournisseur ;
- `OtpDeliveryResult` normalise succès ou rejet fournisseur ;
- `OtpChannelRouter` applique par défaut la préférence WhatsApp puis SMS.

Aucun fournisseur réel n’est intégré dans ce Sprint. Aucun message WhatsApp ou SMS n’est envoyé et aucun OTP n’est persisté. La comparaison des coûts, la délivrabilité, la génération/stockage sécurisé des OTP, les limites de tentatives et l’intégration Meta/Twilio restent des travaux ultérieurs.

## Validation Sprint 4

L’acceptation manuelle E2E du POC a démontré :

- POST CSRF fonctionnel ;
- création d’un dossier synthétique `pending` ;
- NINU/CIN et téléphone chiffrés ;
- empreinte NINU sur 64 caractères hexadécimaux ;
- trois documents stockés hors `public/` ;
- références documentaires opaques ;
- événement et audit publics ;
- absence du NINU/CIN et du téléphone en clair dans la réponse et les logs contrôlés ;
- Debug Toolbar absent ;
- `/health` opérationnel.

Les tests automatisés ciblés couvrent également la soumission publique, l’isolation tenant, la déduplication, le rollback documentaire et le routage OTP WhatsApp vers SMS.

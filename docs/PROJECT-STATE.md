# État du projet

Dernière mise à jour : 5 septembre 2026.

## Référence de production

- Dernière référence `main` connue avant l'itération notifications : `f55154441b9bcc5f6767040a602163ab7988a949`.
- Le déploiement de référence répond avec un statut HTTP 200 pour la santé, l'accueil, la connexion administrateur, la PWA et les ressources Leaflet.
- Les sauvegardes de la base et du dossier `writable` sont réalisées avant chaque déploiement majeur.

## Contrat visuel figé

- Nom unique : Portail de vérification citoyenne.
- Même symbole bleu et rouge, même favicon et même identité PWA sur toutes les pages.
- Interface claire, sobre, mobile en premier et cohérente entre le portail citoyen et l'administration.
- Feuilles de style publiques servies de manière déterministe pour éviter les régressions de cache.
- Français et kreyòl maintenus à parité par un test automatisé.
- Aucun tiret long ni emoji dans les textes de l'interface.

## Fonctions déjà livrées

- Parcours citoyen en quatre étapes avec NINU à dix chiffres, contact, trois photos, consentement et confirmation.
- Référence publique courte, suivi protégé et code QR.
- Répertoire des 105 structures politiques agréées par le CEP.
- Administration des dossiers avec filtres, tri, pagination, export PDF et Excel, réimpression et renvoi de confirmation.
- Carte Leaflet agrégée par département, sans géolocalisation individuelle.
- Contrôle ONI manuel, explicite et audité. Aucune extraction automatique du service officiel.
- Configuration WhatsApp, SMS et SMTP avec test, validation, diagnostic et suppression par canal.
- Chiffrement des NINU, téléphones et secrets des fournisseurs, avec séparation stricte par structure politique.
- Saisie du prénom, du nom et du courriel citoyen, chiffrés au repos, et choix explicite du canal de contrôle disponible.
- Action de lecture de carte intégrée au champ NINU avec préremplissage local lorsque le navigateur le permet.

## Itération en cours

- File transactionnelle durable avec idempotence, verrouillage concurrent, reprises espacées et historique des tentatives.
- Messages français et kreyòl pour les dossiers, les décisions, les confirmations, les comptes, les rôles, la sécurité et le mode terrain.
- Routage WhatsApp, SMS et courriel avec repli automatique vers les fournisseurs validés.
- Centre administratif des messages envoyés, en attente, à relancer, annulés ou en échec.
- Numéro de notification chiffré et canal préféré pour les administrateurs et agents terrain.
- Rapports quotidiens par structure et par zone terrain.

## Limites et étapes suivantes

- La lecture actuelle de la carte est une amélioration progressive fondée sur les capacités natives du navigateur. Une reconnaissance OCR fiable sur tous les téléphones exigera un moteur spécialisé, une étude de protection des données et des tests sur de vraies cartes.
- WhatsApp doit encore être configuré avec un modèle Meta approuvé, testé sur un destinataire réel, puis validé dans l'administration.
- Le statut d'envoi confirme l'acceptation par le fournisseur. La remise finale, les rebonds et la lecture demanderont une itération de webhooks Meta/Twilio/SMTP.
- Toute fusion vers `main` et tout déploiement de l'itération notifications restent soumis au passage des tests automatisés et à une sauvegarde SQL et `writable` valide.

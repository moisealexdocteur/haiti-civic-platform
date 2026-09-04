# État du projet

Dernière mise à jour : 4 septembre 2026.

## Référence de production

- Commit actuellement validé en production : `23e9d23e2a1377eac04b04f92bac6c45f73b286e`.
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

## Itération en cours

- Afficher au citoyen uniquement les canaux activés, testés et validés par l'administrateur.
- Permettre de choisir WhatsApp, SMS ou courriel avant l'envoi du code.
- Ajouter le prénom, le nom et le courriel au dossier, chiffrés au repos.
- Placer l'action de lecture de la carte directement dans le champ NINU.
- Préremplir le NINU et, lorsque le navigateur le permet, le prénom et le nom détectés sur l'appareil. Le citoyen doit toujours vérifier et confirmer les valeurs.
- Montrer dans les réglages si chaque canal est réellement visible dans le parcours citoyen.

## Limites et étapes suivantes

- La lecture actuelle de la carte est une amélioration progressive fondée sur les capacités natives du navigateur. Une reconnaissance OCR fiable sur tous les téléphones exigera un moteur spécialisé, une étude de protection des données et des tests sur de vraies cartes.
- Le téléphone reste demandé comme contact de dossier, même lorsque le code est reçu par courriel. Un parcours réellement sans téléphone nécessiterait une évolution du modèle des défis OTP et du suivi.
- WhatsApp doit encore être configuré avec un modèle Meta approuvé, testé sur un destinataire réel, puis validé dans l'administration.
- Toute fusion vers `main` et tout déploiement de cette itération restent soumis à une validation explicite après le passage des tests automatisés.

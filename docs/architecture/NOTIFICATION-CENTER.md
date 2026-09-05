# Centre de notifications transactionnelles

## Principes de production

- Un événement métier est validé avant l'envoi d'un message.
- Le message est placé dans une file durable. Une panne de fournisseur ne doit jamais annuler une décision ou une soumission.
- Les coordonnées, l'objet et le corps sont chiffrés au repos avec une clé liée au tenant.
- Le NINU, les documents et les notes internes ne sont jamais inclus dans un message.
- Chaque tentative est historisée avec un code d'erreur exploitable, sans secret ni coordonnée complète.
- Une clé d'idempotence empêche les doublons lors d'une reprise HTTP ou d'un redémarrage.
- Les modèles existent en français et en kreyòl, avec un test automatique de parité.
- WhatsApp transactionnel requiert un modèle Meta approuvé distinct du modèle OTP. Sans ce modèle, le système utilise SMS puis courriel.

## Matrice des événements

| Domaine | Événement | Citoyen | Administrateurs | Terrain |
|---|---|---:|---:|---:|
| Contact | Code OTP demandé | Oui, canal disponible | Non | Non |
| Dossier | Soumission reçue | Référence et lien de suivi | Nouveau dossier | Si mode terrain actif, selon département |
| Dossier | Contact à contrôler manuellement | Accusé sans fausse promesse | Alerte prioritaire | Selon département |
| Décision | Identité vérifiée | Décision et suivi | Non | Agent terrain concerné |
| Décision | Identité rejetée | Décision et prochaine démarche générique | Non | Agent terrain concerné |
| Décision | Retour en attente | Demande de suivi | Non | Agent terrain concerné |
| Confirmation | Renvoi demandé par un admin | Confirmation et référence | Tracé dans le centre | Non |
| Compte | Administrateur créé | Non | Utilisateur concerné | Si utilisateur terrain |
| Compte | Compte activé ou désactivé | Non | Utilisateur concerné | Si utilisateur terrain |
| Accès | Rôle attribué, retiré ou permissions modifiées | Non | Utilisateur concerné | Si utilisateur terrain |
| Accès | Statut d'administrateur principal modifié | Non | Utilisateur concerné | Si utilisateur terrain |
| Sécurité | Réinitialisation demandée | Non | Lien à l'utilisateur, puis alerte après changement | Non |
| Sécurité | Mot de passe modifié | Non | Alerte à l'utilisateur concerné | Non |
| Accès | Mode terrain activé ou modifié | Non | Utilisateur concerné | Oui |
| Exploitation | Rapport quotidien | Non | Volumes et notifications en échec | File de son département |

Il n'existe actuellement aucun domaine de paiement ou de transaction financière dans l'application. Aucun faux événement financier n'est donc créé. La file accepte de nouveaux `event_key` lorsque ce domaine sera implémenté.

## Routage

Pour un citoyen, le canal qui a réussi lors du contrôle du contact est essayé en premier, puis les canaux validés disponibles. Pour un administrateur ou un agent terrain, la préférence de son profil est utilisée; son courriel reste le repli final et son numéro de notification est chiffré. WhatsApp n'est sélectionné pour un message transactionnel que si le modèle Meta générique a été configuré et approuvé. Les tentatives sont plafonnées et espacées de façon exponentielle.

## Mode terrain

Le mode terrain est une propriété explicite du membre administratif. Il peut être limité à un département. Un agent sans département reçoit la file de toute la structure; un agent affecté reçoit seulement les dossiers de son département. Son profil accepte un téléphone de notification et le choix automatique, WhatsApp, SMS ou courriel.

## Exploitation

La commande `php spark notifications:work --once` traite un lot et se termine. Le service Docker `notifications` exécute `php spark notifications:work` en continu et produit les rapports quotidiens. L'écran **Notifications** permet de filtrer, consulter, relancer ou annuler un message. Les messages définitivement échoués restent visibles pour intervention. Le statut `sent` signifie que le fournisseur a accepté le message; la preuve de lecture ou de remise finale exigera les webhooks propres à chaque fournisseur.

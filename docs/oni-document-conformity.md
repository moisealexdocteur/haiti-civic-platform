# Acceptation automatique ONI et navigation : version 2

État : code publié sur la branche de travail GitHub après accord explicite, sans fusion ni déploiement. Base main vérifiée : `7077879c93c8e03413638dbc4d9c5bde20c71683`. La branche `feature/oni-document-conformity` conserve le centre de notifications et le design existants.

## Décision automatique

Lors d’une nouvelle inscription, le serveur analyse le recto et le portrait séparé. Il cherche une carte portant les libellés haïtiens attendus et un visage imprimé, recadre la carte quand ses contours sont exploitables, corrige la perspective ou essaie les orientations, puis lit le NINU. Le numéro saisi n’est jamais transmis au moteur OCR pour influencer sa lecture.

Les conditions d’acceptation sont : carte reconnue, NINU lu correspondant au NINU saisi, et portrait humain exploitable détecté. Le contrôle distingue le NINU numérique de dix chiffres du numéro alphanumérique de carte. Les noms continuent d’être normalisés et chiffrés ; leur lecture OCR n’est plus une condition supplémentaire d’acceptation.

Le dossier passe directement à `auto_accepted` dans la transaction de soumission. Il ne reste donc pas en attente d’un administrateur. Le citoyen voit cette acceptation sur la confirmation et dans son suivi protégé. Les listes, filtres, exports, tableau de bord, carte départementale et notifications connaissent ce statut.

Les images sans carte reconnue, les portraits absents, multiples ou inexploitablement cadrés, les NINU illisibles ou différents et les erreurs du moteur conduisent à `pending`, avec un motif consultable dans la fiche administrateur. Envoyer exactement le même fichier comme carte et comme portrait conduit également à une revue manuelle. Le statut du contact et les règles OTP existantes restent distincts.

Un administrateur peut rejeter un dossier accepté automatiquement, le remettre en revue manuelle avec un motif, ou procéder à la vérification ONI existante. `verified` et `verified_at` restent réservés au parcours officiel existant. L’acceptation automatique ne se présente jamais comme une authentification auprès de l’ONI.

## Règles de lecture et limites

L’analyse utilise OpenCV, Pillow et Tesseract français/anglais, localement. Aucun entraînement sur les photos personnelles et aucun appel OCR externe. Le détecteur de visage est un modèle générique OpenCV, avec sa licence conservée.

Une lecture unique doit atteindre 80 de confiance OCR. Une valeur confirmée par au moins deux passes distinctes peut être retenue à partir de 65. Les doublons d’un même passage ne constituent pas plusieurs confirmations. Un seul consensus est privilégié ; des consensus contradictoires restent en revue manuelle. Ces seuils sont des règles techniques de cette version, pas des probabilités d’authenticité statistiquement certifiées. Aucune substitution automatique de lettres en chiffres.

Le portrait est contrôlé pour la présence d’un visage suffisamment grand et non coupé. Cela n’établit ni la vivacité, ni la correspondance entre la personne et le titulaire de la carte. Une photographie de visage imprimé peut être détectée comme un visage. La puce, les hologrammes, le verso, les dates d’expiration et le registre ONI ne sont pas authentifiés par cette analyse.

JPEG/PNG sont traités automatiquement, dans les limites de taille existantes et jusqu’à seize millions de pixels avant réduction. Les PDF restent acceptés à l’envoi mais passent en revue manuelle. Les images sont réduites pour la lecture. Un seul traitement s’exécute à la fois ; les limites de temps conduisent à la revue manuelle plutôt qu’à une acceptation non contrôlée. L’appel PHP est borné à dix-huit secondes. La charge du conteneur sur le VPS reste à mesurer.

Les photos, leurs recadrages et les transcriptions d’essai restent hors Git. L’événement et l’audit ne contiennent que le résultat, la méthode et les codes de raisons, pas le numéro OCR ou le texte lu.

## Résultats des essais

Sur les onze rectos fournis (cinq anciens, six nouveaux), sept permettent de rapprocher correctement le NINU lu du numéro visible sur la carte. Quatre restent en revue manuelle : trois NINU non confirmés avec assez de certitude et une carte non reconnue avec NINU non confirmé. Aucun des onze rectos n’a été accepté sur un numéro différent du numéro de référence.

Ce résultat concerne les cartes et leur NINU, pas onze dossiers complets : aucun lot de portraits citoyens séparés n’a été fourni. La détection positive d’un portrait a été essayée localement sur un recadrage de visage issu d’une carte, uniquement comme test technique du détecteur. Le parcours PHP → Python → OCR → règle de décision a retourné `conformant` sur un recto exploitable accompagné de ce portrait d’essai. Aucun dossier réel n’a été créé.

- 112 tests unitaires PHP, 2 308 assertions : réussis.
- 6 tests Python : réussis, avec données synthétiques uniquement.
- Vérification de syntaxe des changements PHP/Python/JavaScript : réussie.
- Rendu HTML testé pour les retours citoyen et administrateur ; contrôle des protections de la route de consultation des documents.
- Test MariaDB ajouté pour vérifier l’insertion atomique en `auto_accepted`, la traçabilité et l’absence de `verified_at`. Non exécuté : le serveur local de test ne peut pas ouvrir sa socket dans cet environnement.
- Construction Docker, suite complète avec MariaDB, essais navigateur de bout en bout et charge du VPS : à exécuter avant livraison en production. Les tests d’image sont ajoutés au pipeline existant.

## Navigation corrigée

| Parcours | Changement |
|---|---|
| Pages publiques | Retour à l’accueil ; accès à l’administration si la session administrative est active et validée côté serveur. |
| Pages administratives | Liens vers le tableau de bord et le portail citoyen, avec les permissions des routes existantes. |
| Fiches d’identité et notifications | Retour à la liste en conservant les filtres, le département et la pagination ; contexte séparé par utilisateur et organisation. |
| Changement de langue ou de thème | Conservation des paramètres autorisés des listes. |
| Consultation d’une pièce | Nouvelle page protégée avec retour à la fiche, à la liste et au tableau de bord ; le fichier brut reste protégé. |
| Confirmation imprimable | Liens de retour, masqués à l’impression avec les autres actions. |
| Connexion et récupération | Retour à l’accueil ou à la connexion ; administration pour une session active. |
| Erreurs administratives | Retour vers `/admin`, qui vérifie la session et redirige vers la connexion si nécessaire. |
| Formulaire citoyen | Les retours entre étapes gardent les données ; protection contre une sortie accidentelle dès les premières saisies. |

Les retours utilisent des destinations internes connues, pas une URL fournie par l’utilisateur ou l’en-tête Referer. Aucun changement de palette ou refonte des écrans.

## Mise en service et retour arrière

La base accepte déjà le nouveau statut dans son champ VARCHAR ; aucune migration supplémentaire n’est nécessaire. Le Dockerfile ajoute OpenCV et les données françaises de Tesseract aux dépendances de la première version.

Avant fusion/déploiement : construire l’image, exécuter les migrations et tests sur une base isolée, vérifier les parcours d’acceptation et de revue manuelle ainsi que les accès administratifs, puis mesurer la mémoire et la durée d’une inscription. Ne pas utiliser les cartes personnelles dans la CI publique.

`ONI_OCR_ENABLED=0`, appliqué en recréant le service app, désactive les nouvelles décisions automatiques et conserve les dossiers déjà enregistrés. Les dossiers `auto_accepted` restent lisibles avec le code de cette version. Un retour à une ancienne version ignorant ce statut exige de prévoir leur traitement ; ne pas assimiler ces dossiers à des identités vérifiées ONI.

La publication sur la branche de travail du dépôt public a été explicitement autorisée le 9 septembre 2026. Le contenu publié comprend le code, les tests synthétiques et le modèle générique OpenCV, jamais les cartes personnelles, leurs recadrages ou leurs transcriptions OCR.

Références techniques : [OpenCV, détection par cascade](https://docs.opencv.org/4.13.0/db/d28/tutorial_cascade_classifier.html), [Tesseract, sortie TSV et langues](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html).

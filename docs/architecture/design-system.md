# Design system « Ble Sitwayen »

Un seul jeu de jetons pour le portail citoyen et l'administration :
`public/assets/tokens.css`. Aucune police n'est téléchargée — pile système.

## Couleurs

| Rôle | Clair | Sombre |
|---|---|---|
| `--paper` fond application | `#ECEEF2` | `#0D1117` |
| `--surface` cartes, champs | `#FAFBFD` | `#141A22` |
| `--surface-2` retrait | `#F1F3F7` | `#1B2330` |
| `--ink` / `--ink-2` / `--ink-3` | `#12161C` / `#39424F` / `#5F6B7C` | `#E6EAF0` / `#B0B9C6` / `#8E98A8` |
| `--line` / `--line-strong` | `#D6DAE2` / `#838C9E` | `#262F3C` / `#5A6878` |
| `--accent` action unique | `#15398C` | `#7BA1EE` |
| `--accent-ink` / `--accent-soft` | `#0E2A6B` / `#E3E9F7` | `#A8C4F7` / `#17243D` |
| `--accent-on` texte du bouton | `#FFFFFF` | `#0D1117` |
| `--flag` sceau + focus | `#00209F` | `#4E7BE0` |
| `--critical` rejet | `#A8322B` | `#E9908A` |
| `--warn` en attente | `#8A6410` | `#DCA94F` |
| `--doc-bg` fond des pièces | `#E8EAEE` | `#E8EAEE` |

Règles non négociables :

1. `--accent` est la **seule** couleur cliquable. Il n'existe pas de jeton de
   succès : la réussite est bleue et se lit à l'icône et au mot.
2. Aucun vert nulle part.
3. `--flag` ne sert qu'au sceau de l'organisation et à l'anneau de focus.
4. Le rouge ne sert qu'au rejet, à la suppression et à l'erreur bloquante.
5. `--doc-bg` ne change pas entre les thèmes : on ne décide jamais d'une
   vérification d'identité sur une image assombrie.
6. Pas d'ombre portée en thème sombre : l'élévation passe par la surface.

Tout le texte passe WCAG AA dans les deux thèmes ; les bordures interactives
passent le seuil 3:1.

## Thème

`data-theme` est écrit sur `<html>` par le serveur, depuis le cookie `theme`
(`App\Controllers\Concerns\PublicPage::resolveTheme()`). Sans cookie, aucun
attribut n'est écrit et `prefers-color-scheme` décide. `?theme=auto|light|dark`
change le cookie.

## Langue

Le kreyòl est la langue par défaut (`PublicPage::resolveLocale()`), dans
l'ordre : `?lang=`, cookie `lang`, défaut du tenant, `Accept-Language`, puis
`ht`. La langue est mémorisée en cookie : un lien qui oublie `?lang=` ne
renvoie plus le citoyen en français.

## Parcours citoyen

`public/assets/portal-wizard.js` pilote une machine à états : un écran, une
décision. Aucune chaîne de texte n'est écrite dans le script — elles arrivent
de la couche de langue via le bloc JSON `#wizard-strings`.

Points structurants :

- les photos sont redimensionnées à 1600 px de côté et compressées en JPEG
  qualité 0,72 dans le navigateur avant l'envoi (≈ 250–500 Ko au lieu de 3–5 Mo) ;
- l'envoi passe par `XMLHttpRequest` pour afficher une progression réelle ;
- une erreur serveur renvoie du JSON : la page n'est pas re-rendue, donc les
  photos déjà prises ne sont pas détruites ;
- POST-Redirect-GET vers `/inscription/{tenant}/konfimasyon` : un F5 ne rejoue
  plus l'envoi ;
- sans JavaScript, les écrans restent lisibles à la suite et un message
  l'explique (l'OTP et la compression exigent JS).

## Gabarits

`app/Views/layouts/public.php` et `app/Views/layouts/admin.php`. Les sept
documents HTML complets dupliqués ont été supprimés.

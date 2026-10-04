# Plugin Réveils

Jeedom est la **source de vérité** des réveils. Toutes les 5 minutes, le plugin calcule le prochain réveil de chaque équipement, le programme sur les cibles (Alexa, Rémi), puis **relit la cible pour vérifier**.

## Calcul d'un jour (par priorité)

1. **Exception** : une date avec une heure, ou « pas de réveil ».
2. **Mes congés** : la liste est dans la configuration du plugin.
3. **Jour férié** : calculé localement, Pâques compris.
4. **Vacances scolaires** : académie configurée, via data.education.gouv.fr, rafraîchies chaque jour.
5. **Profil de la semaine**, choisi dans cet ordre :
   - l'affectation explicite de la semaine ISO ;
   - sinon la garde alternée (profil « avec enfant » ou « sans ») ;
   - sinon le profil par défaut.

Pour chacun des points 2 à 4, la règle au niveau de l'équipement peut être « Aucun effet », « Pas de réveil » ou « Profil X ». Le réveil des enfants peut par exemple suivre les vacances scolaires, et le tien tes congés.

**Saisie rapide** d'un profil, par exemple : `lun-ven 6:45, mer 7:30, sam-dim off`. Seuls les jours cités sont modifiés.

## Programmation et vérification

**Changements faits sur l'appareil** (option « Accepter les changements faits sur l'appareil », cochée par défaut) : si un réveil déjà vérifié est ensuite modifié à la main, le plugin garde ce changement comme **exception pour ce jour-là** (en orange sur le widget) et l'applique aussi aux autres appareils. Cela vaut pour une autre heure le même jour dans l'appli Rémi ou à la voix sur l'Alexa, ou pour un réveil désactivé ou supprimé. Les autres écarts, comme un autre jour ou plusieurs jours cochés, ne sont pas adoptés : le planning est réappliqué. Option décochée : le planning est toujours réappliqué. Pour le Rémi, la programmation est confirmée dès l'écriture, donc un changement fait dans l'appli est reconnu même juste après une programmation du plugin.

- **Changement de prochain réveil** : le plugin programme les cibles, relit au bout d'environ 2 minutes (au cron suivant), puis compare.
- **Écart constaté** : le plugin reprogramme, jusqu'à *N* essais (réglable dans la configuration). Au-delà, il envoie un message sur la **commande d'alerte**, une seule fois par réveil.
- **Contrôles de routine** : à chaque passage du cron (5 minutes), plus un contrôle 45 minutes avant le réveil. Pour le Rémi, la lecture est légère : le plugin lit les commandes de JeeRemi, qui relit le cloud toutes les 5 minutes.
- **Rémi seul** : la programmation est vérifiée dès l'écriture, et le statut passe directement à OK.
- **Commandes info** :
  - `Statut synchro` : texte détaillé de l'état ;
  - `Synchro OK`, `Alexa OK`, `Rémi OK` : binaires.

### Alexa (via le plugin alexaapi)

Il faut sélectionner les commandes de l'Echo :

- **Créer une alarme** : commande de type message. Le texte envoyé dans `title` et `message` suit le **modèle** configuré (par défaut `#date# #time#:00`). **À valider sur ton installation**, car la syntaxe dépend de la version d'alexaapi.
- **Prochaine alarme** : commande info, relue pour la vérification. Les formats `AAAA-MM-JJ HH:MM`, `HHmm` et timestamp sont acceptés.
- **Supprimer les alarmes** (optionnel) : exécutée avant chaque programmation. Attention, elle supprime aussi les alarmes réglées à la voix sur cet Echo.
- **Rafraîchir** (optionnel).

### Rémi (via le plugin JeeRemi)

Le plugin passe **uniquement par les commandes de JeeRemi**, sans appel à son API interne :

- **Lecture** : les commandes `alarm_…` de JeeRemi. Leur nom donne l'heure et les jours (« 06:30 – reveil (lun-ven) »), leur valeur binaire donne l'activation. La commande `Rafraîchir` de JeeRemi est lancée avant chaque lecture.
- **Écriture** : la commande `event_set_param` (`time;HH:MM`, `recurrence;…`, `enabled;1|0`). Seul ce qui change est envoyé.
- **Prérequis** : la version modifiée de JeeRemi qui affiche les jours dans le nom. Sans elle, le jour ne peut pas être vérifié ; l'heure et l'activation le sont toujours.
- **Réglage** : tu choisis ton équipement JeeRemi, puis le réveil piloté dans la liste. Crée-le d'abord dans l'appli Rémi avec le son et le visage voulus. Si deux réveils portent le même nom (par exemple deux réveils sans nom affichés « Réveil »), celui choisi est renommé « Jeedom ».
- **Un seul jour actif** : le réveil n'est activé que pour le jour du prochain réveil, et il est désactivé quand aucun réveil n'est prévu.
- **Fenêtre de 7 jours** : le Rémi ne gère que des jours de semaine, pas des dates. Un réveil « jeudi » sonnerait donc au prochain jeudi. Il n'est armé que si le prochain réveil tombe dans les 7 jours ; sinon il reste désactivé et s'arme automatiquement dès l'entrée dans la fenêtre.
- **Ordre des jours** : si un réveil du lundi apparaît « (mar) » dans JeeRemi, coche « Semaine Rémi commence le dimanche ».

### Vérification par tes commandes existantes

La vérification lit les **commandes info que tu as déjà**, qui renvoient l'heure programmée sur l'appareil :

- **Alexa** : champ « Heure de la prochaine alarme (info existante) ».

Formats acceptés : `AAAA-MM-JJ HH:MM`, `HH:MM`, `HHmm`, `6h45` ou timestamp. Une valeur vide ou non horaire signifie « aucun réveil ». Si seule l'heure est renvoyée, seule l'heure est comparée.

### Présence

- **Réglages** : une commande info binaire de présence (1 = présent, case « 0 = présent » pour inverser), une heure de contrôle (04:00 par défaut) et une action « si absent ».
- **Contrôle** : une fois par jour, à l'heure de contrôle. Si tu es absent et qu'un réveil est prévu plus tard dans la journée, le plugin exécute l'action, puis marque le jour **absent**. Ce réveil n'est alors plus reprogrammé, et la vérification s'attend à ce qu'il soit supprimé. Le réveil suivant est programmé normalement.
- **Retour avant l'heure du réveil** : le jour reste marqué absent. Pour annuler, supprime l'exception dans l'onglet Calendrier.

## Garde alternée

- **Réglage global** (configuration du plugin) : les semaines avec ton enfant, paires ou impaires.
- **Réglage par réveil** (onglet Profils) : un profil « Semaine avec mon enfant » et un profil « Semaine sans ». S'ils sont laissés vides, c'est le profil par défaut qui s'applique.
- **Calcul** : parité du numéro de semaine. La S53 (fin 2026) est une semaine impaire.
- **Jour ponctuel** : sur le widget, choisis le crayon 🔵 **Enfant**, puis clique un jour pour inverser sa garde. Un jour impair devient « avec enfant », un jour pair devient « sans ». Un second clic revient à la règle pair/impair.
- **Semaine entière** : l'onglet Calendrier permet toujours de forcer le profil d'une semaine précise.

## Widget

- **Affichage** : le prochain réveil (pastille verte si vérifié, rouge sinon), puis un calendrier glissant de 4 semaines (semaine en cours + 3) avec l'heure de chaque jour. Les jours passés ne sont plus affichés : chaque lundi, la semaine écoulée disparaît et une nouvelle apparaît.
- **Tout se règle depuis le widget** : le bouton ⚙ ouvre la même fenêtre de réglage que la page de l'équipement (profils de semaine et du jour, règles, garde, semaines, exceptions, aperçu), avec un bouton Enregistrer. Seules les commandes Alexa, Rémi et présence restent dans la configuration de l'équipement, car elles ne se règlent qu'une fois.
- **Premier lancement** : un widget sans horaire affiche un bouton « Configurer les horaires ».
- **Accès** : un utilisateur non administrateur peut modifier les horaires depuis le dashboard s'il a les droits d'écriture sur l'équipement.
- **Crayons** en haut : ✎ **Heure**, 🔴 **Congé**, 🔵 **Enfant**, plus un crayon par profil du jour. Le crayon choisi est mémorisé sur l'appareil.
- **Crayon Heure** : un appui sur un jour ouvre une bulle avec trois boutons : une heure (OK), « Pas de réveil », ou « Normal » (retour au calcul habituel).
- **Crayon Congé, un clic sur un jour** le bascule en **congé** (rouge), un second clic l'annule. Le congé est **global** : tous les réveils qui suivent tes congés sont recalculés et reprogrammés. Un clic au milieu d'une période de congés la découpe automatiquement.
- **Numéros de semaine** à gauche, avec l'icône enfant (bleue) sur les semaines de garde.
- **Couleurs** : rouge = congé, contour orange = autre heure ce jour-là (un jour mis sur « Pas de réveil » reste une case vide normale), gris = férié, barre bleue en bas = jour avec ton enfant, « — » = pas de réveil.

## Commandes action

- **Resynchroniser**.
- **Pas de prochain réveil** : ajoute une exception « off » à la date du prochain réveil.
- **Exception** : titre = `AAAA-MM-JJ`, message = `HH:MM`, `off`, `absent` ou vide (vide supprime l'exception).

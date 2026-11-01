# Journal des modifications — Plugin Réveils

Format des versions : `majeure.mineure.correctif`. La version installée est affichée dans la configuration du plugin et dans le log au moment de la mise à jour.

## 1.7.0 — 2026-11-01

### Ajouts
- **Deux réveils sur le même équipement** : ton réveil (Alexa) et celui de ton enfant (Rémi), avec les **prénoms** affichés partout (réglables, « Papa » et « Armand » par défaut).
- **Bulle du jour** (crayon ✎ Heure) : choix **Pour : Papa / Armand / Les deux**, mémorisé sur l'appareil.
- **Bulle de semaine** (clic sur S46…) : profil de la semaine, même heure du lundi au vendredi, pas de réveil, pour Papa, Armand ou les deux.
- **Heures propres au Rémi** : le Rémi suit ton planning, avec des heures forcées possibles jour par jour ou pour une semaine.
- Nouvelles commandes action **Heure Rémi** et **Régler une semaine**.
- Widget : prochain réveil de chacun avec une date lisible (« lun. 2 nov. 06:30 »), et deux lignes par case (**P** / **A**).
- Documentation complète, journal des modifications, numéro de version.

### Changements
- **🔄 Resynchroniser / Programmer maintenant** programme tout de suite, sans attendre la veille.
- **Pas de réveil** dans la bulle de semaine retire aussi le profil forcé et les heures modifiées. Le bouton « Normal » de cette bulle est supprimé.
- Le Rémi n'a **jamais** de réveil un jour sans enfant, même avec une heure forcée.
- Le nom du profil n'est plus affiché sous les numéros de semaine (trait orange seulement).
- Boutons sélectionnés plus lisibles (orange, bleu, violet).

## 1.6.0 — 2026-10-30

### Changements
- **Mise en place la veille** : un réveil n'est programmé qu'à partir de la veille, à l'heure de mise en place (12:00 par défaut). L'Echo reste libre avant, et les alarmes réglées à la main ne créent plus de dérogation.
- Le Rémi n'est armé que la veille (fin de la fenêtre de 7 jours).
- Le plugin JeeRemi est renommé **remi** : la classe `reveilJeeRemi` devient `reveilRemi`.

### Corrections
- Le statut du Rémi pendant une dérogation tient compte de la garde.
- Nettoyage du paquet : copies de fichiers et template mobile supprimés.

## 1.5.0 — 2026-10-19

### Ajouts
- Option **« Seulement les jours avec mon enfant »** pour le Rémi (cochée par défaut).

## 1.4.0 — 2026-10-17

### Ajouts
- **Heure de passage** : le réveil du jour reste en place jusqu'à 12:00, et au moins 1 h après sa sonnerie. Une alarme n'est plus modifiée pendant qu'elle sonne.
- Message clair si le plugin Rémi est inactif.

## 1.3.0 — 2026-10-13

### Changements
- **Alexa** : lecture directe de la liste des alarmes chez Amazon (contourne le cache d'Alexa Premium), suppression une par une. Il n'y a plus de doublons quand l'heure change plusieurs fois.
- Vérification Alexa en quelques secondes, puis contrôle chaque minute tant qu'elle est en attente.
- Widget mis à jour en direct, sans rafraîchir la page.
- Un seul template (dashboard), aussi utilisé en mobile.

### Ajouts
- Boutons **✔✔ Vérifier** (sans reprogrammer) et **🔄 Resynchroniser** sur le widget.
- Commandes info **Statut Alexa** et **Statut Rémi**, et statut affiché en clair sur le widget.

## 1.2.0 — 2026-10-13

### Ajouts
- **Dérogation manuelle par appareil** : une alarme modifiée dans l'appli Alexa ou Rémi est laissée telle quelle, sans toucher à l'autre appareil. Le planning reprend une fois tous les réveils passés.
- Sonnerie Alexa réglable (paramètre `sound` obligatoire pour Alexa Premium).
- Logo du plugin.

### Corrections
- « Alarm already exists » est traité comme un succès.
- La suppression Alexa n'est plus bloquante.
- Le log n'est plus rempli après l'alerte.

## 1.1.0 — 2026-09-30

### Ajouts
- **Rémi piloté uniquement par les commandes du plugin Rémi** (lecture des `alarm_…`, écriture par `event_set_param`), avec la vérification du jour.
- **Profils du jour** (rugby…) avec leur crayon violet.
- Éditeur complet ouvert depuis le widget (⚙).
- Calendrier glissant de 4 semaines, jours passés masqués.
- « Pas de réveil » affiché comme une case vide.

### Changements
- Installation vide : pas de profil créé par défaut.

## 1.0.0 — 2026-09-29

### Première version
- Profils de semaine, saisie rapide, exceptions.
- Jours fériés (calcul local), congés personnels, vacances scolaires.
- Garde alternée (semaines paires ou impaires) avec bascule jour par jour.
- Programmation de l'Alexa et du Rémi, vérification, nouvel essai et alerte.
- Contrôle de présence.
- Widget avec crayons Congé et Enfant.

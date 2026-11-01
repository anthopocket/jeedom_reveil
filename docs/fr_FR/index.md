# Plugin Réveils

Version **1.7.0** — voir le [journal des modifications](changelog.md).

Le plugin gère les réveils de la maison depuis Jeedom : **ton réveil** sur une **Alexa** (plugin Alexa Premium) et celui de **ton enfant** sur un réveil **Rémi** (plugin remi). Jeedom est la source de vérité : il calcule le prochain réveil, le programme sur l'appareil, puis relit l'appareil pour vérifier.

Tout se règle depuis le **widget** : horaires du jour, semaines entières, congés, garde alternée, profils.

---

## 1. Installation et premier réglage

1. Installe le plugin, puis active-le dans **Plugins → Gestion des plugins → Réveils**. Vérifie que les crons **cron**, **cron5** et **cronDaily** sont cochés.
2. Ouvre **la configuration du plugin** (voir § 2) : tes congés, la garde alternée, l'alerte.
3. Crée un équipement dans **Plugins → Organisation → Réveils → Ajouter**, puis règle ses appareils (voir § 3).
4. Sur le widget, clique sur ⚙ pour créer tes **profils** (par exemple « Travail » lun-ven 06:30) et choisis un **profil par défaut**.

À l'installation, le planning est vide : aucun réveil n'est programmé tant que tu n'as pas créé de profil.

---

## 2. Configuration du plugin (commune à tous les équipements)

| Réglage | Rôle |
|---|---|
| **Académie** | Pour les vacances scolaires (data.education.gouv.fr), rafraîchies chaque jour. |
| **Mes congés** | Une période par ligne : `AAAA-MM-JJ AAAA-MM-JJ libellé`, ou une seule date. Se règle aussi sur le widget (crayon 🔴 Congé). |
| **Semaines avec mon enfant** | Paires, impaires ou désactivé. La S53 compte comme impaire. |
| **Heure de mise en place (la veille)** | 12:00 par défaut. Voir § 5. |
| **Commande d'alerte** | Commande message appelée si un réveil reste mal programmé. |
| **Nombre d'essais avant alerte** | 3 par défaut. |

---

## 3. Configuration de l'équipement

### Général

- **Prénoms affichés** : celui qui a l'Alexa et celui qui a le Rémi (par défaut « Papa » et « Armand »). Ils apparaissent sur le widget, dans les cases et dans les bulles.
- **Respecter les changements faits sur l'appareil** (« Accepter les changements faits sur l'appareil ») : voir § 6.
- **Présence** : voir § 8.

### Alexa (plugin Alexa Premium)

| Champ | Utilité |
|---|---|
| **Créer une alarme** (`addAlarm`) | **Indispensable.** Sert à créer l'alarme et à retrouver ton Echo. |
| **Modèle du message** | Par défaut `#date# #time#:00` (`2026-11-02 06:30:00`). |
| **Sonnerie Alexa** | Par défaut `system_alerts_melodic_01`. Obligatoire pour Alexa Premium. |
| **Prochaine alarme** (`whennextalarm`), **Supprimer** (`deleteallalarms`), **Rafraîchir** (`updateallalarms`) | Secours seulement. Le plugin lit et supprime normalement les alarmes **directement** (liste fraîche chez Amazon, suppression une par une avec `removeAlarm`). |

Avant de poser son alarme, le plugin **supprime les autres alarmes de cet Echo**. Si la bonne alarme existe déjà, il la garde. Il ne faut donc pas mettre deux équipements Réveils sur le même Echo.

### Rémi (plugin remi)

Le plugin passe **uniquement par les commandes du plugin remi** :

- **Lecture** : les commandes `alarm_…`. Leur nom donne l'heure et les jours (« 06:30 – reveil (lun-ven) »), leur valeur l'activation.
- **Écriture** : `event_set_param` (`time;HH:MM`, `recurrence;…`, `enabled;1|0`). Seul ce qui change est envoyé.

Réglages :

- **Rémi** puis **Réveil piloté** : crée d'abord le réveil dans l'appli Rémi (son, visage), puis choisis-le dans la liste. Un réveil sans nom est renommé « Jeedom » pour être retrouvé.
- **Seulement les jours avec mon enfant** (coché par défaut) : le Rémi ne sonne jamais un jour sans barre bleue, même avec une heure forcée.
- **Semaine Rémi commence le dimanche** : à cocher si un réveil du lundi apparaît « (mar) ».

Le Rémi ne connaît que des jours de la semaine, pas des dates : il n'est armé que pour un seul jour, la veille du réveil, et désactivé le reste du temps.

---

## 4. Calcul des réveils

### Ton réveil (Alexa), par priorité

1. **Heure du jour** modifiée sur le widget, **profil du jour** (rugby…) ou « pas de réveil ».
2. **Mes congés** → selon la règle de l'équipement (par défaut : pas de réveil).
3. **Jour férié** (Pâques compris) → selon la règle (par défaut : pas de réveil).
4. **Vacances scolaires** → selon la règle (par défaut : aucun effet).
5. **Profil de la semaine** :
   - le profil choisi pour cette semaine (bulle de semaine) ;
   - sinon le profil « semaine avec mon enfant » ou « semaine sans » ;
   - sinon le **profil par défaut**.

Les profils de semaine vont du lundi au vendredi. **Saisie rapide** d'un profil, par exemple `lun-ven 6:45, mer 7:30, sam-dim off`.

### Le réveil de ton enfant (Rémi)

- Les jours **avec enfant** (barre bleue), il **suit ton heure**.
- Tu peux lui donner **une autre heure** ou **pas de réveil**, jour par jour ou pour une semaine (voir § 7).
- Les jours **sans enfant**, il n'a jamais de réveil. Pour une nuit exceptionnelle chez toi, bascule d'abord le jour en bleu (crayon 🔵 Enfant).

---

## 5. Programmation des appareils

- **Mise en place la veille** : un réveil n'est programmé qu'à partir de la veille, à l'heure de mise en place (12:00 par défaut). Avant, rien n'est programmé : l'Echo est libre et le Rémi désactivé.
- **Le réveil du jour reste en place** jusqu'à cette même heure, et au moins 1 h après sa sonnerie. Une alarme n'est jamais modifiée juste avant ou pendant qu'elle sonne.
- **🔄 Resynchroniser** (widget) ou **Programmer maintenant** (page de l'équipement) : programme **tout de suite** le prochain réveil, sans attendre la veille, et met fin aux dérogations manuelles.
- Le Rémi et l'Alexa sont gérés séparément : un changement de l'heure de ton enfant part même quand ton propre réveil est en attente.

---

## 6. Vérification, alerte et dérogation manuelle

- **Juste après la programmation** : l'Alexa est relue jusqu'à 4 fois (toutes les 2 secondes), le Rémi immédiatement. Le statut passe à OK dès que l'appareil est conforme.
- **Sinon** : nouvelle relecture chaque minute. En cas d'écart, le plugin reprogramme, jusqu'au nombre d'essais choisi. Au-delà, il envoie **une seule alerte** par réveil.
- **Ensuite** : contrôle toutes les 5 minutes, plus un contrôle 45 minutes avant le réveil.
- **✔✔ Vérifier** (widget ou page de l'équipement) : relit les deux appareils tout de suite, **sans rien reprogrammer**.

**Dérogation manuelle** (option cochée par défaut) : si, après vérification, tu modifies l'alarme dans l'appli Alexa ou dans l'appli Rémi, cet appareil passe en **dérogation manuelle**.

- Le plugin la laisse telle quelle et ne touche ni à l'autre appareil ni au planning.
- Rien n'est reprogrammé tant que **tous les réveils** (prévus et réglés à la main) ne sont pas passés.
- **🔄 Resynchroniser** y met fin tout de suite.
- Option décochée : tout écart est corrigé.

---

## 7. Le widget

```
          ● Papa   lun. 2 nov. 06:30
            Armand lun. 9 nov. 06:30
              Papa 06:30 OK
              Armand prévu lun. 09/11 06:30, mis en place la veille
  ✎ Heure  🔴 Congé  🔵 Enfant  Rugby 08:00   ✔✔  🔄  ⚙
        L        M        M        J        V      S     D
  S46  P 06:30  P 06:30  (congé)  P 06:30  P 07:00
       A 06:30  A 06:30           A 06:30  A 07:30
```

### En-tête

- **Prochain réveil** de chacun, avec une pastille verte (vérifié) ou rouge.
- **Une ligne d'état par personne** : vert = OK, orange = en attente, violet = dérogation manuelle, rouge = écart ou échec. Les lignes se mettent à jour seules.

### Calendrier

- **4 semaines glissantes** : les jours passés disparaissent, une nouvelle semaine apparaît chaque lundi.
- Dans chaque case : **P** = ton heure, **A** = celle de ton enfant (en bleu, en gras si elle est forcée), « — » = pas de réveil.
- **Couleurs** : rouge = congé, gris = férié, contour orange = heure modifiée, contour violet = profil du jour, barre bleue = jour avec enfant.
- **Numéros de semaine** : icône enfant sur les semaines de garde, trait orange si un profil est forcé.

### Crayons

Choisis un crayon, puis clique sur les jours. Le crayon choisi est mémorisé sur l'appareil.

- **✎ Heure** : ouvre une bulle. Choisis **Pour : Papa / Armand / Les deux**, puis une heure (**OK**), **Pas de réveil** ou **Normal** (retour au planning).
- **🔴 Congé** : bascule le jour en congé. C'est **global** : tous les réveils qui suivent tes congés sont recalculés. Un clic au milieu d'une période la découpe.
- **🔵 Enfant** : inverse la garde du jour (un jour impair devient « avec enfant » et inversement). Un second clic revient à la règle.
- **Profils du jour** (violet, par exemple « Rugby 08:00 ») : applique ce profil au jour, un second clic l'enlève.

### Régler une semaine entière

Clic sur le **numéro de semaine** (S46…) :

- **Pour : Papa / Armand / Les deux** ;
- **Profil de la semaine** (pour Papa ; avec « Les deux », Armand suit) ;
- **Lun-ven à cette heure** : même heure du lundi au vendredi ;
- **Pas de réveil** : rien du lundi au vendredi (retire aussi le profil forcé et les heures modifiées).

Les jours passés, les profils du jour, les absences et, pour une heure, les congés et fériés ne sont pas touchés.

### Boutons

- **✔✔** : vérifier sans reprogrammer.
- **🔄** : resynchroniser tout de suite.
- **⚙** : éditeur complet (profils de semaine et du jour, règles congés / fériés / vacances, garde, profil par défaut, semaines, exceptions, aperçu).

Un utilisateur non administrateur peut tout régler depuis le widget s'il a les droits d'écriture sur l'équipement.

---

## 8. Présence

- **Réglages** : une commande info binaire (1 = présent, case « 0 = présent » pour inverser), une heure de contrôle (04:00 par défaut) et une action « si absent ».
- **Contrôle** : chaque jour à l'heure de contrôle. Si tu es absent et qu'un réveil est prévu dans la journée, l'action est exécutée et le jour est marqué **absent** (pas de réveil). Pour annuler, utilise **Normal** sur ce jour.

---

## 9. Commandes

### Infos

| Commande | Contenu |
|---|---|
| Prochain réveil, Prochain réveil (timestamp) | Ton prochain réveil. |
| Origine, Profil de la semaine | D'où vient l'heure, profil en cours. |
| Synchro OK, Alexa OK, Rémi OK | Binaires de vérification. |
| Statut synchro | État détaillé. |
| Statut Alexa, Statut Rémi | État de chaque appareil. |
| Dernière vérification, Présence au contrôle | |

### Actions (utilisables dans un scénario)

| Commande | Titre | Message |
|---|---|---|
| Resynchroniser | | |
| Vérifier | | |
| Pas de prochain réveil | | |
| Exception (ton heure) | `AAAA-MM-JJ` | `HH:MM`, `off`, `absent`, `@Profil du jour` ou vide (= normal) |
| Heure Rémi | `AAAA-MM-JJ` | `HH:MM`, `off`, vide ; préfixe `both:` pour vous deux |
| Régler une semaine | `AAAA-Wss` (ex. `2026-W46`) | `profile:Nom`, `time:HH:MM`, `off`, `normal` ; préfixe `remi:` ou `both:` |
| Basculer congé / garde / profil du jour | `AAAA-MM-JJ` | (profil du jour : son nom) |

---

## 10. Dépannage

- **Le widget n'affiche aucune heure** : aucun profil ne s'applique. Choisis un **profil par défaut** dans ⚙ → Profils, ou un profil par semaine.
- **« Plugin remi non installé ou inactif »** : le plugin remi doit être actif, avec l'identifiant `remi`.
- **Alexa « dérogation manuelle (aucune alarme) »** : l'alarme a disparu de l'Echo après vérification (supprimée à la main ou à la voix). 🔄 Resynchroniser la remet.
- **Log** : passe le log `reveil` en debug pour voir chaque lecture, suppression et programmation.

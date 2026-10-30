<?php
/**
 * Pilotage d'un réveil Rémi via le plugin remi, UNIQUEMENT par ses commandes Jeedom
 * (aucun appel à son API interne) :
 *
 * - Lecture : commandes info `alarm_<objectId>` de remi
 *     nom    = "HH:MM – <nom du réveil> (<jours>)"   ex. "06:30 – reveil (lun-ven)"
 *     valeur = 1 actif / 0 inactif
 *   La commande `refresh` de remi est exécutée avant chaque lecture.
 * - Écriture : commande `event_set_param`, titre = objectId, message = "clé;valeur".
 *
 * Le réveil est retrouvé par son NOM (l'objectId peut changer côté cloud).
 */
class reveilRemi {

    const DAY_NAMES = array('lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim');

    /** Identifiant du plugin remi. */
    const PLUGIN_IDS = array('remi');

    /** Plugins Rémi installés et chargés. */
    private static function pluginIds() {
        $out = array();
        foreach (self::PLUGIN_IDS as $id) {
            if (class_exists($id)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public static function available() {
        return !empty(self::pluginIds());
    }

    /** @return array [id => nom humain] des équipements remi */
    public static function listEquipments() {
        $out = array();
        foreach (self::pluginIds() as $id) {
            foreach (eqLogic::byType($id) as $eq) {
                $out[$eq->getId()] = $eq->getHumanName();
            }
        }
        return $out;
    }

    public static function equipment($eqId) {
        if (!self::available()) {
            throw new Exception('plugin remi inactif ou introuvable');
        }
        $eq = eqLogic::byId($eqId);
        if (!is_object($eq) || !in_array(strtolower($eq->getEqType_name()), array_map('strtolower', self::PLUGIN_IDS))) {
            throw new Exception('équipement Rémi (plugin remi) non sélectionné');
        }
        return $eq;
    }

    /** Demande à remi de relire le cloud (met à jour ses commandes alarm_…). */
    public static function refresh($eq) {
        $cmd = $eq->getCmd(null, 'refresh');
        if (is_object($cmd)) {
            try {
                $cmd->execCmd();
            } catch (Exception $e) {
                log::add('reveil', 'warning', 'remi refresh : ' . $e->getMessage());
            }
        }
    }

    /**
     * "lun-ven" -> [1,1,1,1,1,0,0], "jeu" -> [0,0,0,1,0,0,0], "lun,mar,ven-dim", "tous les jours", "une fois".
     * @return array|null null si le texte n'est pas reconnu (remi non modifié)
     */
    public static function parseDays($text) {
        $text = mb_strtolower(trim((string) $text));
        if ($text === 'tous les jours') {
            return array_fill(0, 7, 1);
        }
        if ($text === 'une fois') {
            return array_fill(0, 7, 0);
        }
        if ($text === '') {
            return null;
        }
        $rec = array_fill(0, 7, 0);
        foreach (explode(',', $text) as $part) {
            $bounds = explode('-', trim($part));
            $a = array_search(trim($bounds[0]), self::DAY_NAMES);
            $b = isset($bounds[1]) ? array_search(trim($bounds[1]), self::DAY_NAMES) : $a;
            if ($a === false || $b === false || $b < $a) {
                return null;
            }
            for ($i = $a; $i <= $b; $i++) {
                $rec[$i] = 1;
            }
        }
        return $rec;
    }

    /**
     * Réveils du Rémi d'après les commandes de remi.
     * @return array liste de ['objectId','name','time','days','recurrence'(array|null),'enabled']
     */
    public static function alarms($eq, $refresh = true) {
        if ($refresh) {
            self::refresh($eq);
        }
        $out = array();
        foreach ($eq->getCmd('info') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'alarm_') !== 0) {
                continue;
            }
            // "06:30 – reveil (lun-ven)"  (tiret long, jours optionnels)
            if (!preg_match('/^(\d{1,2}):(\d{2})\s*[–-]\s*(.*?)(?:\s*\(([^()]*)\))?\s*$/u', $cmd->getName(), $m)) {
                continue;
            }
            $days = isset($m[4]) ? $m[4] : '';
            $out[] = array(
                'objectId' => substr($cmd->getLogicalId(), strlen('alarm_')),
                'name' => trim($m[3]),
                'time' => sprintf('%02d:%02d', $m[1], $m[2]),
                'days' => $days,
                'recurrence' => self::parseDays($days),
                'enabled' => (int) $cmd->execCmd() === 1,
            );
        }
        return $out;
    }

    /** Réveils pour l'interface (liste de choix). */
    public static function alarmSummary($eq) {
        $out = array();
        foreach (self::alarms($eq) as $a) {
            $a['recurrence'] = $a['recurrence'] === null ? array() : $a['recurrence'];
            $out[] = $a;
        }
        return $out;
    }

    public static function find($eq, $name, $refresh = false) {
        $wanted = mb_strtolower(trim($name));
        foreach (self::alarms($eq, $refresh) as $a) {
            if (mb_strtolower($a['name']) === $wanted) {
                return $a;
            }
        }
        return null;
    }

    /** recurrence[7] avec un seul jour actif. $isoDay : 1 = lundi … 7 = dimanche. */
    public static function recurrenceFor($isoDay, $mondayFirst = true) {
        $rec = array_fill(0, 7, 0);
        $rec[$mondayFirst ? $isoDay - 1 : $isoDay % 7] = 1;
        return $rec;
    }

    private static function setParam($eq, $name, $key, $value) {
        $alarm = self::find($eq, $name); // objectId courant (remi s'est remis à jour après l'appel précédent)
        if ($alarm === null) {
            throw new Exception('réveil "' . $name . '" introuvable dans remi');
        }
        $cmd = $eq->getCmd(null, 'event_set_param');
        if (!is_object($cmd)) {
            throw new Exception('commande event_set_param absente de remi');
        }
        // remi exécute ensuite son updateInfos() : ses commandes alarm_… reflètent la modification
        $cmd->execCmd(array('title' => $alarm['objectId'], 'message' => $key . ';' . $value));
    }

    /** Programme le réveil $name pour $time ('HH:MM') le jour ISO $isoDay, ou le désactive si $time est null. */
    public static function program($eqId, $name, $time, $isoDay, $mondayFirst = true) {
        $eq = self::equipment($eqId);
        $alarm = self::find($eq, $name, true);
        if ($time === null) {
            if ($alarm !== null && $alarm['enabled']) {
                self::setParam($eq, $name, 'enabled', '0');
            }
            return;
        }
        if ($alarm === null) {
            throw new Exception('réveil "' . $name . '" introuvable dans remi : créez-le dans l\'appli Rémi');
        }
        $rec = self::recurrenceFor($isoDay, $mondayFirst);
        // on n'envoie que ce qui change (jours inconnus -> toujours envoyés)
        if ($alarm['time'] !== $time) {
            self::setParam($eq, $name, 'time', $time);
        }
        if ($alarm['recurrence'] !== $rec) {
            self::setParam($eq, $name, 'recurrence', implode(',', $rec));
        }
        if (!$alarm['enabled']) {
            self::setParam($eq, $name, 'enabled', '1');
        }
    }

    /** @return array ['ok' => bool, 'detail' => string] */
    public static function verify($eqId, $name, $time, $isoDay, $mondayFirst = true, $refresh = false) {
        $eq = self::equipment($eqId);
        // par défaut sans rafraîchissement : remi relit le cloud après chaque écriture et toutes les 5 min
        $alarm = self::find($eq, $name, $refresh);
        if ($time === null) {
            $ok = ($alarm === null || !$alarm['enabled']);
            return array('ok' => $ok, 'detail' => $ok ? 'désactivé' : 'toujours actif');
        }
        if ($alarm === null) {
            return array('ok' => false, 'detail' => 'réveil "' . $name . '" introuvable');
        }
        $problems = array();
        if (!$alarm['enabled']) {
            $problems[] = 'désactivé';
        }
        if ($alarm['time'] !== $time) {
            $problems[] = 'heure ' . $alarm['time'] . ' au lieu de ' . $time;
        }
        $expected = self::recurrenceFor($isoDay, $mondayFirst);
        $note = '';
        if ($alarm['recurrence'] === null) {
            $note = ' (jour non vérifiable : remi n\'affiche pas les jours)';
        } elseif ($alarm['recurrence'] !== $expected) {
            $problems[] = 'jours ' . ($alarm['days'] ?: '?') . ' au lieu de ' . self::DAY_NAMES[array_search(1, $expected)];
        }
        return array('ok' => empty($problems), 'detail' => (empty($problems) ? $time . ' OK' : implode(', ', $problems)) . $note);
    }

    /**
     * Rattache le réveil $objectId. S'il porte le même nom qu'un autre réveil
     * (cas des réveils sans nom, affichés "Réveil" par remi), on lui donne un nom unique.
     * @return string nom à mémoriser
     */
    public static function claim($eqId, $objectId) {
        $eq = self::equipment($eqId);
        $alarms = self::alarms($eq, true);
        $target = null;
        $others = array();
        foreach ($alarms as $a) {
            if ($a['objectId'] === $objectId) {
                $target = $a;
            } else {
                $others[] = mb_strtolower($a['name']);
            }
        }
        if ($target === null) {
            throw new Exception('réveil introuvable, rechargez la liste');
        }
        $name = $target['name'];
        if ($name === '' || in_array(mb_strtolower($name), $others)) {
            $name = 'Jeedom';
            for ($i = 2; in_array(mb_strtolower($name), $others); $i++) {
                $name = 'Jeedom ' . $i;
            }
            $cmd = $eq->getCmd(null, 'event_set_param');
            if (!is_object($cmd)) {
                throw new Exception('commande event_set_param absente de remi');
            }
            $cmd->execCmd(array('title' => $objectId, 'message' => 'name;' . $name));
            log::add('reveil', 'info', 'Rémi : réveil ' . $target['time'] . ' renommé "' . $name . '" pour être piloté');
        }
        return $name;
    }
}

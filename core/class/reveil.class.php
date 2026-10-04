<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/reveilCalendar.class.php';
require_once dirname(__FILE__) . '/reveilJeeRemi.class.php';

/**
 * Un équipement = un dormeur / une chambre.
 * Jeedom est la source de vérité : on calcule le prochain réveil, on le pousse
 * sur chaque cible (Alexa, Rémi) puis on relit la cible pour vérifier.
 *
 * configuration 'planning' (JSON) :
 * {
 *   "profiles":   {"École": {"1":"06:45", ... "7":""}},   // 1 = lundi, "" = pas de réveil
 *   "defaultProfile": "École",
 *   "weeks":      {"2026-W41": "Vacances"},              // affectation explicite par semaine ISO
 *   "garde":      {"avec": "Avec mon fils", "sans": "Sans"},    // garde alternée (réglage global des semaines)
 *   "dayProfiles":{"Rugby": "08:00"},                    // profils du jour, posés sur une date
 *   "exceptions": {"2026-10-05": "07:30", "2026-10-06": "off", "2026-10-10": "@Rugby"},
 *   "rules":      {"conges": "ignore|off|<profil>", "ferie": "...", "vacances": "..."}
 * }
 */
class reveil extends eqLogic {

    const VERIFY_DELAY = 120;      // s entre la programmation et la relecture
    const RECHECK_EVERY = 240;     // contrôle de routine à chaque passage du cron (5 min), lecture légère
    const PREWAKE_CHECK = 2700;    // contrôle supplémentaire 45 min avant le réveil
    const HORIZON_DAYS = 21;

    private function setCaches($values) {
        foreach ($values as $k => $v) {
            $this->setCache($k, $v);
        }
    }

    /* ======================= Crons ======================= */

    public static function cron5() {
        foreach (eqLogic::byType('reveil', true) as $eq) {
            try {
                $eq->checkPresence();
                $eq->syncTargets();
            } catch (Exception $e) {
                log::add('reveil', 'error', $eq->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public static function cronDaily() {
        foreach (eqLogic::byType('reveil', true) as $eq) {
            $eq->refreshWidget();
        }
        try {
            reveilCalendar::refreshSchoolHolidays();
        } catch (Exception $e) {
            log::add('reveil', 'warning', 'Vacances scolaires : ' . $e->getMessage() . ' (cache conservé)');
        }
    }

    /* ======================= Planning ======================= */

    public function getPlanning() {
        $p = $this->getConfiguration('planning', array());
        if (is_string($p)) {
            $p = json_decode($p, true);
        }
        if (!is_array($p)) {
            $p = array();
        }
        return array_merge(array(
            'profiles' => array(),
            'defaultProfile' => '',
            'weeks' => array(),
            'garde' => array('avec' => '', 'sans' => ''),
            'exceptions' => array(),
            'dayProfiles' => array(),
            'rules' => array('conges' => 'ignore', 'ferie' => 'ignore', 'vacances' => 'ignore'),
        ), $p);
    }

    private static function profileTime($planning, $profile, $isoDay) {
        if (!isset($planning['profiles'][$profile])) {
            return null;
        }
        $t = isset($planning['profiles'][$profile][$isoDay]) ? trim($planning['profiles'][$profile][$isoDay]) : '';
        return preg_match('/^\d{1,2}:\d{2}$/', $t) ? sprintf('%05s', $t) : null;
    }

    /** Profil de la semaine : affectation explicite > garde alternée > défaut. */
    public static function weekProfile($planning, $date) {
        $d = new DateTime($date);
        $week = $d->format('o-\WW');
        if (!empty($planning['weeks'][$week])) {
            return array($planning['weeks'][$week], 'semaine ' . $week);
        }
        $custody = reveilCalendar::custodyDay($date);
        if ($custody !== null) {
            $key = $custody ? 'avec' : 'sans';
            $forced = array_key_exists($date, reveilCalendar::custodyOverrides());
            if (!empty($planning['garde'][$key])) {
                return array($planning['garde'][$key], 'garde : ' . ($custody ? 'avec enfant' : 'sans enfant') . ($forced ? ' (jour forcé)' : ' (S' . $d->format('W') . ')'));
            }
        }
        return array($planning['defaultProfile'], $planning['defaultProfile'] != '' ? 'défaut' : '');
    }

    /**
     * Calcule le réveil d'un jour.
     * Priorité : profil du jour / exception > congés perso > férié > vacances scolaires > profil de semaine.
     * @return array ['date','time'|null,'source','profile']
     */
    public static function computeDay($planning, $date) {
        $isoDay = (int) (new DateTime($date))->format('N');
        $res = array('date' => $date, 'time' => null, 'source' => '', 'profile' => '');

        if (isset($planning['exceptions'][$date]) && $planning['exceptions'][$date] !== '' && $planning['exceptions'][$date][0] == '@') {
            // profil du jour (ex. "@Rugby") : prioritaire sur tout le reste
            $name = substr($planning['exceptions'][$date], 1);
            $t = isset($planning['dayProfiles'][$name]) ? trim($planning['dayProfiles'][$name]) : '';
            if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
                $res['source'] = 'jour : ' . $name;
                $res['profile'] = $name;
                $res['time'] = sprintf('%05s', $t);
                return $res;
            }
            // profil du jour supprimé ou sans heure : on ignore et on continue le calcul normal
        } elseif (isset($planning['exceptions'][$date]) && $planning['exceptions'][$date] !== '') {
            $v = $planning['exceptions'][$date];
            $res['source'] = ($v == 'absent') ? 'absence' : 'exception';
            $res['time'] = ($v == 'off' || $v == 'absent') ? null : sprintf('%05s', $v);
            return $res;
        }

        $calendars = array(
            'conges' => reveilCalendar::isPersonalLeave($date),
            'ferie' => reveilCalendar::isFerie($date),
            'vacances' => reveilCalendar::isSchoolHoliday($date),
        );
        foreach ($calendars as $kind => $label) {
            $rule = isset($planning['rules'][$kind]) ? $planning['rules'][$kind] : 'ignore';
            if ($label === false || $rule == 'ignore' || $rule == '') {
                continue;
            }
            $res['source'] = $kind . ' (' . $label . ')';
            if ($rule == 'off') {
                return $res;
            }
            $res['profile'] = $rule;
            $res['time'] = self::profileTime($planning, $rule, $isoDay);
            return $res;
        }

        list($profile, $why) = self::weekProfile($planning, $date);
        $res['time'] = self::profileTime($planning, $profile, $isoDay);
        // jour sans heure dans le profil (ex. week-end d'un profil lun-ven) : pas d'origine affichée
        if ($res['time'] !== null) {
            $res['profile'] = $profile;
            $res['source'] = $why;
        }
        return $res;
    }

    /** Liste jour par jour sur $days jours à partir d'aujourd'hui. */
    public static function preview($planning, $days = 21) {
        $out = array();
        $d = new DateTime('today');
        for ($i = 0; $i < $days; $i++) {
            $out[] = self::computeDay($planning, $d->format('Y-m-d'));
            $d->modify('+1 day');
        }
        return $out;
    }

    /** Prochain réveil strictement après maintenant, ou null. */
    public function nextWake() {
        $planning = $this->getPlanning();
        $now = time();
        foreach (self::preview($planning, self::HORIZON_DAYS) as $day) {
            if ($day['time'] === null) {
                continue;
            }
            $ts = strtotime($day['date'] . ' ' . $day['time']);
            if ($ts > $now) {
                $day['timestamp'] = $ts;
                $day['datetime'] = $day['date'] . ' ' . $day['time'];
                return $day;
            }
        }
        return null;
    }

    /* ======================= Saisie rapide ======================= */

    /**
     * "lun-ven 6:45, mer 7:30, sam-dim off" -> ["1"=>"06:45", ..., "6"=>""]
     * Seuls les jours mentionnés sont renvoyés ("" = pas de réveil).
     */
    public static function parseQuick($text) {
        $days = array('lun' => 1, 'mar' => 2, 'mer' => 3, 'jeu' => 4, 'ven' => 5, 'sam' => 6, 'dim' => 7);
        $out = array();
        foreach (preg_split('/[,;\n]+/', mb_strtolower($text)) as $chunk) {
            if (!preg_match('/^\s*(lun|mar|mer|jeu|ven|sam|dim)\w*(?:\s*-\s*(lun|mar|mer|jeu|ven|sam|dim)\w*)?\s+(off|\d{1,2}[:h]\d{2})\s*$/u', $chunk, $m)) {
                if (trim($chunk) != '') {
                    throw new Exception('Saisie non comprise : "' . trim($chunk) . '"');
                }
                continue;
            }
            $from = $days[$m[1]];
            $to = !empty($m[2]) ? $days[$m[2]] : $from;
            $val = ($m[3] == 'off') ? '' : sprintf('%05s', str_replace('h', ':', $m[3]));
            for ($i = $from; $i != $to % 7 + 1; $i = $i % 7 + 1) {
                $out[(string) $i] = $val;
            }
        }
        ksort($out);
        return $out;
    }

    /* ======================= Synchronisation ======================= */

    public function syncTargets($force = false) {
        $next = $this->nextWake();
        $remiNext = $this->remiTarget($next);
        // la signature inclut l'état "armé" du Rémi : quand le réveil entre dans la fenêtre de 7 jours, il est programmé
        $sig = ($next ? $next['datetime'] : 'none') . '|' . ($remiNext ? 'R' : '-');
        $this->updateInfos($next);
        $now = time();

        if ($force || $sig !== $this->getCache('pushedFor', '')) {
            $this->pushAll($next, !$force);
            $this->setCaches(array('pushedFor' => $sig, 'pushedAt' => $now, 'verified' => false, 'retry' => 0, 'alerted' => false, 'lastCheck' => $now));
            $remiOn = $this->getConfiguration('remiEnable', 0);
            $alexaOn = $this->getConfiguration('alexaEnable', 0);
            $remiConfirmed = $remiOn && $this->getCache('remiConfirmed', false);
            if ($remiConfirmed) {
                $this->checkAndUpdateCmd('remi_ok', 1);
            }
            if ($remiConfirmed && !$alexaOn) {
                // Rémi seul et confirmé à l'écriture : vérifié tout de suite, pas besoin d'attendre le cron
                $remiNext = $this->remiTarget($next);
                $detail = $remiNext ? $remiNext['time'] . ' OK' : ($next ? 'en attente (réveil à plus de 7 jours)' : 'désactivé');
                $this->setCaches(array('verified' => true));
                $this->checkAndUpdateCmd('sync_ok', 1);
                $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));
                $this->checkAndUpdateCmd('sync_status', 'OK : Rémi ' . $detail);
            } else {
                $this->checkAndUpdateCmd('sync_ok', 0);
                $this->checkAndUpdateCmd('sync_status', $remiConfirmed ? 'Programmé : Rémi confirmé, Alexa en attente de vérification' : 'Programmé, vérification en attente');
            }
            return;
        }

        $verified = $this->getCache('verified', false);
        $pushedAt = $this->getCache('pushedAt', 0);
        $lastCheck = $this->getCache('lastCheck', 0);

        if (!$verified) {
            if ($now - $pushedAt < self::VERIFY_DELAY) {
                return;
            }
        } else {
            $routine = ($now - $lastCheck) >= self::RECHECK_EVERY;
            $prewake = $next && ($next['timestamp'] - $now) < self::PREWAKE_CHECK && !$this->getCache('prewakeDone', false);
            if (!$routine && !$prewake) {
                return;
            }
            if ($prewake) {
                $this->setCache('prewakeDone', true);
            }
        }

        $result = $this->verifyAll($next);
        $this->setCache('lastCheck', $now);
        $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));

        if ($result['ok']) {
            $this->setCaches(array('verified' => true, 'retry' => 0, 'alerted' => false));
            $this->checkAndUpdateCmd('sync_ok', 1);
            $this->checkAndUpdateCmd('sync_status', 'OK : ' . $result['detail']);
            return;
        }

        // Réveil déjà vérifié OK puis modifié : c'est un changement fait à la main (appli, voix),
        // pas un échec de programmation. Option : on l'adopte comme exception du jour.
        $remiConfirmed = $this->getCache('remiConfirmed', false);
        if (($verified || $remiConfirmed) && $this->getConfiguration('adoptExternal', 1)) {
            // pas encore vérifié globalement : seul le Rémi (confirmé à la programmation) peut être "changé à la main"
            $ext = $this->detectExternalChange($next, !$verified);
            if ($ext !== null) {
                $label = $ext['value'] == 'off' ? 'réveil supprimé' : $ext['value'];
                log::add('reveil', 'info', $this->getHumanName() . ' changement fait sur ' . $ext['source'] . ' adopté : ' . $label . ' le ' . $next['date']);
                $this->setException($next['date'], $ext['value']); // reprogramme les autres appareils et rafraîchit le widget
                $this->checkAndUpdateCmd('sync_status', 'Changement fait sur ' . $ext['source'] . ' adopté (' . $label . ' le ' . $next['date'] . '), vérification en attente');
                return;
            }
        }

        $this->checkAndUpdateCmd('sync_ok', 0);
        $retry = $this->getCache('retry', 0) + 1;
        $max = max(1, (int) config::byKey('maxRetry', 'reveil', 3));
        log::add('reveil', 'warning', $this->getHumanName() . ' vérification KO (' . $retry . '/' . $max . ') : ' . $result['detail']);
        if ($retry < $max) {
            $this->pushAll($next, false);
            $this->setCaches(array('verified' => false, 'retry' => $retry, 'pushedAt' => $now));
            $this->checkAndUpdateCmd('sync_status', 'Écart, nouvel essai ' . $retry . ' : ' . $result['detail']);
            return;
        }
        $this->setCaches(array('verified' => false, 'retry' => $retry, 'pushedAt' => $now));
        $this->checkAndUpdateCmd('sync_status', 'ÉCHEC : ' . $result['detail']);
        if (!$this->getCache('alerted', false)) {
            $this->setCache('alerted', true);
            self::alert($this->getName() . ' : réveil ' . ($next ? $next['datetime'] : 'aucun') . ' mal programmé (' . $result['detail'] . ')');
        }
    }

    private function updateInfos($next) {
        $this->checkAndUpdateCmd('next_wake', $next ? $next['datetime'] : 'Aucun');
        $this->checkAndUpdateCmd('next_wake_ts', $next ? $next['timestamp'] : 0);
        $this->checkAndUpdateCmd('next_source', $next ? $next['source'] : '');
        list($profile) = self::weekProfile($this->getPlanning(), date('Y-m-d'));
        $this->checkAndUpdateCmd('week_profile', $profile);
    }

    /**
     * Le Rémi ne connaît que des jours de semaine, pas des dates : un réveil "jeudi" sonne au PROCHAIN jeudi.
     * On ne le programme donc que si le prochain réveil tombe dans les 7 jours ; sinon il reste désactivé
     * et sera armé automatiquement dès qu'il entrera dans la fenêtre (contrôle toutes les 5 min).
     */
    private function remiTarget($next) {
        if ($next === null) {
            return null;
        }
        return ($next['timestamp'] - time()) < 7 * 86400 ? $next : null;
    }

    /**
     * @param bool $onlyChanged ne reprogrammer que les cibles dont la consigne a changé
     *                          (évite par exemple de recréer l'alarme Alexa quand seul le Rémi s'arme)
     */
    private function pushAll($next, $onlyChanged = false) {
        $this->setCache('prewakeDone', false);
        $remiNext = $this->remiTarget($next);
        $alexaSig = $next ? $next['datetime'] : 'none';
        $remiSig = $remiNext ? $remiNext['datetime'] : 'none';
        if ($this->getConfiguration('alexaEnable', 0) && (!$onlyChanged || $alexaSig !== $this->getCache('pushedAlexa', ''))) {
            try {
                $this->pushAlexa($next);
                $this->setCache('pushedAlexa', $alexaSig);
            } catch (Exception $e) {
                log::add('reveil', 'error', $this->getHumanName() . ' Alexa : ' . $e->getMessage());
            }
        }
        if ($this->getConfiguration('remiEnable', 0) && (!$onlyChanged || $remiSig !== $this->getCache('pushedRemi', ''))) {
            try {
                $this->setCache('remiConfirmed', false);
                $this->pushRemi($remiNext);
                $this->setCache('pushedRemi', $remiSig);
                // JeeRemi relit le cloud juste après chaque écriture : on peut confirmer tout de suite.
                // Une fois confirmé, tout écart ultérieur vient forcément de l'appli (et non d'un échec).
                try {
                    $check = $this->verifyRemi($next);
                    $this->setCache('remiConfirmed', $check['ok']);
                } catch (Exception $e) {
                    log::add('reveil', 'debug', 'confirmation Rémi : ' . $e->getMessage());
                }
                if ($next !== null && $remiNext === null) {
                    log::add('reveil', 'info', $this->getHumanName() . ' Rémi désactivé : réveil du ' . $next['datetime'] . ' à plus de 7 jours, armé à partir du ' . date('Y-m-d H:i', $next['timestamp'] - 7 * 86400 + 60));
                }
            } catch (Exception $e) {
                log::add('reveil', 'error', $this->getHumanName() . ' Rémi : ' . $e->getMessage());
            }
        }
        log::add('reveil', 'info', $this->getHumanName() . ' programmé : ' . ($next ? $next['datetime'] . ' (' . $next['source'] . ')' : 'aucun réveil'));
    }

    private function verifyAll($next) {
        $ok = true;
        $details = array();
        if ($this->getConfiguration('alexaEnable', 0)) {
            try {
                $r = $this->verifyAlexa($next);
            } catch (Exception $e) {
                $r = array('ok' => false, 'detail' => $e->getMessage());
            }
            $ok = $ok && $r['ok'];
            $details[] = 'Alexa ' . $r['detail'];
            $this->checkAndUpdateCmd('alexa_ok', $r['ok'] ? 1 : 0);
        }
        if ($this->getConfiguration('remiEnable', 0)) {
            try {
                $r = $this->verifyRemi($next);
            } catch (Exception $e) {
                $r = array('ok' => false, 'detail' => $e->getMessage());
            }
            $ok = $ok && $r['ok'];
            $details[] = 'Rémi ' . $r['detail'];
            $this->checkAndUpdateCmd('remi_ok', $r['ok'] ? 1 : 0);
        }
        return array('ok' => $ok, 'detail' => implode(' | ', $details));
    }

    /* ---------- Alexa (via le plugin alexaapi) ---------- */

    private function cmdFromConf($key) {
        $conf = trim($this->getConfiguration($key, ''));
        if ($conf == '') {
            return null;
        }
        $cmd = cmd::byId(str_replace('#', '', $conf));
        if (!is_object($cmd)) {
            $cmd = cmd::byString($conf);
        }
        return $cmd;
    }

    private function pushAlexa($next) {
        $del = $this->cmdFromConf('alexaDeleteCmd');
        if ($del) {
            $del->execCmd();
        }
        if ($next === null) {
            return;
        }
        $cmd = $this->cmdFromConf('alexaAlarmCmd');
        if (!$cmd) {
            throw new Exception('commande de création d\'alarme non configurée');
        }
        $tpl = $this->getConfiguration('alexaTemplate', '#date# #time#:00');
        $msg = str_replace(array('#date#', '#time#', '#datetime#'), array($next['date'], $next['time'], $next['datetime']), $tpl);
        $cmd->execCmd(array('title' => $msg, 'message' => $msg));
        $refresh = $this->cmdFromConf('alexaRefreshCmd');
        if ($refresh) {
            $refresh->execCmd();
        }
    }

    /** Interprète la valeur "prochaine alarme" d'alexaapi : date+heure, HH:MM, HHmm ou timestamp. */
    public static function parseAlexaValue($v) {
        $v = trim((string) $v);
        if (preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):?(\d{2})/', $v, $m)) {
            return array('date' => $m[1], 'time' => sprintf('%02d:%02d', $m[2], $m[3]));
        }
        if (ctype_digit($v) && strlen($v) >= 10) {
            $ts = (strlen($v) > 10) ? intdiv((int) $v, 1000) : (int) $v;
            return array('date' => date('Y-m-d', $ts), 'time' => date('H:i', $ts));
        }
        if (preg_match('/^(\d{1,2})[:h]?(\d{2})$/', $v, $m)) {
            return array('date' => null, 'time' => sprintf('%02d:%02d', $m[1], $m[2]));
        }
        return null;
    }

    /**
     * Vérification générique à partir d'une commande info existante qui renvoie
     * l'heure programmée sur l'appareil (date+heure, HH:MM, HHmm ou timestamp).
     */
    private function verifyFromInfo($infoKey, $refreshKey, $next) {
        $info = $this->cmdFromConf($infoKey);
        if (!$info) {
            return array('ok' => false, 'detail' => 'commande info non configurée');
        }
        $raw = $info->execCmd();
        $refresh = $this->cmdFromConf($refreshKey);
        if ($refresh) {
            $refresh->execCmd(); // valeur fraîche pour le prochain contrôle
        }
        $read = self::parseAlexaValue($raw);
        if ($next === null) {
            $ok = ($read === null);
            return array('ok' => $ok, 'detail' => $ok ? 'aucun réveil' : 'réveil restant ' . $raw);
        }
        if ($read === null) {
            return array('ok' => false, 'detail' => 'aucune heure lue (' . $raw . ')');
        }
        $ok = $read['time'] == $next['time'] && ($read['date'] === null || $read['date'] == $next['date']);
        return array('ok' => $ok, 'detail' => $ok ? $next['time'] . ' OK' : 'lu ' . trim($read['date'] . ' ' . $read['time']) . ' au lieu de ' . $next['datetime']);
    }

    private function verifyAlexa($next) {
        return $this->verifyFromInfo('alexaNextCmd', 'alexaRefreshCmd', $next);
    }

    /* ---------- Rémi (via le plugin JeeRemi) ---------- */

    /**
     * Cherche un changement fait à la main sur un appareil, qu'on sait traduire en exception du jour :
     * - réveil supprimé / désactivé                 -> 'off'
     * - même jour, autre heure                      -> 'HH:MM'
     * Tout autre écart (autre jour, plusieurs jours…) n'est pas adopté : le planning est réappliqué.
     * @return array|null ['source' => 'Rémi'|'Alexa', 'value' => 'HH:MM'|'off']
     */
    private function detectExternalChange($next, $remiOnly = false) {
        if ($next === null) {
            return null;
        }
        if ($this->getConfiguration('remiEnable', 0) && $this->remiTarget($next) !== null) {
            try {
                list($eqId, $name, $mondayFirst) = $this->remiParams();
                $alarm = reveilJeeRemi::find(reveilJeeRemi::equipment($eqId), $name, true);
                if ($alarm !== null) {
                    $expectedDays = reveilJeeRemi::recurrenceFor((int) (new DateTime($next['date']))->format('N'), $mondayFirst);
                    if (!$alarm['enabled']) {
                        return array('source' => 'Rémi', 'value' => 'off');
                    }
                    if (($alarm['recurrence'] === null || $alarm['recurrence'] === $expectedDays) && $alarm['time'] !== $next['time']) {
                        return array('source' => 'Rémi', 'value' => $alarm['time']);
                    }
                }
            } catch (Exception $e) {
                log::add('reveil', 'debug', 'détection Rémi : ' . $e->getMessage());
            }
        }
        if (!$remiOnly && $this->getConfiguration('alexaEnable', 0)) {
            $info = $this->cmdFromConf('alexaNextCmd');
            if ($info) {
                $read = self::parseAlexaValue($info->execCmd());
                if ($read === null) {
                    return array('source' => 'Alexa', 'value' => 'off');
                }
                if (($read['date'] === null || $read['date'] == $next['date']) && $read['time'] !== $next['time']) {
                    return array('source' => 'Alexa', 'value' => $read['time']);
                }
            }
        }
        return null;
    }

    private function remiParams() {
        $name = trim($this->getConfiguration('remiAlarmName', '')) ?: 'Jeedom';
        $mondayFirst = $this->getConfiguration('remiSundayFirst', 0) ? false : true;
        return array($this->getConfiguration('remiEqId', ''), $name, $mondayFirst);
    }

    /** $next est déjà la consigne Rémi (null si rien dans les 7 jours). */
    private function pushRemi($next) {
        list($eqId, $name, $mondayFirst) = $this->remiParams();
        $isoDay = $next ? (int) (new DateTime($next['date']))->format('N') : 1;
        reveilJeeRemi::program($eqId, $name, $next ? $next['time'] : null, $isoDay, $mondayFirst);
    }

    private function verifyRemi($next) {
        $remiNext = $this->remiTarget($next);
        list($eqId, $name, $mondayFirst) = $this->remiParams();
        $isoDay = $remiNext ? (int) (new DateTime($remiNext['date']))->format('N') : 1;
        $r = reveilJeeRemi::verify($eqId, $name, $remiNext ? $remiNext['time'] : null, $isoDay, $mondayFirst);
        if ($r['ok'] && $next !== null && $remiNext === null) {
            $r['detail'] = 'en attente (réveil à plus de 7 jours)';
        }
        return $r;
    }

    public static function alert($message) {
        log::add('reveil', 'error', $message);
        $conf = trim(config::byKey('alertCmd', 'reveil', ''));
        if ($conf == '') {
            return;
        }
        $cmd = cmd::byId(str_replace('#', '', $conf));
        if (!is_object($cmd)) {
            $cmd = cmd::byString($conf);
        }
        if (is_object($cmd)) {
            $cmd->execCmd(array('title' => 'Réveil', 'message' => $message));
        }
    }

    /* ======================= Présence ======================= */

    /**
     * À l'heure de contrôle (04:00 par défaut), une seule fois par jour :
     * si la présence vaut "absent" et qu'un réveil est prévu plus tard dans la journée,
     * exécute l'action de suppression configurée et marque le jour "absent".
     */
    public function checkPresence() {
        $presenceCmd = $this->cmdFromConf('presenceCmd');
        if (!$presenceCmd) {
            return;
        }
        $today = date('Y-m-d');
        $checkTime = trim($this->getConfiguration('presenceTime', '')) ?: '04:00';
        if (time() < strtotime($today . ' ' . $checkTime) || $this->getCache('presenceCheckedFor', '') == $today) {
            return;
        }
        $this->setCache('presenceCheckedFor', $today);

        $raw = $presenceCmd->execCmd();
        $present = (bool) (int) $raw;
        if ($this->getConfiguration('presenceInvert', 0)) {
            $present = !$present;
        }
        $this->checkAndUpdateCmd('presence', $present ? 1 : 0);
        $next = $this->nextWake();
        if ($present || $next === null || $next['date'] != $today) {
            log::add('reveil', 'debug', $this->getHumanName() . ' présence ' . $checkTime . ' : ' . ($present ? 'présent' : 'absent') . ', rien à faire');
            return;
        }

        log::add('reveil', 'info', $this->getHumanName() . ' absent à ' . $checkTime . ' : suppression du réveil de ' . $next['time']);
        $action = $this->cmdFromConf('presenceActionCmd');
        if ($action) {
            try {
                $action->execCmd();
            } catch (Exception $e) {
                log::add('reveil', 'error', $this->getHumanName() . ' action d\'absence : ' . $e->getMessage());
            }
        }
        // Le jour passe en "absent" : le plugin ne reprogramme pas ce réveil et la vérification l'attend supprimé
        $this->setException($today, 'absent');
    }

    /* ======================= Exceptions (depuis commandes / ajax) ======================= */

    /** Pose ou retire le profil du jour $name sur $date (clic sur le widget). */
    public function toggleDayProfile($date, $name) {
        $planning = $this->getPlanning();
        $current = isset($planning['exceptions'][$date]) ? $planning['exceptions'][$date] : '';
        $this->setException($date, ($current === '@' . $name) ? '' : '@' . $name);
    }

    public function setException($date, $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Date invalide : ' . $date);
        }
        $value = trim($value);
        $planning = $this->getPlanning();
        if ($value !== '' && $value[0] == '@') {
            if (!isset($planning['dayProfiles'][substr($value, 1)])) {
                throw new Exception('Profil du jour inconnu : ' . substr($value, 1));
            }
        } else {
            $value = mb_strtolower($value);
            if ($value !== '' && $value !== 'off' && $value !== 'absent' && !preg_match('/^\d{1,2}[:h]\d{2}$/', $value)) {
                throw new Exception('Valeur invalide : ' . $value . ' (HH:MM, off, @profil ou vide)');
            }
        }
        if ($value === '') {
            unset($planning['exceptions'][$date]);
        } else {
            $planning['exceptions'][$date] = (in_array($value, array('off', 'absent')) || $value[0] == '@') ? $value : sprintf('%05s', str_replace('h', ':', $value));
        }
        // purge des exceptions passées
        foreach (array_keys($planning['exceptions']) as $d) {
            if ($d < date('Y-m-d', strtotime('-7 days'))) {
                unset($planning['exceptions'][$d]);
            }
        }
        $this->setConfiguration('planning', $planning);
        $this->save(true);
        $this->syncTargets();
        $this->refreshWidget();
    }

    /** Congés et garde sont globaux : tous les réveils sont recalculés et leurs widgets rafraîchis. */
    public static function resyncAll() {
        foreach (eqLogic::byType('reveil', true) as $eq) {
            try {
                $eq->syncTargets();
            } catch (Exception $e) {
                log::add('reveil', 'error', $eq->getHumanName() . ' : ' . $e->getMessage());
            }
            $eq->refreshWidget();
        }
    }

    /* ======================= Widget ======================= */

    const WIDGET_WEEKS = 4;

    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        if (!is_object($this->getCmd(null, 'toggle_garde')) || !is_object($this->getCmd(null, 'toggle_conges')) || !is_object($this->getCmd(null, 'toggle_dayprofile'))) {
            $this->createCmds();
        }
        $toggle = $this->getCmd(null, 'toggle_conges');
        $replace['#toggle_id#'] = is_object($toggle) ? $toggle->getId() : '';
        $toggleGarde = $this->getCmd(null, 'toggle_garde');
        $replace['#toggle_garde_id#'] = is_object($toggleGarde) ? $toggleGarde->getId() : '';

        $next = $this->getCmd(null, 'next_wake');
        $ok = $this->getCmd(null, 'sync_ok');
        $replace['#next_wake#'] = is_object($next) ? $next->execCmd() : '';
        $replace['#sync_class#'] = (is_object($ok) && $ok->execCmd() == 1) ? 'ok' : 'ko';
        $status = $this->getCmd(null, 'sync_status');
        $replace['#sync_title#'] = is_object($status) ? htmlspecialchars($status->execCmd(), ENT_QUOTES) : '';

        $toggleDay = $this->getCmd(null, 'toggle_dayprofile');
        $replace['#toggle_day_id#'] = is_object($toggleDay) ? $toggleDay->getId() : '';
        $pens = '';
        foreach ($this->getPlanning()['dayProfiles'] as $name => $t) {
            if (preg_match('/^\d{1,2}:\d{2}$/', trim($t))) {
                $n = htmlspecialchars($name, ENT_QUOTES);
                $pens .= '<span class="rv-pen rv-pen-day" data-pen="day:' . $n . '"><i class="fas fa-clock"></i> ' . $n . ' ' . htmlspecialchars(trim($t), ENT_QUOTES) . '</span>';
            }
        }
        $replace['#day_pens#'] = $pens;
        $exc = $this->getCmd(null, 'set_exception');
        $replace['#exception_id#'] = is_object($exc) ? $exc->getId() : '';
        $pl = $this->getPlanning();
        $hasTimes = false;
        foreach ($pl['profiles'] as $days) {
            foreach ((array) $days as $t) {
                if (trim((string) $t) !== '') {
                    $hasTimes = true;
                }
            }
        }
        $replace['#empty_class#'] = ($hasTimes || !empty($pl['dayProfiles']) || !empty($pl['exceptions'])) ? '' : 'rv-empty';
        try {
            $replace['#grid#'] = $this->widgetGrid();
        } catch (Throwable $e) {
            log::add('reveil', 'error', $this->getHumanName() . ' widget : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            $replace['#grid#'] = '<div style="color:#e53935;text-align:center;">Erreur d\'affichage, voir le log reveil</div>';
        }
        $tpl = getTemplate('core', $version, 'reveil', 'reveil');
        // les templates de plugin ne sont pas traduits automatiquement : {{Congé}} -> Congé
        if (class_exists('translate')) {
            $tpl = translate::exec($tpl, 'plugins/reveil/core/template/' . $version . '/reveil.html');
        }
        $tpl = preg_replace('/\{\{(.*?)\}\}/s', '$1', $tpl);
        return $this->postToHtml($_version, template_replace($replace, $tpl));
    }

    /** Calendrier glissant : semaine courante (jours passés masqués) + 3 semaines. */
    private function widgetGrid() {
        $planning = $this->getPlanning();
        $months = array('', 'janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc');
        $today = date('Y-m-d');
        $d = new DateTime('monday this week');
        $html = '<div class="rv-head"><span></span><span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span></div><div class="rv-grid">';
        for ($i = 0; $i < 7 * self::WIDGET_WEEKS; $i++) {
            $date = $d->format('Y-m-d');
            if ($i % 7 == 0) {
                $custody = reveilCalendar::custodyWeek($date);
                $html .= '<div class="rv-week' . ($custody ? ' rv-custody' : '') . '" title="' . ($custody === null ? '' : ($custody ? 'Semaine avec enfant' : 'Semaine sans enfant')) . '">'
                    . 'S' . $d->format('W') . ($custody ? '<i class="fas fa-child"></i>' : '') . '</div>';
            }
            if ($date < $today) {
                // planning glissant : les jours passés ne sont plus affichés
                $html .= '<div class="rv-day rv-gone"></div>';
                $d->modify('+1 day');
                continue;
            }
            $day = self::computeDay($planning, $date);
            $classes = array('rv-day');
            if ($date < $today) {
                $classes[] = 'rv-past';
            }
            if ($date == $today) {
                $classes[] = 'rv-today';
            }
            if (reveilCalendar::isPersonalLeave($date) !== false) {
                $classes[] = 'rv-conges';
            } elseif (reveilCalendar::isFerie($date) !== false) {
                $classes[] = 'rv-ferie';
            }
            // orange uniquement pour une heure modifiée ; "pas de réveil" reste une case vide normale
            if ($day['source'] == 'exception' && $day['time'] !== null) {
                $classes[] = 'rv-exception';
            }
            if (reveilCalendar::custodyDay($date)) {
                $classes[] = 'rv-child';
            }
            $dayName = (strpos($day['source'], 'jour : ') === 0) ? $day['profile'] : '';
            if ($dayName !== '') {
                $classes[] = 'rv-dayprof';
            }
            if ($day['time'] === null) {
                $classes[] = 'rv-off';
            }
            $num = (int) $d->format('j');
            $label = ($num == 1 || $i == 0) ? $num . ' ' . $months[(int) $d->format('n')] : $num;
            $title = $date . ' — ' . ($day['time'] ?: 'pas de réveil') . ' (' . $day['source'] . ')';
            $html .= '<div class="' . implode(' ', $classes) . '" data-date="' . $date . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '">'
                . '<span class="rv-num">' . $label . '</span>'
                . '<span class="rv-time">' . ($day['time'] ?: '—') . '</span>'
                . ($dayName !== '' ? '<span class="rv-dname">' . htmlspecialchars(mb_substr($dayName, 0, 6), ENT_QUOTES) . '</span>' : '')
                . '</div>';
            $d->modify('+1 day');
        }
        return $html . '</div>';
    }

    /* ======================= Commandes ======================= */

    /** Nouvel équipement : activé, visible, planning entièrement vide (aucun profil). */
    public function preInsert() {
        $this->setIsEnable(1);
        $this->setIsVisible(1);
        $this->setConfiguration('adoptExternal', 1);
        if ($this->getConfiguration('planning', '') == '') {
            $this->setConfiguration('planning', array(
                'profiles' => array(),
                'defaultProfile' => '',
                'weeks' => array(),
                'garde' => array('avec' => '', 'sans' => ''),
                'exceptions' => array(),
                'rules' => array('conges' => 'off', 'ferie' => 'off', 'vacances' => 'ignore'),
            ));
        }
    }

    public function postSave() {
        $this->createCmds();
        if ($this->getIsEnable()) {
            $this->setCache('pushedFor', ''); // force une reprogrammation au prochain cron
        }
    }

    /** Crée les commandes manquantes (aussi appelé au rendu du widget pour les équipements existants). */
    public function createCmds() {
        $cmds = array(
            'next_wake' => array('Prochain réveil', 'info', 'string'),
            'next_wake_ts' => array('Prochain réveil (timestamp)', 'info', 'numeric'),
            'next_source' => array('Origine', 'info', 'string'),
            'week_profile' => array('Profil de la semaine', 'info', 'string'),
            'sync_ok' => array('Synchro OK', 'info', 'binary'),
            'alexa_ok' => array('Alexa OK', 'info', 'binary'),
            'remi_ok' => array('Rémi OK', 'info', 'binary'),
            'presence' => array('Présence au contrôle', 'info', 'binary'),
            'sync_status' => array('Statut synchro', 'info', 'string'),
            'last_check' => array('Dernière vérification', 'info', 'string'),
            'refresh' => array('Resynchroniser', 'action', 'other'),
            'skip_next' => array('Pas de prochain réveil', 'action', 'other'),
            'set_exception' => array('Exception', 'action', 'message'),
            'toggle_conges' => array('Basculer congé', 'action', 'message'),
            'toggle_garde' => array('Basculer garde', 'action', 'message'),
            'toggle_dayprofile' => array('Basculer profil du jour', 'action', 'message'),
        );
        $order = 0;
        foreach ($cmds as $logical => $def) {
            $cmd = $this->getCmd(null, $logical);
            if (!is_object($cmd)) {
                $cmd = new reveilCmd();
                $cmd->setLogicalId($logical);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName(__($def[0], __FILE__));
                $cmd->setType($def[1]);
                $cmd->setSubType($def[2]);
                $cmd->setOrder($order);
                if ($logical == 'set_exception') {
                    $cmd->setDisplay('title_placeholder', 'Date AAAA-MM-JJ');
                    $cmd->setDisplay('message_placeholder', 'HH:MM, off ou vide');
                }
                if (in_array($logical, array('next_wake_ts', 'alexa_ok', 'remi_ok', 'toggle_conges', 'toggle_garde', 'toggle_dayprofile'))) {
                    $cmd->setIsVisible(0);
                }
                $cmd->save();
            }
            $order++;
        }
    }
}

class reveilCmd extends cmd {

    public function execute($_options = array()) {
        $eq = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'refresh':
                $eq->syncTargets(true);
                break;
            case 'skip_next':
                $next = $eq->nextWake();
                if ($next) {
                    $eq->setException($next['date'], 'off');
                }
                break;
            case 'toggle_conges':
                reveilCalendar::togglePersonalLeave(trim($_options['title']));
                reveil::resyncAll();
                break;
            case 'toggle_garde':
                reveilCalendar::toggleCustodyDay(trim($_options['title']));
                reveil::resyncAll();
                break;
            case 'toggle_dayprofile':
                $eq->toggleDayProfile(trim($_options['title']), trim($_options['message']));
                break;
            case 'set_exception':
                $eq->setException(trim($_options['title']), isset($_options['message']) ? $_options['message'] : '');
                break;
        }
    }
}

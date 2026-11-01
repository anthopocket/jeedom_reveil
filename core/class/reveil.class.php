<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/reveilCalendar.class.php';
require_once dirname(__FILE__) . '/reveilRemi.class.php';

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

    const VERIFY_DELAY = 45;       // s entre la programmation et la relecture (vérifiée chaque minute)
    const CONFIRM_TRIES = 4;       // lectures Alexa juste après l'écriture…
    const CONFIRM_PAUSE = 2;       // …espacées de 2 s
    const RECHECK_EVERY = 240;     // contrôle de routine à chaque passage du cron (5 min), lecture légère
    const PREWAKE_CHECK = 2700;    // contrôle supplémentaire 45 min avant le réveil
    const HORIZON_DAYS = 21;

    private function setCaches($values) {
        foreach ($values as $k => $v) {
            $this->setCache($k, $v);
        }
    }

    /* ======================= Crons ======================= */

    /**
     * Chaque minute : uniquement les réveils dont une vérification est en attente
     * (le cron5 fait le reste, on l'évite donc les minutes multiples de 5).
     */
    public static function cron() {
        if (((int) date('i')) % 5 == 0) {
            return;
        }
        foreach (eqLogic::byType('reveil', true) as $eq) {
            if ($eq->getCache('verified', true) || !$eq->getCache('pushedAt', 0)
                || $eq->getCache('retry', 0) >= max(1, (int) config::byKey('maxRetry', 'reveil', 3))) {
                continue; // vérifié, jamais programmé, ou déjà en échec (le cron5 continue de surveiller)
            }
            try {
                $eq->syncTargets();
            } catch (Exception $e) {
                log::add('reveil', 'error', $eq->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

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

    /** Heure à partir de laquelle on passe au réveil suivant (configuration du plugin, 12:00 par défaut). */
    public static function switchTime() {
        $t = trim((string) config::byKey('switchTime', 'reveil', '12:00'));
        return preg_match('/^\d{1,2}:\d{2}$/', $t) ? $t : '12:00';
    }

    /**
     * Réveil qui doit être programmé sur les appareils : celui du jour reste en place
     * jusqu'à l'heure de bascule (ou au moins 1 h après sa sonnerie), pour ne jamais
     * toucher à une alarme qui va sonner ou qui sonne. 'passed' = déjà sonné, en attente de bascule.
     */
    private function setPassedStatus($wake) {
        $parts = array();
        foreach (array('Alexa' => 'alexaEnable', 'Rémi' => 'remiEnable') as $target => $key) {
            if (!$this->getConfiguration($key, 0)) {
                continue;
            }
            if ($target == 'Rémi') {
                $parts[] = $this->remiStatusPart();
            } else {
                $parts[] = $target . ' ' . $wake['time'] . ' passé';
            }
        }
        $this->checkAndUpdateCmd('sync_ok', 1);
        $this->setSyncStatus('OK : ' . (empty($parts) ? 'réveil de ' . $wake['time'] . ' passé' : implode(' | ', $parts))
            . ' — réveil suivant programmé à ' . date('H:i', $wake['keepUntil']));
    }

    public function programmedWake() {
        $planning = $this->getPlanning();
        $now = time();
        foreach (self::preview($planning, self::HORIZON_DAYS) as $day) {
            if ($day['time'] === null) {
                continue;
            }
            $ts = strtotime($day['date'] . ' ' . $day['time']);
            $keep = max($ts + 3600, strtotime($day['date'] . ' ' . self::switchTime()));
            if ($keep > $now) {
                $day['timestamp'] = $ts;
                $day['datetime'] = $day['date'] . ' ' . $day['time'];
                $day['passed'] = ($ts <= $now);
                $day['keepUntil'] = $keep;
                return $day;
            }
        }
        return null;
    }

    /**
     * Un réveil n'est mis sur les appareils qu'à partir de la veille, à l'heure de passage
     * (12:00 par défaut) : jamais plusieurs jours à l'avance.
     */
    public static function armTime($wake) {
        return strtotime(date('Y-m-d', strtotime($wake['date'] . ' -1 day')) . ' ' . self::switchTime());
    }

    private static function shortDay($ts) {
        $j = array(1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.');
        return $j[(int) date('N', $ts)] . ' ' . date('d/m', $ts);
    }

    /** Rien n'est encore programmé : le prochain réveil sera mis en place la veille. */
    private function setIdle($next, $now, $force) {
        $this->updateInfos($next);
        // l'Echo et le Rémi sont libres : pas de dérogation possible tant que rien n'est programmé
        if ($this->getCache('holdActive', false) || $this->getCache('derogAlexa', null) || $this->getCache('derogRémi', null)) {
            $this->setCaches(array('holdActive' => false, 'heldNext' => null, 'derogAlexa' => null, 'derogRémi' => null));
        }
        $sig = 'idle|' . $next['datetime'];
        if ($force || $sig !== $this->getCache('pushedFor', '')) {
            // à la mise en place, l'Alexa sera (re)programmée
            $this->setCaches(array('pushedFor' => $sig, 'pushedAlexa' => '', 'verified' => true, 'retry' => 0, 'alerted' => false));
            log::add('reveil', 'info', $this->getHumanName() . ' prochain réveil ' . $next['datetime'] . ', mis en place le ' . date('Y-m-d H:i', self::armTime($next)));
        }
        // le Rémi suit son propre réveil (heure forcée possible), indépendamment de l'attente de l'Alexa
        $this->syncRemiOnly();
        $parts = array();
        if ($this->getConfiguration('alexaEnable', 0)) {
            $parts[] = 'Alexa libre';
        }
        if ($this->getConfiguration('remiEnable', 0)) {
            $parts[] = $this->remiStatusPart();
        }
        $this->checkAndUpdateCmd('sync_ok', 1);
        $this->setSyncStatus('OK : ' . (empty($parts) ? 'rien à programmer' : implode(' | ', $parts))
            . ' — réveil du ' . self::shortDay($next['timestamp']) . ' ' . $next['time'] . ' mis en place le ' . self::shortDay(self::armTime($next)) . ' à ' . date('H:i', self::armTime($next)));
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
        $now = time();
        if ($force) {
            // "Resynchroniser" : le prochain réveil du Rémi est aussi mis en place tout de suite
            $rw = $this->remiWakes(2);
            foreach ($rw as $w) {
                if (!$w['passed']) {
                    $this->setCache('manualArmRemi', $w['datetime']);
                    break;
                }
            }
        }
        $next = $this->programmedWake();
        if ($next !== null && $next['passed']) {
            if (!$force) {
                // le réveil du jour a sonné : on ne touche à rien jusqu'à l'heure de bascule
                $this->updateInfos($next);
                if ($this->holdUntil() > $now) {
                    $this->watchDuringHold($now); // une alarme réglée à la main n'a pas encore sonné
                } else {
                    $this->syncRemiOnly();
                    $this->setPassedStatus($next);
                }
                return;
            }
            // "Resynchroniser" : on passe tout de suite au réveil suivant
            $next = $this->nextWake();
        }
        if ($next !== null && $now < self::armTime($next)) {
            if ($force) {
                // "Resynchroniser" / "Programmer maintenant" : on programme tout de suite,
                // et on s'en souvient pour que le cron ne repasse pas en attente
                $this->setCache('manualArm', $next['datetime']);
                log::add('reveil', 'info', $this->getHumanName() . ' réveil du ' . $next['datetime'] . ' programmé à la demande, sans attendre la veille');
            } elseif ($this->getCache('manualArm', '') !== $next['datetime']) {
                // trop tôt : le réveil ne sera programmé que la veille
                $this->setIdle($next, $now, $force);
                return;
            }
        }
        $remiNext = $this->remiTarget($next);
        // la signature inclut le réveil armé sur le Rémi (garde) : s'il change, le Rémi est reprogrammé
        $sig = ($next ? $next['datetime'] : 'none') . '|' . ($remiNext ? 'R' . $remiNext['datetime'] : '-');
        $this->updateInfos($next);

        // Dérogation manuelle en cours : on ne reprogramme RIEN tant que tous les réveils
        // (prévus et réglés à la main) ne sont pas passés. On continue seulement de surveiller.
        if (!$force && $this->holdUntil() > $now) {
            $this->watchDuringHold($now);
            return;
        }
        if ($this->getCache('holdActive', false)) {
            // fin de l'attente : le planning reprend la main sur tous les appareils
            $this->setCaches(array('holdActive' => false, 'heldNext' => null));
            log::add('reveil', 'info', $this->getHumanName() . ' dérogations terminées : reprise du planning');
            $force = true;
        }

        if ($force || $sig !== $this->getCache('pushedFor', '')) {
            $pushed = $this->pushAll($next, !$force);
            if ($pushed === 0 && !$force) {
                // appareils déjà à jour (ex. réveils ajoutés plus loin dans le planning) : rien à revérifier
                $this->setCache('pushedFor', $sig);
                return;
            }
            $this->setCaches(array('pushedFor' => $sig, 'pushedAt' => $now, 'verified' => false, 'retry' => 0, 'alerted' => false, 'lastCheck' => $now));
            $remiOn = $this->getConfiguration('remiEnable', 0);
            $alexaOn = $this->getConfiguration('alexaEnable', 0);
            $derogA = $this->activeDerogation('Alexa');
            $derogR = $this->activeDerogation('Rémi');
            // un appareil en dérogation manuelle n'a pas été reprogrammé : il compte comme confirmé
            $remiConfirmed = $remiOn && ($derogR || $this->getCache('remiConfirmed', false));
            $alexaConfirmed = $alexaOn && ($derogA || $this->getCache('alexaConfirmed', false));
            if ($remiConfirmed) {
                $this->checkAndUpdateCmd('remi_ok', 1);
            }
            if ($alexaConfirmed) {
                $this->checkAndUpdateCmd('alexa_ok', 1);
            }
            // état de chaque appareil : confirmé à l'écriture, en dérogation, ou en attente de vérification
            $parts = array();
            if ($alexaOn) {
                $parts[] = 'Alexa ' . ($derogA ? 'dérogation manuelle (' . $derogA['value'] . ')'
                    : (!$alexaConfirmed ? 'vérification en attente' : ($next ? $next['time'] . ' OK' : 'aucune alarme')));
            }
            if ($remiOn) {
                $remiNext = $this->remiTarget($next);
                $parts[] = 'Rémi ' . ($derogR ? 'dérogation manuelle (' . $derogR['value'] . ')'
                    : (!$remiConfirmed ? 'vérification en attente' : ($remiNext ? ($remiNext['passed'] ? $remiNext['time'] . ' passé' : self::shortDay($remiNext['timestamp']) . ' ' . $remiNext['time'] . ' OK') : $this->remiWaitReason())));
            }
            if ((!$remiOn || $remiConfirmed) && (!$alexaOn || $alexaConfirmed) && ($remiOn || $alexaOn)) {
                // tout confirmé à l'écriture : vérifié tout de suite, pas besoin d'attendre le cron
                $this->setCaches(array('verified' => true));
                $this->checkAndUpdateCmd('sync_ok', 1);
                $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));
                $this->setSyncStatus('OK : ' . implode(' | ', $parts));
            } else {
                $this->checkAndUpdateCmd('sync_ok', 0);
                $this->setSyncStatus('Programmé : ' . implode(' | ', $parts));
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

        $result = $this->verifyAll($next, $verified);
        $this->setCache('lastCheck', $now);
        $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));

        if ($result['ok']) {
            $this->setCaches(array('verified' => true, 'retry' => 0, 'alerted' => false));
            $this->checkAndUpdateCmd('sync_ok', 1);
            $this->setSyncStatus('OK : ' . $result['detail']);
            return;
        }

        $this->checkAndUpdateCmd('sync_ok', 0);
        $retry = $this->getCache('retry', 0) + 1;
        $max = max(1, (int) config::byKey('maxRetry', 'reveil', 3));
        // après l'alerte, on continue de vérifier (pour voir le retour à la normale) sans remplir le log
        log::add('reveil', $retry <= $max ? 'warning' : 'debug', $this->getHumanName() . ' vérification KO (' . $retry . '/' . $max . ') : ' . $result['detail']);
        if ($retry < $max) {
            $this->pushAll($next, false);
            $this->setCaches(array('verified' => false, 'retry' => $retry, 'pushedAt' => $now));
            $this->setSyncStatus('Écart, nouvel essai ' . $retry . ' : ' . $result['detail']);
            return;
        }
        $this->setCaches(array('verified' => false, 'retry' => $retry, 'pushedAt' => $now));
        $this->setSyncStatus('ÉCHEC : ' . $result['detail']);
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
     * Il n'est donc armé que la veille du réveil (voir armTime) et désactivé le reste du temps.
     */
    /** Le Rémi ne sonne que les jours avec mon enfant (case bleue). Coché par défaut. */
    private function remiOnlyChild() {
        return (bool) $this->getConfiguration('remiOnlyChild', 1);
    }

    /**
     * Heure du Rémi pour un jour : son heure forcée (crayon Rémi) si elle existe,
     * sinon celle du planning, seulement les jours avec enfant (option cochée).
     */
    private function remiDayTime($day, $remiExceptions) {
        $date = $day['date'];
        if (isset($remiExceptions[$date])) {
            return $remiExceptions[$date] === 'off' ? null : $remiExceptions[$date];
        }
        if ($this->remiOnlyChild() && !reveilCalendar::custodyDay($date)) {
            return null;
        }
        return $day['time'];
    }

    /**
     * Réveils du Rémi à partir de maintenant (celui qui vient de sonner reste en tête
     * jusqu'à l'heure de passage, comme pour l'Alexa).
     * @return array liste de ['date','time','timestamp','datetime','passed']
     */
    private function remiWakes($limit = 2) {
        $planning = $this->getPlanning();
        $rx = (isset($planning['remiExceptions']) && is_array($planning['remiExceptions'])) ? $planning['remiExceptions'] : array();
        $now = time();
        $out = array();
        foreach (self::preview($planning, self::HORIZON_DAYS) as $day) {
            $t = $this->remiDayTime($day, $rx);
            if ($t === null) {
                continue;
            }
            $ts = strtotime($day['date'] . ' ' . $t);
            if (max($ts + 3600, strtotime($day['date'] . ' ' . self::switchTime())) <= $now) {
                continue;
            }
            $out[] = array('date' => $day['date'], 'time' => $t, 'timestamp' => $ts, 'datetime' => $day['date'] . ' ' . $t, 'passed' => $ts <= $now,
                'source' => isset($rx[$day['date']]) ? 'Rémi : heure forcée' : $day['source']);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Réveil à mettre sur le Rémi : le sien (il suit le planning de Papa, sauf heure forcée),
     * mis en place la veille à l'heure de passage comme l'Alexa, ou tout de suite après « Resynchroniser ».
     * $next est ignoré (gardé pour compatibilité des appels).
     */
    private function remiTarget($next = null) {
        $wakes = $this->remiWakes(1);
        if (empty($wakes)) {
            return null;
        }
        $w = $wakes[0];
        if ($w['passed'] || time() >= self::armTime($w) || $this->getCache('manualArmRemi', '') === $w['datetime']) {
            return $w;
        }
        return null;
    }

    /** Pourquoi le Rémi n'est pas armé. */
    private function remiWaitReason($next = null) {
        $wakes = $this->remiWakes(1);
        if (empty($wakes)) {
            return 'aucun réveil';
        }
        $w = $wakes[0];
        return 'prévu ' . self::shortDay($w['timestamp']) . ' ' . $w['time'] . ', mis en place la veille';
    }

    /** Morceau de statut du Rémi (pour les états où seul le Rémi bouge). */
    private function remiStatusPart() {
        $t = $this->remiTarget();
        if ($t === null) {
            return 'Rémi ' . $this->remiWaitReason();
        }
        if ($t['passed']) {
            return 'Rémi ' . $t['time'] . ' passé';
        }
        return 'Rémi ' . ($this->getCache('remiConfirmed', false) ? self::shortDay($t['timestamp']) . ' ' . $t['time'] . ' OK' : 'vérification en attente');
    }

    /**
     * Le Rémi a son propre réveil : quand l'Alexa est en attente (réveil passé, veille pas encore
     * atteinte), on le met quand même à jour s'il a changé.
     */
    private function syncRemiOnly() {
        if (!$this->getConfiguration('remiEnable', 0) || $this->activeDerogation('Rémi') !== null) {
            return;
        }
        $t = $this->remiTarget();
        $sig = $t ? $t['datetime'] : 'none';
        if ($sig === $this->getCache('pushedRemi', '')) {
            return;
        }
        try {
            $this->setCache('remiConfirmed', false);
            $this->pushRemi($t);
            $this->setCache('pushedRemi', $sig);
            $check = $this->verifyRemi($t);
            $this->setCache('remiConfirmed', $check['ok']);
            $this->checkAndUpdateCmd('remi_ok', $check['ok'] ? 1 : 0);
            log::add('reveil', 'info', $this->getHumanName() . ' Rémi : ' . ($t ? $t['datetime'] . ' (' . $t['source'] . ')' : 'désactivé'));
        } catch (Exception $e) {
            log::add('reveil', 'error', $this->getHumanName() . ' Rémi : ' . $e->getMessage());
        }
    }

    /**
     * @param bool $onlyChanged ne reprogrammer que les cibles dont la consigne a changé
     *                          (évite par exemple de recréer l'alarme Alexa quand seul le Rémi s'arme)
     */
    /** @return int nombre d'appareils réellement reprogrammés */
    private function pushAll($next, $onlyChanged = false) {
        $pushed = 0;
        $remiNext = $this->remiTarget($next);
        $alexaSig = $next ? $next['datetime'] : 'none';
        $remiSig = $remiNext ? $remiNext['datetime'] : 'none';
        if (!$onlyChanged) {
            // "Programmer maintenant" / nouvel essai : le planning reprend la main partout
            $this->setCache('derogAlexa', null);
            $this->setCache('derogRémi', null);
        }
        $derogAlexa = $this->activeDerogation('Alexa', $next) !== null;
        $derogRemi = $this->activeDerogation('Rémi', $next) !== null;
        if ($this->getConfiguration('alexaEnable', 0) && !$derogAlexa && (!$onlyChanged || $alexaSig !== $this->getCache('pushedAlexa', ''))) {
            $pushed++;
            try {
                $this->setCache('alexaConfirmed', false);
                $this->pushAlexa($next);
                $this->setCache('pushedAlexa', $alexaSig);
                // confirmation immédiate : on rafraîchit les infos d'Alexa Premium puis on relit
                // (l'info Alexa est mise à jour presque en temps réel : quelques lectures rapprochées suffisent)
                for ($i = 1; $i <= self::CONFIRM_TRIES; $i++) {
                    try {
                        $check = $this->verifyAlexa($next);
                        if ($check['ok']) {
                            $this->setCache('alexaConfirmed', true);
                            log::add('reveil', 'debug', $this->getHumanName() . ' Alexa confirmée après ' . $i . ' lecture(s)');
                            break;
                        }
                    } catch (Exception $e) {
                        log::add('reveil', 'debug', 'confirmation Alexa : ' . $e->getMessage());
                    }
                    if ($i < self::CONFIRM_TRIES) {
                        sleep(self::CONFIRM_PAUSE);
                    }
                }
            } catch (Exception $e) {
                log::add('reveil', 'error', $this->getHumanName() . ' Alexa : ' . $e->getMessage());
            }
        }
        if ($this->getConfiguration('remiEnable', 0) && !$derogRemi && (!$onlyChanged || $remiSig !== $this->getCache('pushedRemi', ''))) {
            $pushed++;
            try {
                $this->setCache('remiConfirmed', false);
                $this->pushRemi($remiNext);
                $this->setCache('pushedRemi', $remiSig);
                // remi relit le cloud juste après chaque écriture : on peut confirmer tout de suite.
                // Une fois confirmé, tout écart ultérieur vient forcément de l'appli (et non d'un échec).
                try {
                    $check = $this->verifyRemi($next);
                    $this->setCache('remiConfirmed', $check['ok']);
                } catch (Exception $e) {
                    log::add('reveil', 'debug', 'confirmation Rémi : ' . $e->getMessage());
                }
                if ($remiNext === null) {
                    log::add('reveil', 'info', $this->getHumanName() . ' Rémi désactivé : ' . $this->remiWaitReason());
                }
            } catch (Exception $e) {
                log::add('reveil', 'error', $this->getHumanName() . ' Rémi : ' . $e->getMessage());
            }
        }
        if ($pushed > 0) {
            $this->setCache('prewakeDone', false);
            log::add('reveil', 'info', $this->getHumanName() . ' programmé : ' . ($next ? $next['datetime'] . ' (' . $next['source'] . ')' : 'aucun réveil'));
        }
        return $pushed;
    }

    /**
     * Vérifie chaque appareil. Un appareil déjà confirmé (vérifié OK, ou relu juste après l'écriture)
     * qui diffère ensuite a été modifié à la main : il passe en "dérogation manuelle" et n'est plus
     * corrigé jusqu'au réveil suivant. Il n'impacte ni l'autre appareil ni le planning.
     */
    private function verifyAll($next, $verified = false) {
        $ok = true;
        $details = array();
        $adopt = $this->getConfiguration('adoptExternal', 1);
        $targets = array(
            'Alexa' => array('alexaEnable', 'alexa_ok', 'alexaConfirmed'),
            'Rémi' => array('remiEnable', 'remi_ok', 'remiConfirmed'),
        );
        foreach ($targets as $target => $def) {
            if (!$this->getConfiguration($def[0], 0)) {
                continue;
            }
            $derog = $this->activeDerogation($target, $next);
            if ($derog !== null) {
                $details[] = $target . ' dérogation manuelle (' . $derog['value'] . ')';
                $this->checkAndUpdateCmd($def[1], 1);
                continue;
            }
            try {
                $r = ($target == 'Alexa') ? $this->verifyAlexa($next) : $this->verifyRemi($next);
            } catch (Exception $e) {
                $r = array('ok' => false, 'detail' => $e->getMessage());
            }
            if (!$r['ok'] && $adopt && ($verified || $this->getCache($def[2], false))) {
                // déjà confirmé puis différent : modifié à la main sur l'appareil
                $value = $this->readManualState($target);
                $this->setCache('derog' . $target, array('value' => $value, 'until' => $this->derogationEnd($target, $next)));
                if (!$this->getCache('holdActive', false)) {
                    // réveil sur lequel porte l'attente (sert de référence pour surveiller l'autre appareil)
                    $this->setCaches(array('holdActive' => true, 'heldNext' => $next, 'heldSince' => time()));
                }
                log::add('reveil', 'info', $this->getHumanName() . ' ' . $target . ' : dérogation manuelle (' . $value . '), laissée telle quelle jusqu\'à son passage');
                $r = array('ok' => true, 'detail' => 'dérogation manuelle (' . $value . ')');
            }
            $ok = $ok && $r['ok'];
            $details[] = $target . ' ' . $r['detail'];
            $this->checkAndUpdateCmd($def[1], $r['ok'] ? 1 : 0);
        }
        return array('ok' => $ok, 'detail' => implode(' | ', $details));
    }

    /**
     * Vérification à la demande : relit l'Alexa et le Rémi et met à jour les statuts,
     * SANS rien reprogrammer. Pendant une dérogation, la référence reste le réveil mis en attente.
     */
    public function verifyNow() {
        $now = time();
        $next = $this->programmedWake();
        $this->updateInfos($next);
        if ($next !== null && $next['passed'] && $this->holdUntil() <= $now) {
            $this->setPassedStatus($next); // rien à vérifier avant la bascule
            return;
        }
        // valeurs fraîches avant lecture (l'Alexa est relue en direct par verifyAlexa)
        if ($this->getConfiguration('remiEnable', 0) && $this->getConfiguration('remiEqId', '') !== '') {
            try {
                reveilRemi::refresh(reveilRemi::equipment($this->getConfiguration('remiEqId')));
            } catch (Exception $e) {
                log::add('reveil', 'debug', 'vérification : rafraîchissement Rémi : ' . $e->getMessage());
            }
        }
        $held = $this->getCache('heldNext', null);
        if ($this->holdUntil() > $now && is_array($held) && $held['timestamp'] > $now) {
            $this->setCache('lastCheck', 0); // force la relecture dans watchDuringHold
            $this->watchDuringHold($now);
            log::add('reveil', 'info', $this->getHumanName() . ' vérification manuelle (dérogation en cours)');
            return;
        }
        $result = $this->verifyAll($next, $this->getCache('verified', false));
        $this->setCache('lastCheck', $now);
        $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));
        if ($result['ok']) {
            $this->setCaches(array('verified' => true, 'retry' => 0, 'alerted' => false));
            $this->checkAndUpdateCmd('sync_ok', 1);
            $this->setSyncStatus('OK : ' . $result['detail']);
        } else {
            $this->checkAndUpdateCmd('sync_ok', 0);
            $this->setSyncStatus('KO : ' . $result['detail']);
        }
        log::add('reveil', 'info', $this->getHumanName() . ' vérification manuelle : ' . ($result['ok'] ? 'OK' : 'KO') . ' (' . $result['detail'] . ')');
    }

    /** Fin de l'attente : la plus tardive des dérogations en cours (0 si aucune). */
    private function holdUntil() {
        $until = 0;
        foreach (array('Alexa', 'Rémi') as $target) {
            $d = $this->activeDerogation($target);
            if ($d !== null) {
                $until = max($until, $d['until']);
            }
        }
        return $until;
    }

    /**
     * Pendant l'attente : aucun envoi. On surveille l'autre appareil par rapport au réveil
     * sur lequel il a été programmé ; s'il est modifié à la main, il passe aussi en dérogation.
     */
    private function watchDuringHold($now) {
        $held = $this->getCache('heldNext', null);
        if (($now - $this->getCache('lastCheck', 0)) >= self::RECHECK_EVERY && is_array($held) && $held['timestamp'] > $now) {
            $this->verifyAll($held, true);
            $this->setCache('lastCheck', $now);
            $this->checkAndUpdateCmd('last_check', date('Y-m-d H:i:s'));
        }
        $parts = array();
        foreach (array('Alexa' => 'alexaEnable', 'Rémi' => 'remiEnable') as $target => $key) {
            if (!$this->getConfiguration($key, 0)) {
                continue;
            }
            $d = $this->activeDerogation($target);
            $old = $this->getCache('derog' . $target, null);
            if ($d) {
                $parts[] = $target . ' dérogation manuelle (' . $d['value'] . ')';
            } elseif (is_array($old) && isset($old['until']) && $old['until'] > $this->getCache('heldSince', 0)) {
                $parts[] = $target . ' dérogation passée (' . $old['value'] . ')';
            } elseif ($target == 'Rémi') {
                $parts[] = $this->remiStatusPart();
            } else {
                $parts[] = $target . ' ' . (is_array($held) ? $held['time'] : '') . ' OK';
            }
        }
        $this->checkAndUpdateCmd('sync_ok', 1);
        $this->setSyncStatus('OK : ' . implode(' | ', $parts) . ' — planning repris après ' . date('H:i', $this->holdUntil()));
    }

    /**
     * Met à jour « Statut synchro » et, à partir du même texte, les commandes
     * « Statut Alexa » et « Statut Rémi » (une info lisible par appareil).
     */
    private function setSyncStatus($text) {
        $cmd = $this->getCmd(null, 'sync_status');
        $changed = is_object($cmd) && (string) $cmd->execCmd() !== (string) $text;
        $this->checkAndUpdateCmd('sync_status', $text);
        if ($changed) {
            $this->refreshWidget(); // le widget affiche le statut en clair : on vide son cache
        }
        $body = $text;
        $note = '';
        if (($cut = mb_strpos($body, ' — ')) !== false) {
            $note = mb_substr($body, $cut + 3);
            $body = mb_substr($body, 0, $cut);
        }
        $state = '';
        if (preg_match('/^(OK|Programmé|KO|ÉCHEC|Écart, nouvel essai \d+)\s*:\s*(.*)$/u', $body, $m)) {
            $state = $m[1];
            $body = $m[2];
        }
        $per = array();
        if (preg_match('/vérification en attente \(([^)]*)\)/u', $body, $m)) {
            foreach (array_map('trim', explode(',', $m[1])) as $t) {
                $per[$t] = 'programmé, vérification en attente';
            }
        } else {
            foreach (explode(' | ', $body) as $part) {
                if (preg_match('/^(Alexa|Rémi)\s+(.*)$/u', trim($part), $m)) {
                    $value = $m[2];
                    if ($state !== '' && $state !== 'OK' && $state !== 'Programmé' && !preg_match('/\sOK$/u', ' ' . $value)) {
                        $value = $state . ' : ' . $value;
                    }
                    if ($note !== '') {
                        $value .= ' — ' . $note;
                    }
                    $per[$m[1]] = $value;
                }
            }
        }
        foreach (array('Alexa' => array('alexaEnable', 'alexa_status'), 'Rémi' => array('remiEnable', 'remi_status')) as $target => $def) {
            if (!$this->getConfiguration($def[0], 0)) {
                $this->checkAndUpdateCmd($def[1], 'non utilisé');
            } elseif (isset($per[$target])) {
                $this->checkAndUpdateCmd($def[1], $per[$target]);
            }
        }
    }

    /** Dérogation manuelle en cours sur $target (null si aucune ou terminée). */
    private function activeDerogation($target, $next = null) {
        $d = $this->getCache('derog' . $target, null);
        return (is_array($d) && isset($d['until']) && $d['until'] > time()) ? $d : null;
    }

    /**
     * Fin de la dérogation : après la plus tardive des deux heures (prévue / réglée à la main),
     * pour ne jamais supprimer une alarme manuelle avant qu'elle sonne. Limitée à 24 h après l'heure prévue.
     */
    private function derogationEnd($target, $next) {
        $planned = $next ? $next['timestamp'] : time();
        $manual = $planned;
        try {
            if ($target == 'Alexa') {
                $alarms = $this->alexaAlarms();
                $read = null;
                if ($alarms !== null) {
                    // alarme active la plus proche = celle qui sonnera
                    foreach ($alarms as $a) {
                        if ($a['status'] == 'ON' && $a['time'] !== null && ($read === null || ($a['date'] . $a['time']) < ($read['date'] . $read['time']))) {
                            $read = $a;
                        }
                    }
                } else {
                    $info = $this->cmdFromConf('alexaNextCmd');
                    $read = $info ? self::parseAlexaValue($info->execCmd()) : null;
                }
                if ($read !== null) {
                    $manual = strtotime(($read['date'] ?: ($next ? $next['date'] : date('Y-m-d'))) . ' ' . $read['time']);
                }
            } else {
                $rt = $this->remiTarget();
                if ($rt) {
                    $planned = $manual = $rt['timestamp'];
                }
                list($eqId, $name) = $this->remiParams();
                $alarm = reveilRemi::find(reveilRemi::equipment($eqId), $name, false);
                if ($alarm !== null && $alarm['enabled'] && $rt) {
                    $manual = strtotime($rt['date'] . ' ' . $alarm['time']);
                }
            }
        } catch (Exception $e) {
        }
        return max($planned, min($manual, $planned + 86400)) + 60;
    }

    /** Ce que l'appareil a réellement, en clair (pour le statut et le log). */
    private function readManualState($target) {
        try {
            if ($target == 'Alexa') {
                $alarms = $this->alexaAlarms();
                if ($alarms !== null) {
                    $on = array();
                    foreach ($alarms as $a) {
                        if ($a['status'] == 'ON') {
                            $on[] = trim($a['date'] . ' ' . $a['time']);
                        }
                    }
                    sort($on);
                    return empty($on) ? 'aucune alarme' : implode(', ', $on);
                }
                $info = $this->cmdFromConf('alexaNextCmd');
                $read = $info ? self::parseAlexaValue($info->execCmd()) : null;
                return $read === null ? 'aucune alarme' : trim($read['date'] . ' ' . $read['time']);
            }
            list($eqId, $name) = $this->remiParams();
            $alarm = reveilRemi::find(reveilRemi::equipment($eqId), $name, false);
            if ($alarm === null || !$alarm['enabled']) {
                return 'désactivé';
            }
            return $alarm['time'] . ($alarm['days'] !== '' ? ' ' . $alarm['days'] : '');
        } catch (Exception $e) {
            return '?';
        }
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

    /**
     * Commande Alexa Premium de l'Echo choisi, par son logicalId (deleteallalarms, updateallalarms…).
     * Prise sur le même équipement que la commande de création d'alarme : marche même si le champ
     * correspondant est vide ou contient une autre commande (ex. removeAlarm, qui exige un jeton).
     */
    private function alexaSiblingCmd($confKey, $logicalId) {
        $cmd = ($confKey === '') ? null : $this->cmdFromConf($confKey);
        if ($cmd && $cmd->getLogicalId() == $logicalId) {
            return $cmd;
        }
        $add = $this->cmdFromConf('alexaAlarmCmd');
        $eq = $add ? $add->getEqLogic() : null;
        $sibling = is_object($eq) ? $eq->getCmd(null, $logicalId) : null;
        if (is_object($sibling)) {
            if ($cmd) {
                log::add('reveil', 'debug', $this->getHumanName() . ' Alexa : "' . $cmd->getLogicalId() . '" remplacée par "' . $logicalId . '"');
            }
            return $sibling;
        }
        return $cmd;
    }

    /**
     * Liste FRAÎCHE des alarmes de l'Echo, lue directement chez Amazon via Alexa Premium,
     * puis redistribuée dans ses commandes (nextAlarm, liste de removeAlarm…).
     * Alexa Premium garde sinon cette liste en cache pour toute la durée du processus PHP :
     * un "updateallalarms" lancé juste après une création ou une suppression renvoyait l'ancien état.
     * @return array|null [['token','status','date','time','raw']], null si Alexa Premium n'est pas joignable
     */
    private function alexaAlarms() {
        $add = $this->cmdFromConf('alexaAlarmCmd');
        $eq = $add ? $add->getEqLogic() : null;
        if (!is_object($eq) || !class_exists('alexaapiv2') || !class_exists('alexa_Api') || !method_exists('alexa_Api', 'mapedNotifs')) {
            $refresh = $this->alexaSiblingCmd('alexaRefreshCmd', 'updateallalarms');
            if ($refresh) {
                try {
                    $refresh->execCmd();
                } catch (Exception $e) {
                }
            }
            return null;
        }
        try {
            try {
                Closure::bind(static function () {
                    self::$_mapedNotifs = null;
                }, null, 'alexaapiv2')();
            } catch (Throwable $e) {
                // cache non vidé : la liste lue ci-dessous reste fraîche, seules les commandes peuvent attendre
            }
            $sn = $eq->getLogicalId();
            $map = alexa_Api::mapedNotifs();
            $eq->deployEqNotifs($sn, (is_array($map) && !empty($map)) ? $map : null);
            $alarms = array();
            $list = (is_array($map) && isset($map[$sn]['Alarm']) && is_array($map[$sn]['Alarm'])) ? $map[$sn]['Alarm'] : array();
            foreach ($list as $a) {
                $parts = isset($a['id']) ? preg_split('/-/', $a['id'], 3) : array();
                if (count($parts) < 3) {
                    continue;
                }
                $when = self::parseAlexaValue(isset($a['dateTime']) ? $a['dateTime'] : '');
                $alarms[] = array(
                    'token' => $parts[2],
                    'status' => isset($a['status']) ? $a['status'] : '',
                    'date' => $when ? $when['date'] : null,
                    'time' => $when ? $when['time'] : null,
                    'raw' => isset($a['dateTime']) ? $a['dateTime'] : '',
                );
            }
            return $alarms;
        } catch (Throwable $e) {
            log::add('reveil', 'debug', $this->getHumanName() . ' Alexa : lecture directe impossible (' . $e->getMessage() . ')');
            return null;
        }
    }

    /** Supprime une alarme de l'Echo par son jeton (commande removeAlarm d'Alexa Premium). */
    private function alexaRemove($alarm) {
        $rm = $this->alexaSiblingCmd('', 'removeAlarm');
        if ($rm && $rm->getLogicalId() == 'removeAlarm') {
            $rm->execCmd(array('select' => $alarm['token'] . '#' . $alarm['status']));
        } else {
            alexa_Api::removeAlarm($alarm['token']);
        }
    }

    private function pushAlexa($next) {
        // Avant de créer l'alarme, toutes les autres alarmes de l'Echo sont supprimées : jamais de doublon.
        // Si l'alarme voulue existe déjà, elle est gardée (pas de suppression/recréation inutile).
        $alarms = $this->alexaAlarms();
        if ($alarms !== null) {
            $kept = false;
            $removed = 0;
            foreach ($alarms as $a) {
                if (!$kept && $next && $a['status'] == 'ON' && $a['date'] == $next['date'] && $a['time'] == $next['time']) {
                    $kept = true;
                    continue;
                }
                try {
                    $this->alexaRemove($a);
                    $removed++;
                } catch (Exception $e) {
                    log::add('reveil', 'warning', $this->getHumanName() . ' Alexa : suppression de l\'alarme ' . $a['raw'] . ' impossible (' . $e->getMessage() . ')');
                }
            }
            log::add('reveil', 'debug', $this->getHumanName() . ' Alexa : ' . count($alarms) . ' alarme(s) lue(s), ' . $removed . ' supprimée(s)' . ($kept ? ', la bonne est gardée' : ''));
            if ($kept || $next === null) {
                if ($removed > 0) {
                    $this->alexaAlarms(); // commandes d'Alexa Premium à jour
                }
                return;
            }
        } else {
            // Alexa Premium non joignable directement : suppression par sa commande deleteallalarms
            $del = $this->alexaSiblingCmd('alexaDeleteCmd', 'deleteallalarms');
            if ($del && $del->getLogicalId() == 'deleteallalarms') {
                try {
                    $del->execCmd();
                } catch (Exception $e) {
                    log::add('reveil', 'warning', $this->getHumanName() . ' Alexa deleteallalarms : ' . $e->getMessage());
                }
            } else {
                log::add('reveil', 'warning', $this->getHumanName() . ' Alexa : commande deleteallalarms introuvable, les anciennes alarmes restent (risque de doublons)');
            }
            if ($next === null) {
                return;
            }
        }
        $cmd = $this->cmdFromConf('alexaAlarmCmd');
        if (!$cmd) {
            throw new Exception('commande de création d\'alarme non configurée');
        }
        $tpl = $this->getConfiguration('alexaTemplate', '#date# #time#:00');
        $msg = str_replace(array('#date#', '#time#', '#datetime#'), array($next['date'], $next['time'], $next['datetime']), $tpl);
        // Alexa Premium (addAlarm) : message = date/heure, sound = sonnerie (obligatoire)
        $sound = trim($this->getConfiguration('alexaSound', '')) ?: 'system_alerts_melodic_01';
        try {
            $cmd->execCmd(array('title' => $msg, 'message' => $msg, 'sound' => $sound));
        } catch (Exception $e) {
            // Amazon refuse un doublon : l'alarme voulue est déjà sur l'Echo, c'est donc un succès
            if (stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
            log::add('reveil', 'info', $this->getHumanName() . ' Alexa : alarme du ' . $msg . ' déjà présente sur l\'Echo');
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
    private function verifyFromInfo($infoKey, $refreshKey, $next, $refreshAfter = true) {
        $info = $this->cmdFromConf($infoKey);
        if (!$info) {
            return array('ok' => false, 'detail' => 'commande info non configurée');
        }
        // rafraîchit AVANT de lire (l'info Alexa est mise à jour quasi en temps réel)
        $refresh = $refreshAfter ? $this->alexaSiblingCmd($refreshKey, 'updateallalarms') : null;
        if ($refresh) {
            try {
                $refresh->execCmd();
            } catch (Exception $e) {
                log::add('reveil', 'debug', 'rafraîchissement Alexa : ' . $e->getMessage());
            }
        }
        $raw = $info->execCmd();
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

    private function verifyAlexa($next, $refreshAfter = true) {
        $alarms = $this->alexaAlarms(); // lecture directe et fraîche (met aussi à jour les infos d'Alexa Premium)
        if ($alarms === null) {
            return $this->verifyFromInfo('alexaNextCmd', 'alexaRefreshCmd', $next, false);
        }
        $on = array_values(array_filter($alarms, function ($a) {
            return $a['status'] == 'ON';
        }));
        if ($next === null) {
            $ok = empty($on);
            return array('ok' => $ok, 'detail' => $ok ? 'aucun réveil' : 'réveil restant ' . $on[0]['raw']);
        }
        $match = false;
        $others = array();
        foreach ($on as $a) {
            if (!$match && $a['date'] == $next['date'] && $a['time'] == $next['time']) {
                $match = true;
            } else {
                $others[] = trim($a['date'] . ' ' . $a['time']);
            }
        }
        if ($match && empty($others)) {
            return array('ok' => true, 'detail' => $next['time'] . ' OK');
        }
        if ($match) {
            return array('ok' => false, 'detail' => $next['time'] . ' présente mais ' . count($others) . ' autre(s) alarme(s) active(s) : ' . implode(', ', $others));
        }
        return array('ok' => false, 'detail' => empty($others) ? 'aucune alarme' : 'lu ' . implode(', ', $others) . ' au lieu de ' . $next['datetime']);
    }

    /* ---------- Rémi (via le plugin remi) ---------- */

    private function remiParams() {
        $name = trim($this->getConfiguration('remiAlarmName', '')) ?: 'Jeedom';
        $mondayFirst = $this->getConfiguration('remiSundayFirst', 0) ? false : true;
        return array($this->getConfiguration('remiEqId', ''), $name, $mondayFirst);
    }

    /** $next est déjà la consigne Rémi (null = désactiver). */
    private function pushRemi($next) {
        list($eqId, $name, $mondayFirst) = $this->remiParams();
        $isoDay = $next ? (int) (new DateTime($next['date']))->format('N') : 1;
        reveilRemi::program($eqId, $name, $next ? $next['time'] : null, $isoDay, $mondayFirst);
    }

    private function verifyRemi($next) {
        $remiNext = $this->remiTarget($next);
        list($eqId, $name, $mondayFirst) = $this->remiParams();
        $isoDay = $remiNext ? (int) (new DateTime($remiNext['date']))->format('N') : 1;
        $r = reveilRemi::verify($eqId, $name, $remiNext ? $remiNext['time'] : null, $isoDay, $mondayFirst);
        if ($r['ok'] && $remiNext === null) {
            $r['detail'] = $this->remiWaitReason();
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
        $this->setExceptions(array($date => $value));
    }

    /** Plusieurs exceptions en un seul enregistrement / une seule reprogrammation. ['AAAA-MM-JJ' => valeur] */
    public function setExceptions($map, $sync = true) {
        $planning = $this->getPlanning();
        foreach ($map as $date => $value) {
            $planning = $this->applyException($planning, $date, $value);
        }
        // purge des exceptions passées
        foreach (array_keys($planning['exceptions']) as $d) {
            if ($d < date('Y-m-d', strtotime('-7 days'))) {
                unset($planning['exceptions'][$d]);
            }
        }
        $this->setConfiguration('planning', $planning);
        $this->save(true);
        if ($sync) {
            $this->syncTargets();
        }
        $this->refreshWidget();
    }

    /**
     * Heure forcée du Rémi pour un jour (crayon « Rémi » du widget) :
     * "HH:MM", "off" (pas de réveil Rémi) ou "" (retour : suit le planning).
     */
    public function setRemiException($date, $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Date invalide : ' . $date);
        }
        $value = mb_strtolower(trim((string) $value));
        $planning = $this->getPlanning();
        if (!isset($planning['remiExceptions']) || !is_array($planning['remiExceptions'])) {
            $planning['remiExceptions'] = array();
        }
        if ($value === '') {
            unset($planning['remiExceptions'][$date]);
        } elseif ($value === 'off') {
            $planning['remiExceptions'][$date] = 'off';
        } elseif (preg_match('/^(\d{1,2})[:h](\d{2})$/', $value, $m)) {
            $planning['remiExceptions'][$date] = sprintf('%02d:%02d', $m[1], $m[2]);
        } else {
            throw new Exception('Valeur invalide : ' . $value . ' (HH:MM, off ou vide)');
        }
        foreach (array_keys($planning['remiExceptions']) as $d) {
            if ($d < date('Y-m-d', strtotime('-7 days'))) {
                unset($planning['remiExceptions'][$d]);
            }
        }
        $this->setConfiguration('planning', $planning);
        $this->save(true);
        log::add('reveil', 'info', $this->getHumanName() . ' Rémi ' . $date . ' : ' . ($value === '' ? 'suit le planning' : $value));
        $this->syncTargets();
        $this->refreshWidget();
    }

    /**
     * Réglage d'une semaine entière depuis le widget (lundi → vendredi, comme les profils).
     * $week : semaine ISO "2026-W46" ; $value :
     *   "profile:<nom>" profil de semaine, "time:HH:MM" même heure lun-ven,
     *   "off" pas de réveil lun-ven, "normal" retour à la règle habituelle.
     *   Préfixe "remi:" (ex. "remi:time:07:30") : seulement l'heure du Rémi.
     * Les jours déjà passés, les profils du jour (rugby…) et, pour une heure, les congés et fériés ne sont pas touchés.
     */
    public function setWeek($week, $value) {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', trim($week), $m)) {
            throw new Exception('Semaine invalide : ' . $week . ' (format AAAA-Wss)');
        }
        $monday = new DateTime();
        $monday->setISODate((int) $m[1], (int) $m[2], 1);
        $key = trim($week);
        $value = trim((string) $value);
        $planning = $this->getPlanning();
        $today = date('Y-m-d');
        if (strpos($value, 'remi:') === 0) {
            // semaine du Rémi seulement : heures forcées du lundi au vendredi
            $v = substr($value, 5);
            if ($v === 'normal') {
                $rv = '';
            } elseif ($v === 'off') {
                $rv = 'off';
            } elseif (strpos($v, 'time:') === 0 && preg_match('/^(\d{1,2}):(\d{2})$/', substr($v, 5), $tm)) {
                $rv = sprintf('%02d:%02d', $tm[1], $tm[2]);
            } else {
                throw new Exception('Valeur invalide : ' . $value);
            }
            if (!isset($planning['remiExceptions']) || !is_array($planning['remiExceptions'])) {
                $planning['remiExceptions'] = array();
            }
            $d = clone $monday;
            for ($i = 0; $i < 5; $i++) {
                $date = $d->format('Y-m-d');
                $d->modify('+1 day');
                if ($date < $today) {
                    continue;
                }
                if ($rv === '') {
                    unset($planning['remiExceptions'][$date]);
                } elseif ($rv === 'off' || reveilCalendar::isFerie($date) === false) {
                    $planning['remiExceptions'][$date] = $rv; // une heure ne s'impose pas sur un férié
                }
            }
            $this->setConfiguration('planning', $planning);
            $this->save(true);
            log::add('reveil', 'info', $this->getHumanName() . ' semaine ' . $key . ' Rémi : ' . $v);
            $this->syncTargets();
            $this->refreshWidget();
            return;
        }
        $dayValue = null; // valeur des exceptions lun-ven ('' = supprimer)
        if ($value === 'normal') {
            unset($planning['weeks'][$key]);
            $dayValue = '';
        } elseif (strpos($value, 'profile:') === 0) {
            $name = substr($value, 8);
            if (!isset($planning['profiles'][$name])) {
                throw new Exception('Profil inconnu : ' . $name);
            }
            $planning['weeks'][$key] = $name;
            $dayValue = '';
        } elseif (strpos($value, 'time:') === 0 && preg_match('/^\d{1,2}:\d{2}$/', substr($value, 5))) {
            $dayValue = substr($value, 5);
        } elseif ($value === 'off') {
            $dayValue = 'off';
        } else {
            throw new Exception('Valeur invalide : ' . $value);
        }
        $d = clone $monday;
        for ($i = 0; $i < 5; $i++) {
            $date = $d->format('Y-m-d');
            $d->modify('+1 day');
            if ($date < $today) {
                continue;
            }
            $cur = isset($planning['exceptions'][$date]) ? $planning['exceptions'][$date] : '';
            if ($cur !== '' && ($cur[0] == '@' || $cur == 'absent')) {
                continue; // profil du jour ou absence : gardés
            }
            if ($dayValue !== '' && $dayValue !== 'off' && (reveilCalendar::isPersonalLeave($date) !== false || reveilCalendar::isFerie($date) !== false)) {
                continue; // une heure de semaine ne s'impose pas sur un congé ou un férié
            }
            $planning = $this->applyException($planning, $date, $dayValue);
        }
        $this->setConfiguration('planning', $planning);
        $this->save(true);
        log::add('reveil', 'info', $this->getHumanName() . ' semaine ' . $key . ' : ' . $value);
        $this->syncTargets();
        $this->refreshWidget();
    }

    private function applyException($planning, $date, $value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Date invalide : ' . $date);
        }
        $value = trim($value);
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
        return $planning;
    }

    /** Statut synchro en clair pour le widget : une ligne colorée par appareil (rendu côté serveur, sans JS). */
    public static function statusHtml($txt) {
        $txt = trim($txt);
        if ($txt === '') {
            return '';
        }
        $esc = function ($t) { return htmlspecialchars($t, ENT_QUOTES); };
        $note = '';
        if (($cut = mb_strpos($txt, ' — ')) !== false) {
            $note = mb_substr($txt, $cut + 3);
            $txt = mb_substr($txt, 0, $cut);
        }
        $global = '';
        if (preg_match('/^(OK|Programmé|KO|ÉCHEC|Écart, nouvel essai \d+)\s*:\s*(.*)$/u', $txt, $m)) {
            $global = $m[1];
            $txt = $m[2];
        }
        $html = ($global !== '' && !in_array($global, array('OK', 'Programmé'))) ? '<div class="rv-st-ko"><b>' . $esc($global) . '</b></div>' : '';
        foreach (explode(' | ', $txt) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $cls = preg_match('/^(KO|ÉCHEC|Écart)/u', $global) ? 'rv-st-ko' : 'rv-st-ok';
            if (preg_match('/\sOK$/u', $part)) {
                $cls = 'rv-st-ok';
            }
            if (preg_match('/dérogation/iu', $part)) {
                $cls = 'rv-st-derog';
            } elseif (preg_match('/attente|programmé/iu', $part)) {
                $cls = 'rv-st-wait';
            } elseif (preg_match('/ÉCHEC|ECHEC|KO|erreur|introuvable|au lieu|toujours actif|absente|aucune alarme/u', $part)) {
                $cls = 'rv-st-ko';
            }
            $html .= '<div class="' . $cls . '" title="' . $esc($part) . '">' . $esc($part) . '</div>';
        }
        if ($note !== '') {
            $html .= '<div class="rv-st-note">' . $esc($note) . '</div>';
        }
        return $html;
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
        if (!is_object($this->getCmd(null, 'toggle_garde')) || !is_object($this->getCmd(null, 'toggle_conges')) || !is_object($this->getCmd(null, 'toggle_dayprofile')) || !is_object($this->getCmd(null, 'alexa_status')) || !is_object($this->getCmd(null, 'verify')) || !is_object($this->getCmd(null, 'set_week')) || !is_object($this->getCmd(null, 'set_remi'))) {
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
        $replace['#sync_status_id#'] = is_object($status) ? $status->getId() : '';
        $replace['#status_html#'] = self::statusHtml(is_object($status) ? (string) $status->execCmd() : '');
        $replace['#sync_ok_id#'] = is_object($ok) ? $ok->getId() : '';
        $verify = $this->getCmd(null, 'verify');
        $replace['#verify_id#'] = is_object($verify) ? $verify->getId() : '';
        $resync = $this->getCmd(null, 'refresh');
        $replace['#resync_id#'] = is_object($resync) ? $resync->getId() : '';
        $replace['#next_wake_id#'] = is_object($next) ? $next->getId() : '';

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
        $setRemi = $this->getCmd(null, 'set_remi');
        $replace['#set_remi_id#'] = is_object($setRemi) ? $setRemi->getId() : '';
        $replace['#remi_pen_style#'] = $this->getConfiguration('remiEnable', 0) ? '' : 'display:none;';
        $setWeek = $this->getCmd(null, 'set_week');
        $replace['#set_week_id#'] = is_object($setWeek) ? $setWeek->getId() : '';
        $wbtns = '';
        foreach (array_keys($this->getPlanning()['profiles']) as $name) {
            $n = htmlspecialchars($name, ENT_QUOTES);
            $wbtns .= '<a class="btn btn-xs btn-default rv-wpop-prof" data-profile="' . $n . '">' . $n . '</a>';
        }
        $replace['#week_profiles#'] = $wbtns;
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
        $tpl = getTemplate('core', 'dashboard', 'reveil', 'reveil'); // un seul template (dashboard), aussi utilisé en mobile
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
                $wkey = $d->format('o-\WW');
                $forced = isset($planning['weeks'][$wkey]) ? $planning['weeks'][$wkey] : '';
                $wtitle = ($custody === null ? '' : ($custody ? 'Semaine avec enfant' : 'Semaine sans enfant'))
                    . ($forced !== '' ? ' — profil ' . $forced : '') . ' — clic pour régler la semaine';
                $fri = (clone $d)->modify('+4 days');
                $html .= '<div class="rv-week' . ($custody ? ' rv-custody' : '') . ($forced !== '' ? ' rv-week-forced' : '') . '" data-week="' . $wkey . '"'
                    . ' data-from="' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n')] . '" data-to="' . (int) $fri->format('j') . ' ' . $months[(int) $fri->format('n')] . '"'
                    . ' data-profile="' . htmlspecialchars($forced, ENT_QUOTES) . '" title="' . htmlspecialchars($wtitle, ENT_QUOTES) . '">'
                    . 'S' . $d->format('W') . ($custody ? '<i class="fas fa-child"></i>' : '') . ($forced !== '' ? '<span class="rv-wprof">' . htmlspecialchars(mb_substr($forced, 0, 5), ENT_QUOTES) . '</span>' : '') . '</div>';
            }
            if ($date < $today) {
                // planning glissant : les jours passés ne sont plus affichés
                $html .= '<div class="rv-day rv-gone"></div>';
                $d->modify('+1 day');
                continue;
            }
            $day = self::computeDay($planning, $date);
            $rx = (isset($planning['remiExceptions']) && is_array($planning['remiExceptions'])) ? $planning['remiExceptions'] : array();
            $remiLine = '';
            if ($this->getConfiguration('remiEnable', 0)) {
                $rt = $this->remiDayTime($day, $rx);
                if (isset($rx[$date])) {
                    // heure forcée du Rémi : toujours affichée
                    $remiLine = '<span class="rv-rtime rv-rforced" title="Rémi : heure forcée">R ' . ($rt ?: '—') . '</span>';
                } elseif ($rt !== null && $rt !== $day['time']) {
                    $remiLine = '<span class="rv-rtime">R ' . $rt . '</span>';
                }
            }
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
                . $remiLine
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
        $this->setConfiguration('remiOnlyChild', 1);
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
            'alexa_status' => array('Statut Alexa', 'info', 'string'),
            'remi_status' => array('Statut Rémi', 'info', 'string'),
            'last_check' => array('Dernière vérification', 'info', 'string'),
            'refresh' => array('Resynchroniser', 'action', 'other'),
            'verify' => array('Vérifier', 'action', 'other'),
            'skip_next' => array('Pas de prochain réveil', 'action', 'other'),
            'set_exception' => array('Exception', 'action', 'message'),
            'set_week' => array('Régler une semaine', 'action', 'message'),
            'set_remi' => array('Heure Rémi', 'action', 'message'),
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
                if ($logical == 'set_remi') {
                    $cmd->setDisplay('title_placeholder', 'Date AAAA-MM-JJ');
                    $cmd->setDisplay('message_placeholder', 'HH:MM, off ou vide');
                }
                if ($logical == 'set_week') {
                    $cmd->setDisplay('title_placeholder', 'Semaine AAAA-Wss');
                    $cmd->setDisplay('message_placeholder', 'profile:Nom, time:HH:MM, off, normal (préfixe remi: pour le Rémi)');
                }
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
            case 'verify':
                $eq->verifyNow();
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
            case 'set_remi':
                $eq->setRemiException(trim($_options['title']), isset($_options['message']) ? $_options['message'] : '');
                break;
            case 'set_week':
                $eq->setWeek(trim($_options['title']), isset($_options['message']) ? $_options['message'] : '');
                break;
        }
    }
}

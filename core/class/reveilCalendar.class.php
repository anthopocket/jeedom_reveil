<?php
/**
 * Calendriers : jours fériés (calculés localement), vacances scolaires
 * (data.education.gouv.fr, mises en cache) et congés personnels (config).
 * Toutes les dates manipulées sont des chaînes 'Y-m-d' en heure locale.
 */
class reveilCalendar {

    /**
     * config::byKey décode déjà le JSON stocké : la valeur peut être un tableau ou une chaîne.
     */
    public static function configArray($key, $default = array()) {
        $v = config::byKey($key, 'reveil', $default);
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        return is_array($v) ? $v : $default;
    }

    const API_URL = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records';

    /* ---------- Jours fériés ---------- */

    /** Dimanche de Pâques (algorithme de Butcher, pas besoin de l'extension calendar). */
    public static function easter($year) {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    public static function feries($year) {
        $easter = self::easter($year);
        $rel = function ($days) use ($easter) {
            $d = clone $easter;
            return $d->modify("+$days day")->format('Y-m-d');
        };
        return array(
            "$year-01-01" => 'Jour de l\'an',
            $rel(1) => 'Lundi de Pâques',
            "$year-05-01" => 'Fête du travail',
            "$year-05-08" => 'Victoire 1945',
            $rel(39) => 'Ascension',
            $rel(50) => 'Lundi de Pentecôte',
            "$year-07-14" => 'Fête nationale',
            "$year-08-15" => 'Assomption',
            "$year-11-01" => 'Toussaint',
            "$year-11-11" => 'Armistice',
            "$year-12-25" => 'Noël',
        );
    }

    /** @return string|false libellé du férié */
    public static function isFerie($date) {
        $feries = self::feries((int) substr($date, 0, 4));
        return isset($feries[$date]) ? $feries[$date] : false;
    }

    /* ---------- Vacances scolaires ---------- */

    /** Convertit un horodatage de l'API (UTC) en date locale Y-m-d. */
    private static function toLocalDate($value) {
        $d = new DateTime($value);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d->format('Y-m-d');
    }

    /**
     * Récupère les vacances de l'académie configurée et les met en cache.
     * Période stockée en [start, end[ : start = 1er jour de vacances, end = jour de reprise.
     */
    public static function refreshSchoolHolidays() {
        $academie = trim(config::byKey('academie', 'reveil', 'Toulouse'));
        $where = sprintf('location="%s" and end_date>="%s"', $academie, date('Y-m-d', strtotime('-30 days')));
        $url = self::API_URL . '?' . http_build_query(array('where' => $where, 'limit' => 100, 'order_by' => 'start_date'));

        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        log::add('reveil', 'debug', 'Vacances scolaires HTTP ' . $code . ' : ' . substr((string) $raw, 0, 2000));
        if ($raw === false || $code != 200) {
            throw new Exception('API calendrier scolaire injoignable (HTTP ' . $code . ')');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['results'])) {
            throw new Exception('Réponse API calendrier scolaire inattendue');
        }

        $periods = array();
        foreach ($data['results'] as $rec) {
            // Les périodes "Enseignants" (pré-rentrée) ne concernent pas les élèves
            if (isset($rec['population']) && stripos($rec['population'], 'enseignant') !== false) {
                continue;
            }
            if (empty($rec['start_date'])) {
                continue;
            }
            $start = self::toLocalDate($rec['start_date']);
            // Pas de date de fin = "Vacances d'été" sans reprise connue : on borne au 1er septembre
            $end = empty($rec['end_date']) ? substr($start, 0, 4) . '-09-01' : self::toLocalDate($rec['end_date']);
            $key = $start . '|' . $end;
            $periods[$key] = array('start' => $start, 'end' => $end, 'label' => isset($rec['description']) ? $rec['description'] : 'Vacances');
        }
        $periods = array_values($periods);
        config::save('schoolHolidays', $periods, 'reveil');
        config::save('schoolHolidaysUpdate', date('Y-m-d H:i:s'), 'reveil');
        log::add('reveil', 'info', count($periods) . ' périodes de vacances scolaires en cache (' . $academie . ')');
        return $periods;
    }

    public static function schoolHolidays() {
        return self::configArray('schoolHolidays');
    }

    /** @return string|false libellé des vacances */
    public static function isSchoolHoliday($date) {
        foreach (self::schoolHolidays() as $p) {
            if ($date >= $p['start'] && $date < $p['end']) {
                return $p['label'];
            }
        }
        return false;
    }

    /* ---------- Congés personnels ---------- */

    /**
     * Format : une période par ligne "AAAA-MM-JJ [AAAA-MM-JJ] [libellé]" (bornes incluses).
     */
    public static function personalLeaves() {
        $periods = array();
        foreach (preg_split('/\r?\n/', (string) config::byKey('conges', 'reveil', '')) as $line) {
            if (!preg_match('/^\s*(\d{4}-\d{2}-\d{2})(?:\s+(\d{4}-\d{2}-\d{2}))?\s*(.*)$/', $line, $m)) {
                continue;
            }
            $end = !empty($m[2]) ? $m[2] : $m[1];
            $periods[] = array('start' => $m[1], 'end' => $end, 'label' => trim($m[3]) != '' ? trim($m[3]) : 'Congés');
        }
        return $periods;
    }

    /** @return string|false libellé des congés */
    public static function isPersonalLeave($date) {
        foreach (self::personalLeaves() as $p) {
            if ($date >= $p['start'] && $date <= $p['end']) {
                return $p['label'];
            }
        }
        return false;
    }

    /**
     * Bascule un jour en congé / pas congé (clic sur le widget).
     * - jour non couvert : ajoute la ligne "AAAA-MM-JJ Congé"
     * - jour couvert par une ligne d'un seul jour : supprime la ligne
     * - jour au milieu d'une période : découpe la période autour de ce jour
     * @return bool true si le jour est désormais en congé
     */
    public static function togglePersonalLeave($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Date invalide : ' . $date);
        }
        $lines = preg_split('/\r?\n/', (string) config::byKey('conges', 'reveil', ''));
        $out = array();
        $found = false;
        foreach ($lines as $line) {
            if (!$found && preg_match('/^\s*(\d{4}-\d{2}-\d{2})(?:\s+(\d{4}-\d{2}-\d{2}))?\s*(.*)$/', $line, $m)) {
                $start = $m[1];
                $end = !empty($m[2]) ? $m[2] : $m[1];
                $label = trim($m[3]);
                if ($date >= $start && $date <= $end) {
                    $found = true;
                    $before = date('Y-m-d', strtotime($date . ' -1 day'));
                    $after = date('Y-m-d', strtotime($date . ' +1 day'));
                    if ($start <= $before) {
                        $out[] = trim($start . ($start != $before ? ' ' . $before : '') . ' ' . $label);
                    }
                    if ($after <= $end) {
                        $out[] = trim($after . ($after != $end ? ' ' . $end : '') . ' ' . $label);
                    }
                    continue;
                }
            }
            if (trim($line) != '') {
                $out[] = $line;
            }
        }
        if (!$found) {
            $out[] = $date . ' Congé';
        }
        // purge des lignes entièrement passées depuis plus d'un mois, tri chronologique
        $limit = date('Y-m-d', strtotime('-31 days'));
        $out = array_values(array_filter($out, function ($l) use ($limit) {
            return !preg_match('/^\s*(\d{4}-\d{2}-\d{2})(?:\s+(\d{4}-\d{2}-\d{2}))?/', $l, $m) || (!empty($m[2]) ? $m[2] : $m[1]) >= $limit;
        }));
        sort($out);
        config::save('conges', implode("\n", $out), 'reveil');
        return !$found;
    }

    /* ---------- Garde alternée ---------- */

    /**
     * La semaine de $date est-elle une semaine avec l'enfant ?
     * Parité du numéro de semaine ISO : gardeWeeks = 'pair' | 'impair' ('' = désactivé).
     * @return bool|null null si la garde alternée n'est pas configurée
     */
    public static function custodyWeek($date) {
        $mode = config::byKey('gardeWeeks', 'reveil', '');
        if ($mode != 'pair' && $mode != 'impair') {
            return null;
        }
        $d = new DateTime($date);
        $even = ((int) $d->format('W')) % 2 == 0;
        return $even == ($mode == 'pair');
    }

    /** Jours forcés : ['AAAA-MM-JJ' => 1 (avec enfant) | 0 (sans)] */
    public static function custodyOverrides() {
        return self::configArray('gardeJours');
    }

    /**
     * Garde d'un jour : jour forcé, sinon parité de la semaine.
     * @return bool|null null si la garde n'est pas configurée et le jour non forcé
     */
    public static function custodyDay($date) {
        $o = self::custodyOverrides();
        if (isset($o[$date])) {
            return (bool) $o[$date];
        }
        return self::custodyWeek($date);
    }

    /**
     * Inverse la garde d'un jour (clic "Enfant" sur le widget).
     * Si le nouvel état correspond à la règle pair/impair, le forçage est retiré.
     * @return bool nouvel état (true = avec enfant)
     */
    public static function toggleCustodyDay($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Date invalide : ' . $date);
        }
        $o = self::custodyOverrides();
        $new = !self::custodyDay($date);
        $rule = (bool) self::custodyWeek($date);
        if ($new === $rule) {
            unset($o[$date]);
        } else {
            $o[$date] = $new ? 1 : 0;
        }
        $limit = date('Y-m-d', strtotime('-31 days'));
        foreach (array_keys($o) as $d) {
            if ($d < $limit) {
                unset($o[$d]);
            }
        }
        ksort($o);
        config::save('gardeJours', empty($o) ? '' : $o, 'reveil');
        return $new;
    }
}

<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    // les classes annexes du plugin ne sont pas chargées par l'autoload de Jeedom
    require_once dirname(__FILE__) . '/../class/reveil.class.php';
    include_file('core', 'authentification', 'php');
    // utilisateurs connectés (non-admin compris) : édition du planning depuis le widget
    if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    $action = init('action');

    if ($action == 'savePlanning') {
        $eq = reveil::byId(init('id'));
        if (!is_object($eq)) {
            throw new Exception('Réveil introuvable');
        }
        if (!isConnect('admin') && !$eq->hasRight('w')) {
            throw new Exception(__('Vous n\'avez pas le droit de modifier ce réveil', __FILE__));
        }
        $planning = json_decode(init('planning'), true);
        if (!is_array($planning)) {
            throw new Exception('Planning invalide');
        }
        $eq->setConfiguration('planning', $planning);
        $eq->save();
        // reprogramme seulement si le prochain réveil change (évite de recréer l'alarme Alexa à l'identique)
        $eq->syncTargets();
        $eq->refreshWidget();
        ajax::success();
    }

    if ($action == 'preview') {
        // Aperçu calculé sur le planning en cours d'édition (pas encore sauvegardé)
        $planning = json_decode(init('planning'), true);
        if (!is_array($planning)) {
            throw new Exception('Planning invalide');
        }
        $tmp = new reveil();
        $tmp->setConfiguration('planning', $planning);
        ajax::success(reveil::preview($tmp->getPlanning(), (int) init('days', 28)));
    }

    if ($action == 'parseQuick') {
        ajax::success(reveil::parseQuick(init('text')));
    }

    if (!isConnect('admin') && !in_array($action, array('preview', 'parseQuick'))) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    if ($action == 'claimRemiAlarm') {
        ajax::success(reveilRemi::claim(init('eqId'), init('objectId')));
    }

    if ($action == 'listRemiAlarms') {
        ajax::success(reveilRemi::alarmSummary(reveilRemi::equipment(init('eqId'))));
    }

    if ($action == 'syncNow') {
        $eq = reveil::byId(init('id'));
        if (!is_object($eq)) {
            throw new Exception('Équipement introuvable');
        }
        $eq->syncTargets(true);
        ajax::success();
    }

    if ($action == 'verifyNow') {
        $eq = reveil::byId(init('id'));
        if (!is_object($eq)) {
            throw new Exception('Équipement introuvable');
        }
        $eq->verifyNow();
        $status = $eq->getCmd(null, 'sync_status');
        ajax::success(is_object($status) ? $status->execCmd() : '');
    }

    if ($action == 'refreshHolidays') {
        ajax::success(reveilCalendar::refreshSchoolHolidays());
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . $action);
} catch (Throwable $e) {
    log::add('reveil', 'error', 'ajax ' . init('action') . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    ajax::error($e->getMessage(), $e->getCode());
}
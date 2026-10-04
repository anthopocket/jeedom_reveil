<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/reveil.class.php';

function reveil_install() {
    reveil_update();
}

function reveil_update() {
    if (config::byKey('academie', 'reveil', '') == '') {
        config::save('academie', 'Toulouse', 'reveil');
    }
    foreach (eqLogic::byType('reveil') as $eqLogic) {
        // équipements créés avant cette version : rendus visibles une seule fois
        if (!$eqLogic->getConfiguration('visibilityInit', 0)) {
            $eqLogic->setIsVisible(1);
            $eqLogic->setIsEnable(1);
            $eqLogic->setConfiguration('visibilityInit', 1);
        }
        $eqLogic->save(); // (re)crée les commandes manquantes
    }
    try {
        reveilCalendar::refreshSchoolHolidays();
    } catch (Exception $e) {
        log::add('reveil', 'warning', 'Vacances scolaires non récupérées : ' . $e->getMessage());
    }
}

function reveil_remove() {
}

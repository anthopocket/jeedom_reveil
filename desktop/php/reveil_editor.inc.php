<?php
/**
 * Éditeur de planning partagé : page de l'équipement et fenêtre ouverte depuis le widget.
 * Les deux blocs sont affichés par reveil_editor_profiles() et reveil_editor_calendar().
 */
function reveil_editor_profiles() {
?>
        <div class="col-lg-7">
          <legend>{{Profils de semaine}}
            <a class="btn btn-xs btn-success pull-right" id="bt_reveilAddProfile"><i class="fas fa-plus"></i> {{Profil}}</a>
          </legend>
          <table class="table table-condensed" id="table_reveilProfiles">
            <thead><tr><th>{{Profil}}</th><th>Lun</th><th>Mar</th><th>Mer</th><th>Jeu</th><th>Ven</th><th>Sam</th><th>Dim</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
          <legend>{{Profils du jour}} <sup><i class="fas fa-question-circle tooltips" title="{{Un nom et une heure (ex. Rugby 08:00), à poser sur des dates avec le crayon correspondant du widget ou depuis l'onglet Calendrier. Prioritaire sur les congés et la garde.}}"></i></sup>
            <a class="btn btn-xs btn-success pull-right" id="bt_reveilAddDayProfile"><i class="fas fa-plus"></i> {{Profil du jour}}</a>
          </legend>
          <table class="table table-condensed" id="table_reveilDayProfiles" style="max-width:420px;">
            <thead><tr><th>{{Nom}}</th><th>{{Heure}}</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
          <div class="form-group">
            <label>{{Saisie rapide dans le profil}} <select id="sel_reveilQuickProfile" class="form-control input-sm" style="display:inline-block;width:auto;"></select></label>
            <div class="input-group">
              <input class="form-control" id="in_reveilQuick" placeholder="lun-ven 6:45, mer 7:30, sam-dim off"/>
              <span class="input-group-btn"><a class="btn btn-primary" id="bt_reveilQuick">{{Appliquer}}</a></span>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <legend>{{Règles}}</legend>
          <form class="form-horizontal">
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Profil par défaut}}</label>
              <div class="col-sm-7"><select class="form-control reveilProfileSelect" data-field="defaultProfile" data-empty="{{(aucun)}}"></select></div>
            </div>
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Pendant mes congés}}</label>
              <div class="col-sm-7"><select class="form-control reveilRuleSelect" data-rule="conges"></select></div>
            </div>
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Jours fériés}}</label>
              <div class="col-sm-7"><select class="form-control reveilRuleSelect" data-rule="ferie"></select></div>
            </div>
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Vacances scolaires}}</label>
              <div class="col-sm-7"><select class="form-control reveilRuleSelect" data-rule="vacances"></select></div>
            </div>
            <legend>{{Garde alternée}} <sup><i class="fas fa-question-circle tooltips" title="{{Semaines paires/impaires réglées dans la configuration du plugin}}"></i></sup></legend>
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Semaine avec mon enfant}}</label>
              <div class="col-sm-7"><select class="form-control reveilProfileSelect" data-field="garde_avec" data-empty="{{(profil par défaut)}}"></select></div>
            </div>
            <div class="form-group">
              <label class="col-sm-5 control-label">{{Semaine sans}}</label>
              <div class="col-sm-7"><select class="form-control reveilProfileSelect" data-field="garde_sans" data-empty="{{(profil par défaut)}}"></select></div>
            </div>
          </form>
        </div>
<?php
}

function reveil_editor_calendar() {
?>
        <div class="col-lg-5">
          <legend>{{Affecter un profil à des semaines}}</legend>
          <div class="form-inline">
            <input type="week" class="form-control input-sm" id="in_reveilWeekFrom"/> →
            <input type="week" class="form-control input-sm" id="in_reveilWeekTo"/>
            <select class="form-control input-sm reveilProfileSelect" id="sel_reveilWeekProfile" data-empty="{{(automatique)}}"></select>
            <a class="btn btn-sm btn-primary" id="bt_reveilAssignWeeks">{{Appliquer}}</a>
          </div>
          <table class="table table-condensed" id="table_reveilWeeks"><tbody></tbody></table>

          <legend>{{Ajouter une exception}}</legend>
          <div class="form-inline">
            <input type="date" class="form-control input-sm" id="in_reveilExcDate"/>
            <input type="time" class="form-control input-sm" id="in_reveilExcTime"/>
            <label class="checkbox-inline"><input type="checkbox" id="cb_reveilExcOff"/>{{Pas de réveil}}</label>
            <select class="form-control input-sm" id="sel_reveilExcDay"></select>
            <a class="btn btn-sm btn-primary" id="bt_reveilAddExc">{{Ajouter}}</a>
          </div>
          <table class="table table-condensed" id="table_reveilExceptions"><tbody></tbody></table>
        </div>
        <div class="col-lg-7">
          <legend>{{Aperçu des 4 prochaines semaines}} <small>{{(planning en cours d'édition)}}</small></legend>
          <table class="table table-condensed table-bordered" id="table_reveilPreview">
            <thead><tr><th>{{Date}}</th><th>{{Réveil}}</th><th>{{Origine}}</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
<?php
}

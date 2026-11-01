<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<?php require_once dirname(__FILE__) . '/../core/class/reveil.class.php'; ?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-info-circle"></i> {{Plugin}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Version}}</label>
      <div class="col-md-8" style="padding-top:7px;">
        <span class="label label-info"><?php echo htmlspecialchars(reveil::pluginVersion()); ?></span>
        &nbsp;<a href="plugins/reveil/docs/fr_FR/index.md" target="_blank"><i class="fas fa-book"></i> {{Documentation}}</a>
        &nbsp;<a href="plugins/reveil/docs/fr_FR/changelog.md" target="_blank"><i class="fas fa-list"></i> {{Journal des modifications}}</a>
      </div>
    </div>

    <legend><i class="fas fa-calendar-alt"></i> {{Calendriers}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Académie (vacances scolaires)}}</label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="academie" placeholder="Toulouse"/>
      </div>
      <div class="col-md-4">
        <a class="btn btn-default" id="bt_reveilRefreshHolidays"><i class="fas fa-sync"></i> {{Rafraîchir les vacances}}</a>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mes congés}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Une période par ligne : AAAA-MM-JJ AAAA-MM-JJ libellé (ou une seule date)}}"></i></sup>
      </label>
      <div class="col-md-6">
        <textarea class="configKey form-control" data-l1key="conges" rows="6" placeholder="2026-10-26 2026-10-30 Toussaint&#10;2026-12-24 2027-01-02 Noël&#10;2026-11-12 RTT"></textarea>
      </div>
    </div>

    <legend><i class="fas fa-child"></i> {{Garde alternée}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Semaines avec mon enfant}}</label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="gardeWeeks">
          <option value="">{{Désactivé}}</option>
          <option value="pair">{{Semaines paires}}</option>
          <option value="impair">{{Semaines impaires}}</option>
        </select>
      </div>
    </div>

    <legend><i class="fas fa-clock"></i> {{Programmation}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Heure de mise en place (la veille)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Un réveil n'est programmé sur l'Alexa et le Rémi qu'à partir de la veille, à cette heure. Le réveil du jour reste en place jusqu'à cette même heure (et au moins 1 h après sa sonnerie) : une alarme n'est jamais modifiée juste avant ou pendant qu'elle sonne.}}"></i></sup>
      </label>
      <div class="col-md-2"><input type="time" class="configKey form-control" data-l1key="switchTime" placeholder="12:00"/></div>
    </div>

    <legend><i class="fas fa-bell"></i> {{Alerte en cas d'échec de vérification}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Commande de notification (message)}}</label>
      <div class="col-md-4">
        <div class="input-group">
          <input class="configKey form-control" data-l1key="alertCmd"/>
          <span class="input-group-btn">
            <a class="btn btn-default" id="bt_reveilSelectAlertCmd"><i class="fas fa-list-alt"></i></a>
          </span>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Nombre d'essais avant alerte}}</label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="maxRetry" placeholder="3"/></div>
    </div>
  </fieldset>
</form>
<script>
  $('#bt_reveilSelectAlertCmd').on('click', function () {
    jeedom.cmd.getSelectModal({cmd: {type: 'action', subType: 'message'}}, function (result) {
      $('.configKey[data-l1key=alertCmd]').value(result.human);
    });
  });
  $('#bt_reveilRefreshHolidays').on('click', function () {
    $.ajax({
      type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php',
      data: {action: 'refreshHolidays'}, dataType: 'json',
      success: function (data) {
        if (data.state != 'ok') {
          $.fn.showAlert({message: data.result, level: 'danger'});
          return;
        }
        $.fn.showAlert({message: data.result.length + ' {{périodes de vacances récupérées}}', level: 'success'});
      }
    });
  });
</script>

/* Plugin Réveils — page de configuration de l'équipement (l'éditeur est dans reveil_editor.js). */

/* ---------- Hooks Jeedom ---------- */

function printEqLogic(_eqLogic) {
  reveilEditorLoad(_eqLogic.configuration ? _eqLogic.configuration.planning : null);
  var conf = _eqLogic.configuration || {};
  reveilLoadRemiAlarms(conf.remiEqId || '', conf.remiAlarmName || '');
}

/* ---------- Rémi (JeeRemi) : liste des réveils ---------- */

var reveilRemiAlarms = [];

function reveilRemiLinked(name) {
  $('#in_reveilRemiAlarmName').val(name || '');
  $('#div_reveilRemiLinked').html(name
    ? '<span class="label label-success"><i class="fas fa-link"></i> ' + $('<div>').text(name).html() + '</span>'
    : '<span class="label label-warning">{{Aucun réveil rattaché}}</span>');
}

function reveilAlarmLabel(a) {
  return (a.name || '{{(sans nom)}}') + ' — ' + a.time + ' — ' + (a.enabled ? '{{actif}}' : '{{inactif}}') + (a.days ? ' — ' + a.days : '');
}

function reveilLoadRemiAlarms(eqId, selected) {
  var $sel = $('#sel_reveilRemiAlarm').empty();
  var $info = $('#div_reveilRemiAlarms').empty();
  reveilRemiLinked(selected);
  if (!eqId) { return; }
  $info.text('{{Lecture des réveils du Rémi…}}');
  $.ajax({
    type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
    data: {action: 'listRemiAlarms', eqId: eqId},
    success: function (data) {
      if (data.state != 'ok') { $info.html('<span style="color:#e53935">' + $('<div>').text(data.result).html() + '</span>'); return; }
      reveilRemiAlarms = data.result;
      $sel.append($('<option>').val('').text('{{Choisir un réveil…}}'));
      var found = false;
      data.result.forEach(function (a) {
        $sel.append($('<option>').val(a.objectId).text(reveilAlarmLabel(a)));
        if (selected && a.name && a.name.toLowerCase() == selected.toLowerCase()) { $sel.val(a.objectId); found = true; }
      });
      if (selected && !found) {
        $('#div_reveilRemiLinked').append(' <span class="label label-danger">{{introuvable sur le Rémi}}</span>');
      }
      $info.text(data.result.length ? '' : "{{Aucun réveil sur ce Rémi : créez-en un dans l'appli Rémi.}}");
    },
    error: function (r) { $info.text('{{Erreur de lecture}} : ' + (r.responseText || r.status)); }
  });
}

$('#sel_reveilRemiEq').on('change', function () {
  reveilLoadRemiAlarms($(this).val(), $('#in_reveilRemiAlarmName').val());
});
$('#bt_reveilRemiAlarms').on('click', function () {
  reveilLoadRemiAlarms($('#sel_reveilRemiEq').val(), $('#in_reveilRemiAlarmName').val());
});
$('#sel_reveilRemiAlarm').on('change', function () {
  var id = $(this).val();
  if (!id) { return; }
  var a = reveilRemiAlarms.filter(function (x) { return x.objectId == id; })[0];
  $('#div_reveilRemiLinked').html('<i class="fas fa-spinner fa-spin"></i>');
  $.ajax({
    type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
    data: {action: 'claimRemiAlarm', eqId: $('#sel_reveilRemiEq').val(), objectId: id},
    success: function (data) {
      if (data.state != 'ok') { $.fn.showAlert({message: data.result, level: 'danger'}); reveilRemiLinked($('#in_reveilRemiAlarmName').val()); return; }
      reveilRemiLinked(data.result);
      if (a && !a.name) {
        $.fn.showAlert({message: '{{Réveil}} ' + a.time + ' {{renommé}} "' + data.result + '" {{pour être piloté. Pensez à sauvegarder.}}', level: 'success'});
      }
      // la liste est rechargée pour refléter le nouveau nom (et le nouvel objectId)
      reveilLoadRemiAlarms($('#sel_reveilRemiEq').val(), data.result);
    }
  });
});

function saveEqLogic(_eqLogic) {
  if (!_eqLogic.configuration) { _eqLogic.configuration = {}; }
  reveilCollect();
  _eqLogic.configuration.planning = reveilPlanning;
  return _eqLogic;
}

$('.bt_reveilSelectCmd').on('click', function () {
  var key = $(this).data('key');
  var filter = {type: $(this).data('type')};
  if ($(this).data('subtype')) { filter.subType = $(this).data('subtype'); }
  jeedom.cmd.getSelectModal({cmd: filter}, function (result) {
    $('.eqLogicAttr[data-l2key=' + key + ']').value(result.human);
  });
});

$('#bt_reveilSyncNow').on('click', function () {
  $.ajax({
    type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
    data: {action: 'syncNow', id: $('.eqLogicAttr[data-l1key=id]').value()},
    success: function (data) {
      if (data.state != 'ok') { $.fn.showAlert({message: data.result, level: 'danger'}); return; }
      $.fn.showAlert({message: '{{Programmé. Vérification automatique dans ~2 min (voir commande Statut synchro).}}', level: 'success'});
    }
  });
});

/* ---------- Table des commandes (standard) ---------- */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) { var _cmd = {configuration: {}}; }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {}; }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
  tr += '<td><input class="cmdAttr form-control input-sm" data-l1key="name"></td>';
  tr += '<td><span class="cmdAttr" data-l1key="type"></span> / <span class="cmdAttr" data-l1key="subType"></span></td>';
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>';
  tr += '<td><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label>';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{Historiser}}</label></td>';
  tr += '<td>';
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
  }
  tr += '</td></tr>';
  $('#table_cmd tbody').append(tr);
  var $tr = $('#table_cmd tbody tr').last();
  $tr.setValues(_cmd, '.cmdAttr');
  jeedom.cmd.changeType($tr, init(_cmd.subType));
}

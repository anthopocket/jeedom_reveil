/* Plugin Réveils — éditeur de planning partagé (page de l'équipement + fenêtre du widget).
 * Le planning est édité dans l'objet `reveilPlanning`.
 */
var reveilPlanning = null;
var reveilPreviewTimer = null;
var REVEIL_DAYS = ['1', '2', '3', '4', '5', '6', '7'];

function reveilDefaultPlanning() {
  return {
    profiles: {},
    defaultProfile: '',
    weeks: {},
    garde: {avec: '', sans: ''},
    exceptions: {},
    dayProfiles: {},
    rules: {conges: 'off', ferie: 'off', vacances: 'ignore'}
  };
}

function reveilEditorLoad(p) {
  if (typeof p === 'string') {
    try { p = JSON.parse(p); } catch (e) { p = null; }
  }
  reveilPlanning = $.extend(true, reveilDefaultPlanning(), p || {});
  if (p && p.profiles) { reveilPlanning.profiles = p.profiles; } // pas de fusion avec le profil exemple
  // un objet PHP vide arrive en JS sous forme de tableau [] : on le remet en objet
  ['profiles', 'weeks', 'exceptions', 'garde', 'rules', 'dayProfiles'].forEach(function (k) {
    if (Array.isArray(reveilPlanning[k]) || typeof reveilPlanning[k] !== 'object' || reveilPlanning[k] === null) { reveilPlanning[k] = {}; }
  });
  if (Array.isArray(reveilPlanning.weeks)) { reveilPlanning.weeks = {}; }
  if (Array.isArray(reveilPlanning.exceptions)) { reveilPlanning.exceptions = {}; }

  reveilRenderAll();
}


/* ---------- Rendu ---------- */

function reveilProfileNames() {
  return Object.keys(reveilPlanning.profiles);
}

function reveilRenderDayProfiles() {
  var $tb = $('#table_reveilDayProfiles tbody').empty();
  $.each(reveilPlanning.dayProfiles, function (name, t) {
    $tb.append('<tr><td><input class="form-control input-sm reveilDayName" value="' + $('<div>').text(name).html() + '"/></td>'
      + '<td><input type="time" class="form-control input-sm reveilDayTime" value="' + (t || '') + '"/></td>'
      + '<td><a class="btn btn-xs btn-danger bt_reveilDelDayProfile"><i class="fas fa-trash"></i></a></td></tr>');
  });
  var html = '<option value="">{{ou profil du jour…}}</option>';
  $.each(reveilPlanning.dayProfiles, function (name, t) { html += '<option value="' + $('<div>').text(name).html() + '">' + $('<div>').text(name).html() + ' ' + (t || '') + '</option>'; });
  $('#sel_reveilExcDay').html(html);
}

function reveilRenderAll() {
  reveilRenderDayProfiles();
  reveilRenderProfiles();
  reveilRenderSelects();
  reveilRenderWeeks();
  reveilRenderExceptions();
  reveilSchedulePreview();
}

function reveilRenderProfiles() {
  var $tb = $('#table_reveilProfiles tbody').empty();
  $.each(reveilPlanning.profiles, function (name, days) {
    var tr = '<tr data-profile="' + name + '"><td><input class="form-control input-sm reveilProfileName" value="' + name + '"/></td>';
    REVEIL_DAYS.forEach(function (d) {
      tr += '<td><input type="time" class="form-control input-sm reveilProfileDay" data-day="' + d + '" value="' + (days[d] || '') + '"/></td>';
    });
    tr += '<td><a class="btn btn-xs btn-default bt_reveilCopyMonday" title="{{Copier lundi sur mardi-vendredi}}"><i class="fas fa-copy"></i></a> ';
    tr += '<a class="btn btn-xs btn-danger bt_reveilDelProfile"><i class="fas fa-trash"></i></a></td></tr>';
    $tb.append(tr);
  });
}

function reveilRenderSelects() {
  var names = reveilProfileNames();
  $('.reveilProfileSelect').each(function () {
    var $s = $(this);
    var empty = $s.data('empty');
    var html = empty ? '<option value="">' + empty + '</option>' : '';
    names.forEach(function (n) { html += '<option value="' + n + '">' + n + '</option>'; });
    $s.html(html);
  });
  $('#sel_reveilQuickProfile').html(names.map(function (n) { return '<option>' + n + '</option>'; }).join(''));
  $('.reveilRuleSelect').each(function () {
    var html = '<option value="ignore">{{Aucun effet}}</option><option value="off">{{Pas de réveil}}</option>';
    names.forEach(function (n) { html += '<option value="' + n + '">{{Profil}} ' + n + '</option>'; });
    $(this).html(html).val(reveilPlanning.rules[$(this).data('rule')] || 'ignore');
  });
  $('.reveilProfileSelect[data-field=defaultProfile]').val(reveilPlanning.defaultProfile);
  $('.reveilProfileSelect[data-field=garde_avec]').val(reveilPlanning.garde.avec || '');
  $('.reveilProfileSelect[data-field=garde_sans]').val(reveilPlanning.garde.sans || '');
}

function reveilRenderWeeks() {
  var $tb = $('#table_reveilWeeks tbody').empty();
  Object.keys(reveilPlanning.weeks).sort().forEach(function (w) {
    $tb.append('<tr data-week="' + w + '"><td>' + w + '</td><td>' + reveilPlanning.weeks[w] + '</td><td><a class="btn btn-xs btn-danger bt_reveilDelWeek"><i class="fas fa-trash"></i></a></td></tr>');
  });
}

function reveilRenderExceptions() {
  var $tb = $('#table_reveilExceptions tbody').empty();
  Object.keys(reveilPlanning.exceptions).sort().forEach(function (d) {
    var v = reveilPlanning.exceptions[d];
    var label = v == 'off' ? '{{Pas de réveil}}' : (v == 'absent' ? '{{Absent}}' : v);
    if (v.charAt(0) == '@') {
      var n = v.substring(1);
      label = '<i class="fas fa-clock" style="color:#8e24aa"></i> ' + $('<div>').text(n).html() + ' ' + (reveilPlanning.dayProfiles[n] || '{{(profil supprimé)}}');
    }
    $tb.append('<tr data-date="' + d + '"><td>' + d + '</td><td>' + label + '</td><td><a class="btn btn-xs btn-danger bt_reveilDelExc"><i class="fas fa-trash"></i></a></td></tr>');
  });
}

/* ---------- Collecte UI -> objet ---------- */

function reveilCollect() {
  var dayProfiles = {};
  $('#table_reveilDayProfiles tbody tr').each(function () {
    var n = $(this).find('.reveilDayName').val().trim();
    if (n !== '') { dayProfiles[n] = $(this).find('.reveilDayTime').val(); }
  });
  // un profil du jour renommé : les dates qui l'utilisaient suivent le nouveau nom
  var oldNames = Object.keys(reveilPlanning.dayProfiles), newNames = Object.keys(dayProfiles);
  if (oldNames.length == newNames.length) {
    oldNames.forEach(function (o, i) {
      if (o !== newNames[i]) {
        $.each(reveilPlanning.exceptions, function (d, v) { if (v === '@' + o) { reveilPlanning.exceptions[d] = '@' + newNames[i]; } });
      }
    });
  }
  reveilPlanning.dayProfiles = dayProfiles;
  var profiles = {};
  $('#table_reveilProfiles tbody tr').each(function () {
    var name = $(this).find('.reveilProfileName').val().trim();
    if (name == '') { return; }
    var days = {};
    $(this).find('.reveilProfileDay').each(function () { days[$(this).data('day')] = $(this).val(); });
    profiles[name] = days;
  });
  reveilPlanning.profiles = profiles;
  reveilPlanning.defaultProfile = $('.reveilProfileSelect[data-field=defaultProfile]').val() || '';
  $('.reveilRuleSelect').each(function () { reveilPlanning.rules[$(this).data('rule')] = $(this).val(); });
  reveilPlanning.garde = {
    avec: $('.reveilProfileSelect[data-field=garde_avec]').val() || '',
    sans: $('.reveilProfileSelect[data-field=garde_sans]').val() || ''
  };

}

/* ---------- Aperçu ---------- */

function reveilSchedulePreview() {
  clearTimeout(reveilPreviewTimer);
  reveilPreviewTimer = setTimeout(reveilPreview, 400);
}

function reveilPreview() {
  if (!reveilPlanning) { return; }
  reveilCollect();
  $.ajax({
    type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
    data: {action: 'preview', planning: JSON.stringify(reveilPlanning), days: 28},
    success: function (data) {
      if (data.state != 'ok') { $.fn.showAlert({message: data.result, level: 'danger'}); return; }
      var dayNames = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
      var $tb = $('#table_reveilPreview tbody').empty();
      data.result.forEach(function (d) {
        var dt = new Date(d.date + 'T12:00:00');
        var cls = d.source.indexOf('jour : ') === 0 ? 'info' : ((d.source == 'exception' && d.time) ? 'warning' : '');
        $tb.append('<tr class="' + cls + '"' + (d.time ? '' : ' style="opacity:.45"') + '><td>' + dayNames[dt.getDay()] + ' ' + d.date + '</td><td><strong>' + (d.time || '—') + '</strong></td><td>' + d.source + (d.profile ? ' · ' + d.profile : '') + '</td></tr>');
      });
    }
  });
}

/* ---------- Événements ---------- */

$('.reveilEditor').on('change', 'input, select', function () {
  if (!$(this).closest('#in_reveilQuick, #in_reveilWeekFrom, #in_reveilWeekTo, #in_reveilExcDate, #in_reveilExcTime, #sel_reveilExcDay').length) {
    reveilSchedulePreview();
  }
});

$('#table_reveilProfiles').on('change', '.reveilProfileName', function () {
  reveilCollect();
  reveilRenderSelects();
});

$('#bt_reveilAddDayProfile').on('click', function () {
  reveilCollect();
  var name = prompt('{{Nom du profil du jour}}', 'Rugby');
  if (!name) { return; }
  reveilPlanning.dayProfiles[name.trim()] = '';
  reveilRenderAll();
});

$('#table_reveilDayProfiles').on('click', '.bt_reveilDelDayProfile', function () {
  $(this).closest('tr').remove();
  reveilCollect();
  reveilRenderAll();
});

$('#table_reveilDayProfiles').on('change', 'input', function () {
  reveilCollect();
  reveilRenderAll();
});

$('#bt_reveilAddProfile').on('click', function () {
  reveilCollect();
  var name = prompt('{{Nom du profil}}', 'Vacances');
  if (!name) { return; }
  reveilPlanning.profiles[name] = {'1': '', '2': '', '3': '', '4': '', '5': '', '6': '', '7': ''};
  reveilRenderAll();
});

$('#table_reveilProfiles').on('click', '.bt_reveilDelProfile', function () {
  $(this).closest('tr').remove();
  reveilCollect();
  reveilRenderAll();
});

$('#table_reveilProfiles').on('click', '.bt_reveilCopyMonday', function () {
  var $tr = $(this).closest('tr');
  var mon = $tr.find('.reveilProfileDay[data-day=1]').val();
  ['2', '3', '4', '5'].forEach(function (d) { $tr.find('.reveilProfileDay[data-day=' + d + ']').val(mon); });
  reveilSchedulePreview();
});

$('#bt_reveilQuick').on('click', function () {
  reveilCollect();
  var profile = $('#sel_reveilQuickProfile').val();
  $.ajax({
    type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
    data: {action: 'parseQuick', text: $('#in_reveilQuick').val()},
    success: function (data) {
      if (data.state != 'ok') { $.fn.showAlert({message: data.result, level: 'danger'}); return; }
      // Seuls les jours mentionnés sont modifiés
      $.each(data.result, function (d, v) { reveilPlanning.profiles[profile][d] = v; });
      $('#in_reveilQuick').val('');
      reveilRenderAll();
    }
  });
});

function reveilIsoWeekList(from, to) {
  // from/to au format AAAA-Www (input type=week)
  var parse = function (w) {
    var m = /^(\d{4})-W(\d{2})$/.exec(w);
    if (!m) { return null; }
    var jan4 = new Date(Date.UTC(+m[1], 0, 4));
    var monday = new Date(jan4.getTime() - ((jan4.getUTCDay() + 6) % 7) * 86400000 + (+m[2] - 1) * 7 * 86400000);
    return monday;
  };
  var fmt = function (d) {
    var t = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + 3));
    var y = t.getUTCFullYear();
    var jan4 = new Date(Date.UTC(y, 0, 4));
    var wk = 1 + Math.round((t - jan4) / 86400000 / 7 - 3 / 7 + ((jan4.getUTCDay() + 6) % 7) / 7);
    return y + '-W' + (wk < 10 ? '0' : '') + wk;
  };
  var a = parse(from), b = parse(to || from), out = [];
  if (!a || !b) { return out; }
  for (var d = a; d <= b && out.length < 104; d = new Date(d.getTime() + 7 * 86400000)) { out.push(fmt(d)); }
  return out;
}

$('#bt_reveilAssignWeeks').on('click', function () {
  reveilCollect();
  var profile = $('#sel_reveilWeekProfile').val();
  var weeks = reveilIsoWeekList($('#in_reveilWeekFrom').val(), $('#in_reveilWeekTo').val());
  if (!weeks.length) { $.fn.showAlert({message: '{{Semaine(s) invalide(s)}}', level: 'warning'}); return; }
  weeks.forEach(function (w) {
    if (profile) { reveilPlanning.weeks[w] = profile; } else { delete reveilPlanning.weeks[w]; }
  });
  reveilRenderAll();
});

$('#table_reveilWeeks').on('click', '.bt_reveilDelWeek', function () {
  delete reveilPlanning.weeks[$(this).closest('tr').data('week')];
  reveilRenderAll();
});

$('#bt_reveilAddExc').on('click', function () {
  reveilCollect();
  var d = $('#in_reveilExcDate').val();
  var off = $('#cb_reveilExcOff').is(':checked');
  var t = $('#in_reveilExcTime').val();
  var dp = $('#sel_reveilExcDay').val();
  if (!d || (!off && !t && !dp)) { $.fn.showAlert({message: '{{Date et heure, "pas de réveil" ou profil du jour requis}}', level: 'warning'}); return; }
  reveilPlanning.exceptions[d] = dp ? '@' + dp : (off ? 'off' : t);
  reveilRenderAll();
});

$('#table_reveilExceptions').on('click', '.bt_reveilDelExc', function () {
  delete reveilPlanning.exceptions[$(this).closest('tr').data('date')];
  reveilRenderAll();
});


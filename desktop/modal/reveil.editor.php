<?php
require_once dirname(__FILE__) . '/../../core/class/reveil.class.php';
/* Fenêtre d'édition du planning, ouverte depuis le widget du dashboard. */
if (!isConnect()) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$eq = reveil::byId(init('id'));
if (!is_object($eq) || $eq->getEqType_name() != 'reveil') {
    throw new Exception('{{Réveil introuvable}}');
}
if (!isConnect('admin') && !$eq->hasRight('w')) {
    throw new Exception('{{Vous n\'avez pas le droit de modifier ce réveil}}');
}
require_once dirname(__FILE__) . '/../php/reveil_editor.inc.php';
?>
<div class="reveilEditor" id="md_reveilEditor" data-eqlogic_id="<?php echo $eq->getId(); ?>">
  <div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:8px;">
    <a class="btn btn-success" id="bt_reveilEditorSave"><i class="fas fa-check-circle"></i> {{Enregistrer}}</a>
  </div>
  <ul class="nav nav-tabs" role="tablist">
    <li role="presentation" class="active"><a href="#md_reveilProfiles" role="tab" data-toggle="tab"><i class="fas fa-th"></i> {{Horaires}}</a></li>
    <li role="presentation"><a href="#md_reveilCalendar" role="tab" data-toggle="tab"><i class="fas fa-calendar-alt"></i> {{Calendrier}}</a></li>
  </ul>
  <div class="tab-content">
    <div role="tabpanel" class="tab-pane active" id="md_reveilProfiles"><br><?php reveil_editor_profiles(); ?></div>
    <div role="tabpanel" class="tab-pane" id="md_reveilCalendar"><br><?php reveil_editor_calendar(); ?></div>
  </div>
</div>
<script>
if (!$.fn.showAlert && window.jeedomUtils) { $.fn.showAlert = function (o) { jeedomUtils.showAlert(o); }; }
<?php
// éditeur partagé, injecté en ligne pour s'exécuter à coup sûr dans la fenêtre
readfile(dirname(__FILE__) . '/../js/reveil_editor.js');
?>

(function () {
  // onglets Bootstrap (Jeedom 4.4 sans jQuery UI tabs sur les fenêtres)
  document.querySelectorAll('#md_reveilEditor .nav-tabs a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      document.querySelectorAll('#md_reveilEditor .nav-tabs li').forEach(function (li) { li.classList.remove('active'); });
      document.querySelectorAll('#md_reveilEditor .tab-pane').forEach(function (p) { p.classList.remove('active'); });
      a.parentNode.classList.add('active');
      document.querySelector(a.getAttribute('href')).classList.add('active');
    });
  });

  reveilEditorLoad(<?php echo json_encode($eq->getPlanning(), JSON_UNESCAPED_UNICODE); ?>);

  $('#bt_reveilEditorSave').on('click', function () {
    reveilCollect();
    var $bt = $(this).addClass('disabled');
    $.ajax({
      type: 'POST', url: 'plugins/reveil/core/ajax/reveil.ajax.php', dataType: 'json',
      data: {action: 'savePlanning', id: $('#md_reveilEditor').data('eqlogic_id'), planning: JSON.stringify(reveilPlanning)},
      success: function (data) {
        $bt.removeClass('disabled');
        var ok = data.state == 'ok';
        var msg = ok ? '{{Horaires enregistrés, réveils reprogrammés}}' : data.result;
        if (window.jeedomUtils && jeedomUtils.showAlert) {
          jeedomUtils.showAlert({message: msg, level: ok ? 'success' : 'danger'});
        } else if ($.fn.showAlert) {
          $.fn.showAlert({message: msg, level: ok ? 'success' : 'danger'});
        }
      },
      error: function () { $bt.removeClass('disabled'); }
    });
  });
})();
</script>

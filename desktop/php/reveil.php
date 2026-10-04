<?php
require_once dirname(__FILE__) . '/../../core/class/reveil.class.php';
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('reveil');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
require_once dirname(__FILE__) . '/reveil_editor.inc.php';
?>
<div class="row row-overflow">
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="add">
        <i class="fas fa-plus-circle"></i><br><span>{{Ajouter}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
      </div>
    </div>
    <legend><i class="fas fa-clock"></i> {{Mes réveils}}</legend>
    <div class="eqLogicThumbnailContainer">
      <?php
      foreach ($eqLogics as $eqLogic) {
          $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
          $next = $eqLogic->getCmd(null, 'next_wake');
          echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
          echo '<img src="' . $plugin->getPathImgIcon() . '"/><br>';
          echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
          if (is_object($next)) {
              echo '<span class="hiddenAsCard displayTableRight">' . $next->execCmd() . '</span>';
          }
          echo '</div>';
      }
      ?>
    </div>
  </div>

  <div class="col-xs-12 eqLogic" style="display:none;">
    <div class="input-group pull-right" style="display:inline-flex;">
      <span class="input-group-btn">
        <a class="btn btn-sm btn-default roundedLeft" id="bt_reveilSyncNow"><i class="fas fa-sync"></i> {{Programmer maintenant}}</a>
        <a class="btn btn-sm btn-default eqLogicAction" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
        <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
        <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
      </span>
    </div>
    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
      <li role="presentation"><a href="#planningtab" role="tab" data-toggle="tab"><i class="fas fa-th"></i> {{Profils}}</a></li>
      <li role="presentation"><a href="#calendartab" role="tab" data-toggle="tab"><i class="fas fa-calendar-alt"></i> {{Calendrier}}</a></li>
      <li role="presentation"><a href="#commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
    </ul>

    <div class="tab-content reveilEditor">
      <!-- ================= Équipement + cibles ================= -->
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <form class="form-horizontal">
          <fieldset>
            <div class="col-lg-6">
              <legend><i class="fas fa-wrench"></i> {{Général}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Nom}}</label>
                <div class="col-sm-6">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;"/>
                  <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Ex : Chambre enfant}}"/>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="object_id">
                    <option value="">{{Aucun}}</option>
                    <?php
                    foreach ((jeeObject::buildTree(null, false)) as $object) {
                        echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Options}}</label>
                <div class="col-sm-6">
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Accepter les changements faits sur l'appareil}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Un réveil modifié dans l'appli Rémi ou à la voix sur l'Alexa (autre heure le même jour, ou réveil supprimé) est gardé comme exception pour ce jour-là. Décoché : le planning est toujours réappliqué.}}"></i></sup>
                </label>
                <div class="col-sm-6"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="adoptExternal" checked/></div>
              </div>
              <legend><i class="fas fa-user-check"></i> {{Présence}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Présence (info binaire)}}</label>
                <div class="col-sm-6"><div class="input-group">
                  <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="presenceCmd"/>
                  <span class="input-group-btn"><a class="btn btn-default bt_reveilSelectCmd" data-key="presenceCmd" data-type="info" data-subtype="binary"><i class="fas fa-list-alt"></i></a></span>
                </div></div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Heure du contrôle}}</label>
                <div class="col-sm-3"><input type="time" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="presenceTime" placeholder="04:00"/></div>
                <div class="col-sm-3"><label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="presenceInvert"/>{{0 = présent}}</label></div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Action si absent}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Exécutée au contrôle si absent et qu'un réveil est prévu dans la journée. Le jour est ensuite marqué 'absent'.}}"></i></sup>
                </label>
                <div class="col-sm-6"><div class="input-group">
                  <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="presenceActionCmd"/>
                  <span class="input-group-btn"><a class="btn btn-default bt_reveilSelectCmd" data-key="presenceActionCmd" data-type="action" data-subtype=""><i class="fas fa-list-alt"></i></a></span>
                </div></div>
              </div>
            </div>

            <div class="col-lg-6">
              <legend><i class="fab fa-amazon"></i> {{Cible Alexa (plugin alexaapi)}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Activer}}</label>
                <div class="col-sm-6"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="alexaEnable"/></div>
              </div>
              <?php
              $alexaCmds = array(
                  'alexaAlarmCmd' => array('{{Créer une alarme}}', 'action', 'message'),
                  'alexaDeleteCmd' => array('{{Supprimer les alarmes (optionnel)}}', 'action', ''),
                  'alexaNextCmd' => array('{{Heure de la prochaine alarme (info existante)}}', 'info', ''),
                  'alexaRefreshCmd' => array('{{Rafraîchir (optionnel)}}', 'action', ''),
              );
              foreach ($alexaCmds as $key => $def) {
                  echo '<div class="form-group"><label class="col-sm-4 control-label">' . $def[0] . '</label><div class="col-sm-6"><div class="input-group">';
                  echo '<input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="' . $key . '"/>';
                  echo '<span class="input-group-btn"><a class="btn btn-default bt_reveilSelectCmd" data-key="' . $key . '" data-type="' . $def[1] . '" data-subtype="' . $def[2] . '"><i class="fas fa-list-alt"></i></a></span>';
                  echo '</div></div></div>';
              }
              ?>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Modèle du message}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Texte envoyé à la commande de création. Variables : #date# #time# #datetime#}}"></i></sup>
                </label>
                <div class="col-sm-6"><input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="alexaTemplate" placeholder="#date# #time#:00"/></div>
              </div>

              <legend><i class="fas fa-child"></i> {{Cible Rémi (plugin JeeRemi)}}</legend>
              <?php if (!reveilJeeRemi::available()) { ?>
                <div class="alert alert-warning">{{Plugin JeeRemi non installé ou inactif.}}</div>
              <?php } ?>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Activer}}</label>
                <div class="col-sm-6"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="remiEnable"/></div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Rémi}}</label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="remiEqId" id="sel_reveilRemiEq">
                    <option value="">{{Aucun}}</option>
                    <?php
                    if (reveilJeeRemi::available()) {
                        foreach (reveilJeeRemi::listEquipments() as $id => $name) {
                            echo '<option value="' . $id . '">' . htmlspecialchars($name) . '</option>';
                        }
                    }
                    ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Réveil piloté}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Choisissez un réveil créé dans l'appli Rémi (son et visage voulus). Le plugin ne modifie que son heure, son jour et son activation. S'il n'a pas de nom, il est nommé Jeedom pour être retrouvé.}}"></i></sup>
                </label>
                <div class="col-sm-6">
                  <div class="input-group">
                    <select class="form-control" id="sel_reveilRemiAlarm"></select>
                    <span class="input-group-btn"><a class="btn btn-default" id="bt_reveilRemiAlarms" title="{{Recharger les réveils du Rémi}}"><i class="fas fa-sync"></i></a></span>
                  </div>
                  <input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="remiAlarmName" id="in_reveilRemiAlarmName"/>
                  <div id="div_reveilRemiLinked" style="margin-top:4px;"></div>
                  <div id="div_reveilRemiAlarms" style="font-size:.85em;opacity:.75;margin-top:4px;"></div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Semaine Rémi commence le dimanche}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{À cocher si, dans la liste ci-dessus, un réveil du lundi apparaît avec les jours 0100000 au lieu de 1000000}}"></i></sup>
                </label>
                <div class="col-sm-6"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="remiSundayFirst"/></div>
              </div>
            </div>
          </fieldset>
        </form>
      </div>

      <!-- ================= Profils ================= -->
      <div role="tabpanel" class="tab-pane" id="planningtab">
        <br>
        <?php reveil_editor_profiles(); ?>
      </div>

      <!-- ================= Calendrier ================= -->
      <div role="tabpanel" class="tab-pane" id="calendartab">
        <br>
        <?php reveil_editor_calendar(); ?>
      </div>

      <!-- ================= Commandes ================= -->
      <div role="tabpanel" class="tab-pane" id="commandtab">
        <div class="table-responsive">
          <table id="table_cmd" class="table table-bordered table-condensed">
            <thead><tr><th>{{Id}}</th><th>{{Nom}}</th><th>{{Type}}</th><th>{{Valeur}}</th><th>{{Options}}</th><th>{{Action}}</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include_file('desktop', 'reveil_editor', 'js', 'reveil'); ?>
<?php include_file('desktop', 'reveil', 'js', 'reveil'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>

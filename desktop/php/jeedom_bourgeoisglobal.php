<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('jeedom_bourgeoisglobal');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <div class="col-xs-12 col-md-3 col-lg-2 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-solar-panel"></i> {{Mes Équipements}}</legend>
        <div class="input-group" style="margin-bottom: 5px;">
            <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic"/>
            <div class="input-group-btn">
                <a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
            </div>
        </div>
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $plugin->getPathImgIcon() . '"/>';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="col-xs-12 col-md-9 col-lg-10 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-default btn-sm eqLogicAction" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
                <a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i> {{Dupliquer}}</a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-danger btn-sm eqLogicAction" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="active">
                <a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a>
            </li>
            <li role="presentation">
                <a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a>
            </li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br/>
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de votre centrale solaire}}"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Objet parent}}</label>
                            <div class="col-sm-4">
                                <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php
                                    $options = '';
                                    foreach (jeeObject::all() as $object) {
                                        $options .= '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                                    }
                                    echo $options;
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Catégorie}}</label>
                            <div class="col-sm-9">
                                <?php
                                foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                    echo '<label class="checkbox-inline">';
                                    echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                                    echo '</label>';
                                }
                                ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Options}}</label>
                            <div class="col-sm-9">
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}
                                </label>
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}
                                </label>
                            </div>
                        </div>

                        <hr>
                        <legend><i class="fas fa-key"></i> {{Paramètres de connexion Bourgeois Global / Hoymiles}}</legend>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Identifiant / Email Cloud}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" placeholder="exemple@email.com"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Mot de passe Cloud}}</label>
                            <div class="col-sm-4">
                                <input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" placeholder="Mot de passe"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{ID de la Station / Centrale}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="station_id" placeholder="Ex: 123456 (Optionnel)"/>
                                <span class="help-block"><small>{{Laissez vide si vous n'avez qu'une seule installation sur votre compte}}</small></span>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br/>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 300px;">{{Nom}}</th>
                            <th style="width: 200px;">{{Type}}</th>
                            <th style="width: 150px;">{{Unité}}</th>
                            <th>{{Options}}</th>
                            <th style="width: 100px;">{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'jeedom_bourgeoisglobal', 'js', 'jeedom_bourgeoisglobal');?>
<?php include_file('core', 'plugin.template', 'js'); ?>

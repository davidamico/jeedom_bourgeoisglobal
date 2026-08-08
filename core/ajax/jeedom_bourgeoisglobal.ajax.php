<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    if (init('action') == 'testConnection') {
        // Code pour tester les identifiants Bourgeois Global
        ajax::success(array('message' => __('Connexion réussie !', __FILE__)));
    }

    throw new Exception(__('Aucune action correspondante : ', __FILE__) . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}

<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class jeedom_bourgeoisglobal extends eqLogic {

    public static function cron15() {
        foreach (self::byType('jeedom_bourgeoisglobal', true) as $eqLogic) {
            $eqLogic->refresh();
        }
    }

    public function refresh() {
        $username = $this->getConfiguration('username');
        $password = $this->getConfiguration('password');
        
        if (empty($username) || empty($password)) {
            log::add('jeedom_bourgeoisglobal', 'warning', __('Identifiants Bourgeois Global non renseignés', __FILE__));
            return;
        }

        // Logique de récupération API Cloud / Jetons
        log::add('jeedom_bourgeoisglobal', 'info', 'Rafraîchissement des données pour : ' . $this->getHumanName());
        
        // Exemple de mise à jour de commandes :
        // $this->checkAndUpdateCmd('power_w', $powerValue);
        // $this->checkAndUpdateCmd('energy_day', $dayEnergyValue);
    }

    public function postSave() {
        // Création automatique des commandes d'information si elles n'existent pas
        $powerCmd = $this->getCmd(null, 'power_w');
        if (!is_object($powerCmd)) {
            $powerCmd = new jeedom_bourgeoisglobalCmd();
            $powerCmd->setName(__('Puissance instantanée', __FILE__));
            $powerCmd->setLogicalId('power_w');
            $powerCmd->setEqLogic_id($this->getId());
            $powerCmd->setType('info');
            $powerCmd->setSubType('numeric');
            $powerCmd->setUnite('W');
            $powerCmd->save();
        }
    }
}

class jeedom_bourgeoisglobalCmd extends cmd {
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() == 'refresh') {
            $eqLogic->refresh();
        }
    }
}

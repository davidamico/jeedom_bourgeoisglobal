<?php
/* * Plugin Jeedom Bourgeois Global (Hoymiles)
 * Licence GNU AGPLv3
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class jeedom_bourgeoisglobal extends eqLogic {

    public static function cron15() {
        foreach (self::byType('jeedom_bourgeoisglobal', true) as $eqLogic) {
            $eqLogic->refresh();
        }
    }

    public function refresh() {
        // --- LOG : DÉBUT DU REFRESH ---
        log::add('jeedom_bourgeoisglobal', 'info', '=======================================================');
        log::add('jeedom_bourgeoisglobal', 'info', 'Début du rafraîchissement pour : ' . $this->getHumanName());

        $username = $this->getConfiguration('username');
        $password = $this->getConfiguration('password');
        $stationId = $this->getConfiguration('station_id'); 

        if (empty($username) || empty($password)) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Identifiants non configurés. Abandon.');
            return;
        }

        // 1. Récupération du Token
        log::add('jeedom_bourgeoisglobal', 'debug', '1. Vérification du Token d\'accès...');
        $token = $this->getApiToken($username, $password);
        if (!$token) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Impossible d\'obtenir le Token. Abandon du rafraîchissement.');
            return;
        }
        log::add('jeedom_bourgeoisglobal', 'debug', 'Token OK.');

        // 2. Requête API
        log::add('jeedom_bourgeoisglobal', 'debug', '2. Interrogation de l\'API Bourgeois Global / Hoymiles...');
        $url = 'https://global.hoymiles.com/platform/api/gateway/pvm/station_select_status';
        
        $payload = json_encode(array(
            'station_id' => $stationId
        ));

        $headers = array(
            'Cookie: WX-TOKEN=' . $token,
            'Content-Type: application/json;charset=UTF-8'
        );

        $request = new com_http($url);
        $request->setHeaders($headers);
        $request->setPost($payload);
        $request->setNoSslCheck(true);
        $request->setTimeout(15);
        $response = $request->exec();

        if (empty($response)) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Réponse vide du serveur API.');
            return;
        }

        $data = json_decode($response, true);
        
        // --- LOG : AFFICHAGE DU JSON BRUT (Uniquement en mode Debug) ---
        log::add('jeedom_bourgeoisglobal', 'debug', 'Réponse JSON brute : ' . print_r($data, true));

        if (!is_array($data) || !isset($data['data'])) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Format de données inattendu ou erreur serveur.');
            return;
        }

        // 3. Extraction des valeurs
        log::add('jeedom_bourgeoisglobal', 'debug', '3. Extraction et mise à jour des commandes Jeedom...');
        $powerW = isset($data['data']['real_power']) ? floatval($data['data']['real_power']) : 0;
        $energyDayKwh = isset($data['data']['daily_eq']) ? floatval($data['data']['daily_eq']) : 0;
        $energyTotalKwh = isset($data['data']['total_eq']) ? floatval($data['data']['total_eq']) : 0;

        $formattedDate = date('Y-m-d H:i:s');

        // 4. Mise à jour des commandes
        $this->checkAndUpdateCmd('power_w', $powerW, $formattedDate);
        $this->checkAndUpdateCmd('energy_day', $energyDayKwh, $formattedDate);
        $this->checkAndUpdateCmd('energy_total', $energyTotalKwh, $formattedDate);

        // --- LOG : FIN ---
        log::add('jeedom_bourgeoisglobal', 'info', sprintf('Succès : Puissance = %s W | Prod. Jour = %s kWh', $powerW, $energyDayKwh));
    }

    private function getApiToken($_username, $_password) {
        $cacheKey = 'jeedom_bourgeoisglobal_token_' . md5($_username);
        $cachedToken = cache::byKey($cacheKey)->getValue(null);

        if ($cachedToken !== null && !empty($cachedToken)) {
            log::add('jeedom_bourgeoisglobal', 'debug', '--> Utilisation du Token en cache (valide).');
            return $cachedToken;
        }

        log::add('jeedom_bourgeoisglobal', 'info', '--> Aucun Token valide en cache. Nouvelle demande d\'authentification en cours...');
        $loginUrl = 'https://global.hoymiles.com/platform/api/gateway/iam/auth_login'; 
        
        $payload = json_encode(array(
            'user_name' => $_username,
            'password' => md5($_password)
        ));

        $headers = array('Content-Type: application/json;charset=UTF-8');

        $request = new com_http($loginUrl);
        $request->setHeaders($headers);
        $request->setPost($payload);
        $request->setNoSslCheck(true);
        $request->setTimeout(10);
        $response = $request->exec();

        $data = json_decode($response, true);

        if (isset($data['data']['token']) && !empty($data['data']['token'])) {
            $token = $data['data']['token'];
            cache::set($cacheKey, $token, 86000); // Valide 24h
            log::add('jeedom_bourgeoisglobal', 'debug', '--> Nouveau Token obtenu et mis en cache avec succès.');
            return $token;
        }

        log::add('jeedom_bourgeoisglobal', 'error', '--> Échec de l\'authentification ! Réponse API : ' . $response);
        return false;
    }

    public function postSave() {
        // [Le reste du code de création des commandes reste identique...]
        $powerCmd = $this->getCmd(null, 'power_w');
        if (!is_object($powerCmd)) {
            $powerCmd = new jeedom_bourgeoisglobalCmd();
            $powerCmd->setName(__('Puissance instantanée', __FILE__));
            $powerCmd->setLogicalId('power_w');
            $powerCmd->setEqLogic_id($this->getId());
            $powerCmd->setType('info');
            $powerCmd->setSubType('numeric');
            $powerCmd->setUnite('W');
            $powerCmd->setIsHistorized(1);
            $powerCmd->setOrder(1);
            $powerCmd->save();
        }

        $dayCmd = $this->getCmd(null, 'energy_day');
        if (!is_object($dayCmd)) {
            $dayCmd = new jeedom_bourgeoisglobalCmd();
            $dayCmd->setName(__('Production du jour', __FILE__));
            $dayCmd->setLogicalId('energy_day');
            $dayCmd->setEqLogic_id($this->getId());
            $dayCmd->setType('info');
            $dayCmd->setSubType('numeric');
            $dayCmd->setUnite('kWh');
            $dayCmd->setIsHistorized(1);
            $dayCmd->setOrder(2);
            $dayCmd->save();
        }

        $totalCmd = $this->getCmd(null, 'energy_total');
        if (!is_object($totalCmd)) {
            $totalCmd = new jeedom_bourgeoisglobalCmd();
            $totalCmd->setName(__('Production totale', __FILE__));
            $totalCmd->setLogicalId('energy_total');
            $totalCmd->setEqLogic_id($this->getId());
            $totalCmd->setType('info');
            $totalCmd->setSubType('numeric');
            $totalCmd->setUnite('kWh');
            $totalCmd->setIsHistorized(1);
            $totalCmd->setOrder(3);
            $totalCmd->save();
        }

        $refreshCmd = $this->getCmd(null, 'refresh');
        if (!is_object($refreshCmd)) {
            $refreshCmd = new jeedom_bourgeoisglobalCmd();
            $refreshCmd->setName(__('Rafraîchir', __FILE__));
            $refreshCmd->setLogicalId('refresh');
            $refreshCmd->setEqLogic_id($this->getId());
            $refreshCmd->setType('action');
            $refreshCmd->setSubType('other');
            $refreshCmd->setOrder(4);
            $refreshCmd->save();
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

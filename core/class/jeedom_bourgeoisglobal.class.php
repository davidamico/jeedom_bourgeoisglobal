<?php
/* * Plugin Jeedom Bourgeois Global
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

        // 2. Requête API de statut
        $apiUrl = $this->getConfiguration('api_base_url', 'https://app.bourgeoisglobal.fr');
        $statusUrl = rtrim($apiUrl, '/') . '/platform/api/gateway/pvm/station_select_status';
        
        log::add('jeedom_bourgeoisglobal', 'debug', 'Interrogation de l\'API : ' . $statusUrl);

        // Construction du payload avec toutes les variantes de clés d'identification possibles
        $payloadArray = array(
            'station_id' => $stationId
        );
        if (!empty($stationId)) {
            $payloadArray['swaggerId'] = $stationId;
            $payloadArray['id'] = $stationId;
        }
        $payload = json_encode($payloadArray);

        $ch = curl_init($statusUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json;charset=UTF-8',
            'Cookie: WX-TOKEN=' . $token
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (empty($response) || $httpCode != 200) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Erreur API - Code HTTP : ' . $httpCode . ' Réponse : ' . $response);
            return;
        }

        $data = json_decode($response, true);
        log::add('jeedom_bourgeoisglobal', 'debug', 'Réponse JSON brute : ' . print_r($data, true));

        if (!is_array($data) || !isset($data['data'])) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Format de données inattendu reçu du serveur.');
            return;
        }

        // 3. Extraction des valeurs
        $powerW = isset($data['data']['real_power']) ? floatval($data['data']['real_power']) : 0;
        $energyDayKwh = isset($data['data']['daily_eq']) ? floatval($data['data']['daily_eq']) : 0;
        $energyTotalKwh = isset($data['data']['total_eq']) ? floatval($data['data']['total_eq']) : 0;

        $formattedDate = date('Y-m-d H:i:s');

        // 4. Mise à jour des commandes
        $this->checkAndUpdateCmd('power_w', $powerW, $formattedDate);
        $this->checkAndUpdateCmd('energy_day', $energyDayKwh, $formattedDate);
        $this->checkAndUpdateCmd('energy_total', $energyTotalKwh, $formattedDate);

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
        
        $apiUrl = $this->getConfiguration('api_base_url', 'https://app.bourgeoisglobal.fr');
        $loginUrl = rtrim($apiUrl, '/') . '/platform/api/gateway/iam/auth_login'; 
        
        log::add('jeedom_bourgeoisglobal', 'debug', 'URL de connexion testée : ' . $loginUrl);

        $payload = json_encode(array(
            'user_name' => $_username,
            'password' => md5($_password)
        ));

        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=UTF-8'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode == 200 && isset($data['data']['token']) && !empty($data['data']['token'])) {
            $token = $data['data']['token'];
            cache::set($cacheKey, $token, 86000); // Valide 24h
            log::add('jeedom_bourgeoisglobal', 'debug', '--> Nouveau Token obtenu et mis en cache avec succès.');
            return $token;
        }

        log::add('jeedom_bourgeoisglobal', 'error', '--> Échec de l\'authentification ! Code HTTP : ' . $httpCode . ' Réponse : ' . $response);
        return false;
    }

    public function postSave() {
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

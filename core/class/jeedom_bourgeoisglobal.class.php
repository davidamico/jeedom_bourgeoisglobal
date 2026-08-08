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
        $stationId = $this->getConfiguration('station_id', '12078440'); 
        $apiUrl = $this->getConfiguration('api_base_url', 'https://app.bourgeoisglobal.fr');

        if (empty($username) || empty($password)) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Identifiants non configurés. Abandon.');
            return;
        }

        // 1. Récupération du Token
        $token = $this->getApiToken($username, $password, $apiUrl);
        if (!$token) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Impossible d\'obtenir le Token.');
            return;
        }

        // 2. Test avec passage de l'ID en paramètre d'URL (GET) ou corps JSON enrichi
        $statusUrl = rtrim($apiUrl, '/') . '/platform/api/gateway/pvm/station_select_status?swaggerId=' . $stationId . '&id=' . $stationId;
        log::add('jeedom_bourgeoisglobal', 'debug', 'Interrogation URL : ' . $statusUrl);

        $payload = json_encode(array(
            'swaggerId' => $stationId,
            'id' => $stationId,
            'stationId' => $stationId
        ));

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

        log::add('jeedom_bourgeoisglobal', 'debug', 'Réponse HTTP ' . $httpCode . ' : ' . $response);

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['data'])) {
            log::add('jeedom_bourgeoisglobal', 'error', 'Erreur API - Réponse invalide.');
            return;
        }

        // 3. Extraction des valeurs
        $powerW = isset($data['data']['real_power']) ? floatval($data['data']['real_power']) : 0;
        $energyDayKwh = isset($data['data']['daily_eq']) ? floatval($data['data']['daily_eq']) : 0;
        $energyTotalKwh = isset($data['data']['total_eq']) ? floatval($data['data']['total_eq']) : 0;

        $formattedDate = date('Y-m-d H:i:s');

        $this->checkAndUpdateCmd('power_w', $powerW, $formattedDate);
        $this->checkAndUpdateCmd('energy_day', $energyDayKwh, $formattedDate);
        $this->checkAndUpdateCmd('energy_total', $energyTotalKwh, $formattedDate);

        log::add('jeedom_bourgeoisglobal', 'info', sprintf('Succès : Puissance = %s W | Prod. Jour = %s kWh', $powerW, $energyDayKwh));
    }

    private function getApiToken($_username, $_password, $_apiUrl) {
        $cacheKey = 'jeedom_bourgeoisglobal_token_' . md5($_username);
        $cachedToken = cache::byKey($cacheKey)->getValue(null);

        if ($cachedToken !== null && !empty($cachedToken)) {
            return $cachedToken;
        }

        $loginUrl = rtrim($_apiUrl, '/') . '/platform/api/gateway/iam/auth_login'; 
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
            cache::set($cacheKey, $token, 86000);
            return $token;
        }

        log::add('jeedom_bourgeoisglobal', 'error', 'Échec authentification HTTP ' . $httpCode);
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

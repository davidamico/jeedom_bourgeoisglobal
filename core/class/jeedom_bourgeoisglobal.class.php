<?php
/* * Plugin Jeedom Bourgeois Global (Hoymiles)
 * Licence GNU AGPLv3
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class jeedom_bourgeoisglobal extends eqLogic {

    /* =========================================================================
     * PLANIFICATION (CRON)
     * ========================================================================= */

    public static function cron15() {
        foreach (self::byType('jeedom_bourgeoisglobal', true) as $eqLogic) {
            $eqLogic->refresh();
        }
    }

    /* =========================================================================
     * MÉTHODES PRINCIPALES DE L'ÉQUIPEMENT
     * ========================================================================= */

    public function refresh() {
        $username = $this->getConfiguration('username');
        $password = $this->getConfiguration('password');
        $stationId = $this->getConfiguration('station_id'); // Optionnel, si vous ciblez une station précise

        if (empty($username) || empty($password)) {
            log::add('jeedom_bourgeoisglobal', 'warning', sprintf(__('Identifiants non configurés pour l\'équipement %s', __FILE__), $this->getHumanName()));
            return;
        }

        // 1. Récupération ou renouvellement du token d'accès
        $token = $this->getApiToken($username, $password);
        if (!$token) {
            log::add('jeedom_bourgeoisglobal', 'error', sprintf(__('Impossible d\'obtenir un jeton d\'accès pour %s', __FILE__), $this->getHumanName()));
            return;
        }

        // 2. Interrogation de l'endpoint des métriques de la centrale solaire
        $url = 'https://global.hoymiles.com/platform/api/gateway/pvm/station_select_status';
        
        $payload = json_encode(array(
            'station_id' => $stationId
        ));

        // Le cloud Hoymiles exige le token dans le Cookie
        $headers = array(
            'Cookie: WX-TOKEN=' . $token,
            'Content-Type: application/json;charset=UTF-8'
        );

        $request = new com_http($url);
        $request->setHeaders($headers);
        $request->setPost($payload); // Utilisation d'un POST comme l'exige l'API Hoymiles
        $request->setNoSslCheck(true);
        $request->setTimeout(15);
        $response = $request->exec();

        if (empty($response)) {
            log::add('jeedom_bourgeoisglobal', 'error', sprintf(__('Réponse vide de l\'API Bourgeois Global pour %s', __FILE__), $this->getHumanName()));
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['data'])) {
            log::add('jeedom_bourgeoisglobal', 'error', sprintf(__('Format JSON invalide ou données manquantes reçu pour %s : %s', __FILE__), $this->getHumanName(), $response));
            return;
        }

        log::add('jeedom_bourgeoisglobal', 'debug', 'Données API reçues : ' . print_r($data, true));

        // 3. Extraction des valeurs (à adapter selon les clés exactes renvoyées par le JSON d'Hoymiles)
        // Les noms de variables ci-dessous (real_power, daily_eq, total_eq) sont des exemples courants de l'API Hoymiles
        $powerW = isset($data['data']['real_power']) ? floatval($data['data']['real_power']) : 0;
        $energyDayKwh = isset($data['data']['daily_eq']) ? floatval($data['data']['daily_eq']) : 0;
        $energyTotalKwh = isset($data['data']['total_eq']) ? floatval($data['data']['total_eq']) : 0;

        // 4. Gestion de la date et du fuseau horaire (Conversion UTC -> Heure locale Jeedom)
        $timestamp = isset($data['data']['update_time']) ? $data['data']['update_time'] : 'now';
        try {
            // L'API Hoymiles renvoie souvent l'heure locale de la station, si c'est de l'UTC, décommentez la conversion :
            // $date = new DateTime($timestamp, new DateTimeZone('UTC'));
            // $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
            // $formattedDate = $date->format('Y-m-d H:i:s');
            
            // On prend par défaut la date du système au moment du refresh pour simplifier
            $formattedDate = date('Y-m-d H:i:s');
        } catch (Exception $e) {
            $formattedDate = date('Y-m-d H:i:s');
        }

        // 5. Mise à jour des commandes d'information dans Jeedom
        $this->checkAndUpdateCmd('power_w', $powerW, $formattedDate);
        $this->checkAndUpdateCmd('energy_day', $energyDayKwh, $formattedDate);
        $this->checkAndUpdateCmd('energy_total', $energyTotalKwh, $formattedDate);

        log::add('jeedom_bourgeoisglobal', 'info', sprintf(__('Données mises à jour [Puissance: %s W, Jour: %s kWh]', __FILE__), $powerW, $energyDayKwh));
    }

    /* =========================================================================
     * GESTION DE L'AUTHENTIFICATION & DU TOKEN (API HOYMILES)
     * ========================================================================= */

    private function getApiToken($_username, $_password) {
        $cacheKey = 'jeedom_bourgeoisglobal_token_' . md5($_username);
        $cachedToken = cache::byKey($cacheKey)->getValue(null);

        if ($cachedToken !== null && !empty($cachedToken)) {
            return $cachedToken;
        }

        // Authentification HTTP POST auprès du serveur S-Miles Cloud
        $loginUrl = 'https://global.hoymiles.com/platform/api/gateway/iam/auth_login'; 
        
        $payload = json_encode(array(
            'user_name' => $_username,
            'password' => md5($_password) // Hachage MD5 requis par l'API
        ));

        $headers = array('Content-Type: application/json;charset=UTF-8');

        $request = new com_http($loginUrl);
        $request->setHeaders($headers);
        $request->setPost($payload);
        $request->setNoSslCheck(true);
        $request->setTimeout(10);
        $response = $request->exec();

        $data = json_decode($response, true);

        // Extraction du token (Généralement placé dans data -> token chez Hoymiles)
        if (isset($data['data']['token']) && !empty($data['data']['token'])) {
            $token = $data['data']['token'];
            $expiresIn = 86400; // Conservation 24h par défaut en cache
            
            cache::set($cacheKey, $token, $expiresIn - 60);
            return $token;
        }

        log::add('jeedom_bourgeoisglobal', 'error', 'Échec de la connexion S-Miles Cloud. Réponse : ' . $response);
        return false;
    }

    /* =========================================================================
     * CRÉATION AUTOMATIQUE DES COMMANDES
     * ========================================================================= */

    public function postSave() {
        // 1. Commande Info : Puissance instantanée (W)
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

        // 2. Commande Info : Production du jour (kWh)
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

        // 3. Commande Info : Production totale (kWh)
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

        // 4. Commande Action : Rafraîchir
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

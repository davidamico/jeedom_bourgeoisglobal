<?php
/* * Plugin Jeedom Bourgeois Global Photovoltaïque
 * Licence GNU AGPLv3
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class jeedom_bourgeoisglobal extends eqLogic {

    /* =========================================================================
     * PLANIFICATION (CRON)
     * ========================================================================= */

    /**
     * Exécuté automatiquement toutes les 15 minutes par le moteur Jeedom
     */
    public static function cron15() {
        foreach (self::byType('jeedom_bourgeoisglobal', true) as $eqLogic) {
            $eqLogic->refresh();
        }
    }

    /* =========================================================================
     * MÉTHODES PRINCIPALES DE L'ÉQUIPEMENT
     * ========================================================================= */

    /**
     * Récupère les données depuis l'API Cloud Bourgeois Global et met à jour Jeedom
     */
    public function refresh() {
        $username = $this->getConfiguration('username');
        $password = $this->getConfiguration('password');
        $stationId = $this->getConfiguration('station_id'); // ID de la centrale si requis

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
        $url = 'https://api.bourgeoisglobal.com/v1/plant/data'; // Adaptez l'URL selon l'API exacte (Horus / S-Miles)
        if (!empty($stationId)) {
            $url .= '?plant_id=' . urlencode($stationId);
        }

        $headers = array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        );

        $request = new com_http($url);
        $request->setHeaders($headers);
        $request->setNoSslCheck(true);
        $request->setTimeout(10);
        $response = $request->exec();

        if (empty($response)) {
            log::add('jeedom_bourgeoisglobal', 'error', sprintf(__('Réponse vide de l\'API Bourgeois Global pour %s', __FILE__), $this->getHumanName()));
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            log::add('jeedom_bourgeoisglobal', 'error', sprintf(__('Format JSON invalide reçu pour %s', __FILE__), $this->getHumanName()));
            return;
        }

        log::add('jeedom_bourgeoisglobal', 'debug', 'Données API reçues : ' . print_r($data, true));

        // 3. Extraction des valeurs principales
        $powerW = isset($data['current_power_w']) ? floatval($data['current_power_w']) : 0;
        $energyDayKwh = isset($data['today_energy_kwh']) ? floatval($data['today_energy_kwh']) : 0;
        $energyTotalKwh = isset($data['total_energy_kwh']) ? floatval($data['total_energy_kwh']) : 0;

        // 4. Gestion de la date et du fuseau horaire (Conversion UTC -> Heure locale Jeedom)
        $timestamp = isset($data['updated_at']) ? $data['updated_at'] : 'now';
        try {
            $date = new DateTime($timestamp, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $formattedDate = $date->format('Y-m-d H:i:s');
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
     * GESTION DE L'AUTHENTIFICATION & DU TOKEN
     * ========================================================================= */

    /**
     * Obtient un Token d'accès en cache ou effectue une reconnexion
     */
    private function getApiToken($_username, $_password) {
        $cacheKey = 'jeedom_bourgeoisglobal_token_' . md5($_username);
        $cachedToken = cache::byKey($cacheKey)->getValue(null);

        if ($cachedToken !== null && !empty($cachedToken)) {
            return $cachedToken;
        }

        // Authentification HTTP POST auprès du serveur Cloud
        $loginUrl = 'https://api.bourgeoisglobal.com/v1/auth/login'; // Adaptez l'URL de login
        $payload = json_encode(array(
            'username' => $_username,
            'password' => $_password
        ));

        $headers = array('Content-Type: application/json');

        $request = new com_http($loginUrl);
        $request->setHeaders($headers);
        $request->setPost($payload);
        $request->setNoSslCheck(true);
        $request->setTimeout(10);
        $response = $request->exec();

        $data = json_decode($response, true);

        if (isset($data['token']) && !empty($data['token'])) {
            $token = $data['token'];
            $expiresIn = isset($data['expires_in']) ? intval($data['expires_in']) - 60 : 3500;
            
            // Stockage dans le cache natif Jeedom
            cache::set($cacheKey, $token, $expiresIn);
            return $token;
        }

        log::add('jeedom_bourgeoisglobal', 'error', 'Échec de la connexion à l\'API Bourgeois Global : ' . $response);
        return false;
    }

    /* =========================================================================
     * CRÉATION AUTOMATIQUE DES COMMANDES (CYCLE DE VIE)
     * ========================================================================= */

    /**
     * Exécuté automatiquement lors de la sauvegarde de l'équipement
     */
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
    
    /**
     * Exécution des commandes d'action (bouton Rafraîchir)
     */
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() == 'refresh') {
            $eqLogic->refresh();
        }
    }
}

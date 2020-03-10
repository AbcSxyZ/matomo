<?php
namespace Piwik\Plugins\Installation;

use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Db\Adapter;
use Piwik\DbHelper;
use Piwik\Config;
use Piwik\Common;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Plugins\UsersManager\NewsletterSignup;
use Piwik\Plugins\UsersManager\UserUpdater;
use Piwik\ProxyHeaders;
use Piwik\Tracker\TrackerCodeGenerator;
use Piwik\Updater;
use Piwik\Url;
use Piwik\Version;

class InstallManager{
    /**
     * Control if the system is ready for installation,
     * run Plugins\Diagnostics to get state.
     * 
     * @view View
    */
    public static function systemCheck($view)
    {
        // Do not use dependency injection because this service requires a lot of sub-services across plugins
        /** @var DiagnosticService $diagnosticService */
        $diagnosticService = StaticContainer::get('Piwik\Plugins\Diagnostics\DiagnosticService');
        $diagnosticReport = $diagnosticService->runDiagnostics();

        if (!is_null($view))
        {
            $view->diagnosticReport = $diagnosticReport;
            $view->showNextStep = !$diagnosticReport->hasErrors();

            // On the system check page, if all is green, display Next link at the top
            $view->showNextStepAtTop = $view->showNextStep && !$diagnosticReport->hasWarnings();
        }

        //HEADLESS: DO STUFF WITH errors
        return (!$diagnosticReport->hasErrors());
    }

    public static function createDatabaseObject($settings)
    {
        //S : Trim is usefull ?? Why the entry shouldn't be trimmed ?
        $dbname = trim($settings["dbname"]); 
        if (empty($dbname))
        {
            throw new Exception("No database name");
        }

        $adapter = $settings['adapter'];
        $port = Adapter::getDefaultPortForAdapter($adapter);
        $host = $settings['host'];
        $tables_prefix = $settings['tables_prefix'];

        $dbInfos = array(
            'host'          => (is_null($host)) ? $host : trim($host),
            'username'      => $settings['username'],
            'password'      => $settings['password'],
            'dbname'        => $dbname,
            'tables_prefix' => (is_null($tables_prefix)) ? $tables_prefix : trim($tables_prefix),
            'adapter'       => $adapter,
            'port'          => $port,
            'schema'        => Config::getInstance()->database['schema'],
            'type'          => $settings['type'],
            'enable_ssl'    => false
        );

        if (($portIndex = strpos($dbInfos['host'], '/')) !== false) {
            // unix_socket=/path/sock.n
            $dbInfos['port'] = substr($dbInfos['host'], $portIndex);
            $dbInfos['host'] = '';
        } else if (($portIndex = strpos($dbInfos['host'], ':')) !== false) {
            // host:port
            $dbInfos['port'] = substr($dbInfos['host'], $portIndex + 1);
            $dbInfos['host'] = substr($dbInfos['host'], 0, $portIndex);
        }

        try {
            @Db::createDatabaseObject($dbInfos);
        } catch (Zend_Db_Adapter_Exception $e) {
            $db = Adapter::factory($adapter, $dbInfos, $connect = false);

            // database not found, we try to create  it
            if ($db->isErrNo($e, '1049')) {
                $dbInfosConnectOnly = $dbInfos;
                $dbInfosConnectOnly['dbname'] = null;
                @Db::createDatabaseObject($dbInfosConnectOnly);
                @DbHelper::createDatabase($dbInfos['dbname']);

                /* // select the newly created database */
                @Db::createDatabaseObject($dbInfos);
            } else {
                throw $e;
            }
        }
        @DbHelper::checkDatabaseVersion();

        @Db::get()->checkClientVersion();
        self::createConfigFile($dbInfos);
        return $dbInfos;
    }
    
    public static function tablesCreation()
    {
        DbHelper::createTables();
        DbHelper::createAnonymousUser();
        DbHelper::recordInstallVersion();

        self::updateComponents();

        Updater::recordComponentSuccessfullyUpdated('core', Version::VERSION);
    }

    public static function setupSuperUser($settings)
    {
        // Can throw exception

        //WHICH WORK IF USER EXISTS ?

        //Create user
        self::createSuperUser($settings['login'],
                              $settings['password'],
                              $settings['email']);


        //Subscribe matomo newsletters
        NewsletterSignup::signupForNewsletter(
            $settings['login'],
            $settings['email'],
            $settings['subscribe_newsletter_piwikorg'],
            $settings['subscribe_newsletter_professionalservices']
        );
    }

    public static function firstWebsiteSetup($settings)
    {
        $name = $settings['name'];
        $url = $settings['url'];
        $ecommerce = $settings['ecommerce'];
        $result = Access::doAsSuperUser(function () use ($name, $url, $ecommerce) {
            return APISitesManager::getInstance()->addSite($name, $url, $ecommerce);
        });

        $params = array(
            'site_idSite'   => $result,
            'site_name'     => urlencode($name)
        );
        self::addTrustedHosts($settings['url']);
        return $params;
    }

    public static function trackingCode($idSite=null)
    {
        if (is_null($siteId))
            $idSite = InstallManager::getParam('idSite');

        $javascriptGenerator = new TrackerCodeGenerator();
        $jsTag = $javascriptGenerator->generate($idSite, Url::getCurrentUrlWithoutFileName());
        $rawJsTag = TrackerCodeGenerator::stripTags($jsTag);
        return $rawJsTag;
        
    }

    protected static function getParam($name)
    {
        return Common::getRequestVar($name, false, 'string');
    }

    public static function createSuperUser($login, $password, $email)
    {
        Access::doAsSuperUser(function () use ($login, $password, $email) {
            $api = APIUsersManager::getInstance();
            $api->addUser($login, $password, $email);

            $userUpdater = new UserUpdater();
            $userUpdater->setSuperUserAccessWithoutCurrentPassword($login, true);
        });
    }

    /**
     * Write configuration file from session-store
     */

    public static function createConfigFile($dbInfos)
    {
        $config = Config::getInstance();

        // make sure DB sessions are used if the filesystem is NFS
        if (count($headers = ProxyHeaders::getProxyClientHeaders()) > 0) {
            $config->General['proxy_client_headers'] = $headers;
        }
        if (count($headers = ProxyHeaders::getProxyHostHeaders()) > 0) {
            $config->General['proxy_host_headers'] = $headers;
        }

        if (Common::getRequestVar('clientProtocol', 'http', 'string') == 'https') {
            $protocol = 'https';
        } else {
            $protocol = ProxyHeaders::getProtocolInformation();
        }

        if (!empty($protocol)
            && !\Piwik\ProxyHttp::isHttps()) {
            $config->General['assume_secure_protocol'] = '1';
        }

        $config->General['salt'] = Common::generateUniqId();
        $config->General['installation_in_progress'] = 1;

        $config->database = $dbInfos;
        if (!DbHelper::isDatabaseConnectionUTF8()) {
            $config->database['charset'] = 'utf8';
        }

        $config->forceSave();
    }

    protected static function updateComponents()
    {
        Access::getInstance();

        return Access::doAsSuperUser(function () {
            $updater = new Updater();
            $componentsWithUpdateFile = $updater->getComponentUpdates();

            if (empty($componentsWithUpdateFile)) {
                return false;
            }
            $result = $updater->updateComponents($componentsWithUpdateFile);
            return $result;
        });
    }
    protected static function addTrustedHosts($siteUrl)
    {
        $trustedHosts = array();

        // extract host from the request header
        if (($host = self::extractHost('http://' . Url::getHost())) !== false) {
            $trustedHosts[] = $host;
        }

        // extract host from first web site
        if (($host = self::extractHost(urldecode($siteUrl))) !== false) {
            $trustedHosts[] = $host;
        }

        $trustedHosts = array_unique($trustedHosts);
        if (count($trustedHosts)) {

            $general = Config::getInstance()->General;
            $general['trusted_hosts'] = $trustedHosts;
            Config::getInstance()->General = $general;

            Config::getInstance()->forceSave();
        }
    } 

    /**
     * Extract host from URL
     *
     * @param string $url URL
     *
     * @return string|false
     */
    public static function extractHost($url)
    {
        $urlParts = parse_url($url);
        if (isset($urlParts['host']) && strlen($host = $urlParts['host'])) {
            return $host;
        }

        return false;
    }

}

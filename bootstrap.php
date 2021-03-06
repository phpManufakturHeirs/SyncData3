<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

include_once __DIR__.'/vendor/autoloader.php';

use phpManufaktur\SyncData\Control\Backup;
use phpManufaktur\SyncData\Control\Utils;
use phpManufaktur\SyncData\Control\Application;
use phpManufaktur\SyncData\Control\Restore;
use phpManufaktur\SyncData\Control\Check;
use phpManufaktur\SyncData\Control\Autosync;
use phpManufaktur\SyncData\Data\Setup\Setup;
use phpManufaktur\SyncData\Control\CreateSynchronizeArchive;
use phpManufaktur\SyncData\Control\SynchronizeClient;
use phpManufaktur\SyncData\Control\SynchronizeServer;
use phpManufaktur\SyncData\Control\CheckKey;
use phpManufaktur\SyncData\Control\Template;
use phpManufaktur\ConfirmationLog\Data\Setup\Setup as confirmationSetup;
use phpManufaktur\ConfirmationLog\Data\Setup\Update as confirmationUpdate;
use phpManufaktur\ConfirmationLog\Data\Setup\Uninstall as confirmationUninstall;
use phpManufaktur\ConfirmationLog\Data\Setup\SetupTool;
use phpManufaktur\ConfirmationLog\Data\Import\ImportOldLog;
use phpManufaktur\SyncData\Control\Confirmations;
use phpManufaktur\SyncData\Data\Setup\Uninstall;

// set the error handling
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('SYNCDATA_SCRIPT_START', microtime(true));

try {

    // init the application
    $app = new Application();

    $app['utils'] = $app->share(function() use($app) {
        return new Utils($app);
    });

    define('SYNCDATA_PATH', $app['utils']->sanitizePath(__DIR__));
    define('MANUFAKTUR_PATH', SYNCDATA_PATH.'/vendor/phpManufaktur');

    include SYNCDATA_PATH.'/bootstrap.inc';

    if($app['config']['sync']['enabled'] === true) {
        $app['autosync'] = $app->share(function() use($app) {
            return new Autosync($app);
        });
    }

    // get the SyncDataServer directory
    $syncdata_directory = dirname($_SERVER['SCRIPT_NAME']);

    if (!in_array($syncdata_directory, $app['config']['backup']['directories']['ignore']['directory'])) {
        // we must grant that the SyncDataServer /temp directory is always ignored (recursion!!!)
        $config = $app['config'];
        $config['backup']['directories']['ignore']['directory'][] = $syncdata_directory.'/temp';
        $app['config'] = $app->share(function($app) use ($config) {
            return $config;
        });
    }

    // got the route dynamically from the real directory where SyncData reside.
    // .htaccess RewriteBase must be equal to the SyncData directory!
    $route = substr($_SERVER['REQUEST_URI'], strlen($syncdata_directory),
        (false !== ($pos = strpos($_SERVER['REQUEST_URI'], '?'))) ? $pos-strlen($syncdata_directory) : strlen($_SERVER['REQUEST_URI']));

    define('SYNCDATA_ROUTE', $route);
    define('SYNCDATA_URL', substr($app['config']['CMS']['CMS_URL'].substr(SYNCDATA_PATH, strlen($app['config']['CMS']['CMS_PATH'])), 0));

    // $initConfig is defined in bootstrap.inc
    if ($initConfig->executedSetup() && $app['config']['security']['active']) {
        // if SyncData was initialized prompt a message!
        $initConfirmation = new confirmationSetup();
        $initConfirmation->exec($app);
        $route = '#init_syncdata';
    }
    $app_result = null;
    // init the KEY check class
    $CheckKey = new CheckKey($app);
    // default template
    $tpl = 'body';

    switch ($route) {
        case '/precheck.php':
        case '/info.php';
            // information for the CMS backward compatibility only
            $app_result = "This is not an WebsiteBaker or LEPTON CMS installation!";
            break;
        case '/phpinfo':
            // show phpinfo()
            phpinfo();
            break;
        case '/precheck':
        case '/systemcheck':
            // execute a systemcheck
            include SYNCDATA_PATH.'/systemcheck.php';
            exit();
        case '/setup':
            // force a setup
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $setup = new Setup($app);
            $app_result = $setup->exec();
            $initConfirmation = new confirmationSetup();
            $app_result = $initConfirmation->exec($app);
            break;
        case '/update':
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $ConfirmationUpdate = new confirmationUpdate();
            $app_result = $ConfirmationUpdate->exec($app);
            break;
        case '/uninstall':
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $ConfirmationUninstall = new confirmationUninstall();
            $app_result = $ConfirmationUninstall->exec($app);
            $Uninstall = new Uninstall($app);
            $app_result = $Uninstall->exec();
            break;
        case '/import_log':
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $ImportLog = new ImportOldLog();
            $app_result = $ImportLog->exec($app);
            break;
        case '/backup':
            // create a backup
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $backup = new Backup($app);
            $app_result = $backup->exec();
            break;
        case '/restore':
            // restore a backup to itself or to a client
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $restore = new Restore($app);
            $app_result = $restore->exec();
            break;
        case '/check':
            // check changes in the CMS but don't create an archive yet
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $check = new Check($app);
            $app_result = $check->exec();
            break;
        case '/create':
            // create the synchronize archive for the client
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $createArchive = new CreateSynchronizeArchive($app);
            $app_result = $createArchive->exec();
            break;
        case '/createsync':
            // check and create a synchronize archive
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            // check the system for changes
            $check = new Check($app);
            $check->exec();
            // create a archive for the client
            $createArchive = new CreateSynchronizeArchive($app);
            $app_result = $createArchive->exec();
            break;
        case '/sync':
            // synchronize the client with the server; this imports files
            // from the inbox folder
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $synchronizeClient = new SynchronizeClient($app);
            $app_result = $synchronizeClient->exec();
            break;
        case '/update_tool':
        case '/setup_tool':
            // install the admin-tool for the ConfirmationLog
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $SetupTool = new SetupTool();
            $app_result = $SetupTool->exec($app);
            break;
        case '/send_confirmations':
            // send confirmations to the outbox
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $Confirmations = new Confirmations($app);
            $app_result = $Confirmations->sendConfirmations();
            break;
        case '/get_confirmations':
            // send confirmations to the outbox
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $Confirmations = new Confirmations($app);
            $app_result = $Confirmations->getConfirmations();
            break;
        case '/upload_confirmations':
             if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            $synchronizeServer = new SynchronizeServer($app);
            $app_result = $synchronizeServer->receive();
            break;

// ---------- AUTOSYNC ROUTES --------------------------------------------------
        case '/autosync':
            // start a new autosync job
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            if($app['config']['sync']['enabled'] !== true) {
                $app_result = '- invalid request -';
                break;
            }
            $tpl = 'autosync';
            $app_result = $app['autosync']->sync();
            break;
        case '/autosync_exec':
            if($app['config']['sync']['enabled'] !== true) {
                $app_result = '- invalid request -';
                break;
            }
            if(!in_array($app['config']['sync']['role'], array('client','server'))) {
                $app_result = '- invalid request -';
                break;
            }
            // request AJAX call
            if(
                   !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'
            ) {
                $app_result = '- invalid request -';
                break;
            }
            if($app['config']['sync']['role'] == 'client') {
                $handler = new SynchronizeClient($app);
            } else {
                $handler = new SynchronizeServer($app);
            }
            $app_result = $handler->autosync();
            break;
        case '/poll':
            if($app['config']['sync']['enabled'] !== true) {
                $app_result = '- invalid request -';
                break;
            }
            if(!in_array($app['config']['sync']['role'], array('client','server'))) {
                $app_result = '- invalid request -';
                break;
            }
            // request AJAX call
            if(
                   !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'
            ) {
                $app_result = '- invalid request -';
                break;
            }
            $app_result = $app['autosync']->poll();
            break;
        case '/get_outbox':
            // get a list of files in the outbox; requires application/json
            // request header!
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            if($app['config']['sync']['role'] != 'server') {
                $app_result = '- invalid request -';
                break;
            }
            if (!$app['utils']->checkJSON()) {
                $app_result = '- invalid request -';
                break;
            }
            $synchronizeServer = new SynchronizeServer($app);
            $app_result = $synchronizeServer->exec();
            break;
        case '/get_sync':
            if (!$CheckKey->check()) {
                $app_result = $CheckKey->getKeyHint();
                break;
            }
            if(!isset($_GET['f']) || !strlen($_GET['f'])) {
                $app_result = '- invalid request -';
                break;
            }
            $synchronizeServer = new SynchronizeServer($app);
            $app_result = $synchronizeServer->pushfile();
            // do not print result, exit instead
            exit;
            break;



        case '#init_syncdata':
            // initialized SyncData2
            $app_result = 'SyncData has successfull initialized and also created a security key: <span class="security_key">'.
                $app['config']['security']['key'].'</span><br />'.
                'Please remember this key, you will need it to execute some commands and to setup cronjobs.';
            if ($app['config']['email']['active']) {
                // send the key also with email
                $message = \Swift_Message::newInstance('SyncData: Key generated')
                ->setFrom(CMS_SERVER_EMAIL, CMS_SERVER_NAME)
                ->setTo(CMS_SERVER_EMAIL)
                ->setBody('SyncData has created a new key: '.$app['config']['security']['key']);
                $app['mailer']->send($message);
                $app_result .= '<br />SyncData has also send the key to '.CMS_SERVER_EMAIL;
            }
            break;
        case '/':
        default:
            $app_result = '- nothing to do -';
            break;
    }

    $execution_time = sprintf('Execution time: %s seconds (max: %s)', number_format(microtime(true) - SYNCDATA_SCRIPT_START, 2), $app['config']['general']['max_execution_time']);
    $app['monolog']->addInfo($execution_time);
    $peak_usage = sprintf('Memory peak usage: %s MB (Limit: %s)', memory_get_peak_usage(true)/(1024*1024), $app['config']['general']['memory_limit']);
    $app['monolog']->addInfo($peak_usage);
    $app['monolog']->addInfo(sprintf('| <<< ----- >>> | %s | <<< ----- >>> |', date('c')));

    $result = is_null($app_result) ? 'Ooops, unexpected result ...' : $app_result;
    $result = sprintf(
        '<div class="result"><h1>%s</h1>%s</div>',
        $app['translator']->trans('Result'),
        $app['translator']->trans($result)
    );
    $Template = new Template();
    echo $Template->parse($app, $result, $tpl);
} catch (\Exception $e) {
    if (!isset($route)) {
        // SyncData2 may be not complete initialized
        throw new \Exception($e);
    }
    // regular Exception handling
    if ($app->offsetExists('monolog')) {
        $app['monolog']->addError(strip_tags($e->getMessage()), array('file' => $e->getFile(), 'line' => $e->getLine()));
    }

    $Template = new Template();
    $error = sprintf(
        '<div class="error"><h1>%s</h1><div class="message">%s</div><div class="logfile">%s</div></div>',
        ( ($app->offsetExists('translator') && $app->offsetExists('config')) ? $app['translator']->trans('Oooops ...') : 'Oooops ...' ),
        parseException($app,$e),
        ( $app->offsetExists('translator') ? $app['translator']->trans('Please check the logfile for further information!') : 'Please check the logfile for further information!' )
    );
    echo $Template->parse($app, $error);
}


/**
 * exception handler; allows to remove paths from error messages and show
 * optional stack trace
 **/
function parseException($app,$exception)
{
    $app['monolog']->addError('>>>>> handle exception: '.$exception->getMessage());
    if($app['config']['general']['debug'] === true)
    {
        $traceline = "#%s %s(%s): %s(%s)";
        $msg       = "Caught exception '%s' with message '%s'<br />"
                   . "<div style=\"font-size:smaller;width:80%%;margin:5px auto;text-align:left;\">"
                   . "in %s:%s<br />Stack trace:<br />%s<br />"
                   . "thrown in %s on line %s</div>"
                   ;
        $trace = $exception->getTrace();

        foreach ($trace as $key => $stackPoint)
        {
            $trace[$key]['args'] = array_map('gettype', $trace[$key]['args']);
        }

        // build tracelines
        $result = array();
        foreach ($trace as $key => $stackPoint)
        {
            $result[] = sprintf(
                $traceline,
                $key,
                ( isset($stackPoint['file']) ? $stackPoint['file'] : '-' ),
                ( isset($stackPoint['line']) ? $stackPoint['line'] : '-' ),
                $stackPoint['function'],
                implode(', ', $stackPoint['args'])
            );
        }

        // trace always ends with {main}
        $result[] = '#' . ++$key . ' {main}';
        // write tracelines into main template
        $msg = sprintf(
            $msg,
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            implode("<br />", $result),
            $exception->getFile(),
            $exception->getLine()
        );
    }
    else
    {
        // template
        $msg = "%s<br />";
        // filter message
        $message = $exception->getMessage();
        //SQLSTATE[42S02]: Base table or view not found: 1146 Table 'bc.cat_mod_wysiwyg_archive' doesn't exist
        preg_match('~SQLSTATE\[[^\]].+?\]:\s+(.*)~i', $message, $match);
        $msg     = sprintf(
            $msg,
            ( isset($match[1]) ? $match[1] : $message )
        );
        // replace paths
    }

    // obfuscate paths
    $msg = str_ireplace(
        array(
            SYNCDATA_PATH,
            str_replace('/','\\',SYNCDATA_PATH),
        ),
        array(
            '/abs/path/to',
            '/abs/path/to',
        ),
        $msg
    );
    
    return $msg;
}
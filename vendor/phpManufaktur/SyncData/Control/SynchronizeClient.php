<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\SyncData\Control;


use phpManufaktur\SyncData\Data\SynchronizeClient as SyncClient;
use phpManufaktur\SyncData\Control\Zip\unZip;
use phpManufaktur\SyncData\Data\General;
use phpManufaktur\SyncData\Control\Confirmations;

class SynchronizeClient
{

    protected $app = null;
    protected static $archive_id = null;
    protected static $error_msg  = NULL;

    /**
     * Constructor
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     *
     * @access public
     * @return
     **/
    public function autosync()
    {
        // ----- Step 0: Start new job -------------------------------------
        $this->app['monolog']->addInfo(
            'Start new AUTOSYNC Job', array('method' => __METHOD__, 'line' => __LINE__)
        );
        if(!$this->startJob())
        {
            return $this->app['translator']->trans(
                'Unable to start a new synchronization job!'
            );
        }
        return 'Synchronization in progress, please wait...';
    }   // end function autosync()

    /**
     *
     * @access public
     * @return
     **/
    public function autosync_exec()
    {
        $this->app['monolog']->addInfo(
            '----- AUTOSYNC EXEC -----', array('method' => __METHOD__, 'line' => __LINE__)
        );

        if(!isset($_GET['jobid']) || !$this->checkJob($_GET['jobid']))
        {
            $this->app['monolog']->addError(sprintf(
                'no such job: [%s]', $_GET['jobid']
            ));
            return '- invalid request -';
        }

        if(!defined('SYNCDATA_JOBID'))
            define('SYNCDATA_JOBID', $_GET['jobid']);

        if(!session_id()) session_start();

        $_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"] = 0;
        $_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"]    = 0;
        $_SESSION["JOB_".SYNCDATA_JOBID."_PROGRESS"] = array();

        // ----- Step 1: Check connection --------------------------------------
        if(!$this->checkSource()) // check connection
        {
            $this->logProgress(array(
                'message' => self::$error_msg,
                'success' => false
            ));
            exit();
        } else {
            $this->logProgress(array(
                'message' => 'Syncdata server successfully contacted',
            ));
        }

        // ----- Step 2: Export confirmations ----------------------------------
        if($this->app['config']['sync']['client']['steps']['export_confirmations'])
        {
            $Confirmations = new Confirmations($this->app);
            $result = $Confirmations->sendConfirmations();
            $this->app['monolog']->addInfo('>>> sendConfirmations: '.$result);
            $this->logProgress(array(
                'message' => $result,
            ));
        } else {
            $this->app['monolog']->addInfo('>>> sendConfirmations: disabled');
            $this->logProgress(array(
                'message' => 'skipped step 2 (sendConfirmations) because it is disabled',
            ));
        }

        // ----- Step 3: Upload local outbox -----------------------------------
        if($this->app['config']['sync']['client']['steps']['upload'])
        {
            $result = false;
            $this->app['monolog']->addInfo('>>>        sendOutbox: '.$result);
            $this->logProgress(array(
                'message' => $result,
            ));
        } else {
            $this->app['monolog']->addInfo('>>>        sendOutbox: disabled');
            $this->logProgress(array(
                'message' => 'skipped step 3 (sendOutbox) because it is disabled',
            ));
        }

        // ----- Step 4: Download server outbox --------------------------------
        if($this->app['config']['sync']['client']['steps']['download'])
        {
            $files = $this->getOutboxContents(); // get file list
            if(isset($files) && count($files))   // if there are any files...
            {
                $this->app['monolog']->addInfo(
                    sprintf('>>>          download: %d files found in outbox',count($files))
                );

                $this->logProgress(array(
                    'message' => '%d files found in outbox',
                    'param'   => count($files),
                ));

                // ...check if they're already there
                for($i=(count($files)-1);$i>=0;$i--)
                {
                    $file = $files[$i];
                    if(file_exists($this->app['utils']->sanitizePath(sprintf('%s/inbox/%s', SYNCDATA_PATH, $file))))
                    {
                        $this->app['monolog']->addInfo(
                            sprintf('>>>          download: skipping file %s (already there)', $file)
                        );
                        unset($files[$i]);
                    }
                }
            }
            // anything left?
            if(is_array($files) && count($files)) {
                $this->app['monolog']->addInfo(
                    sprintf('>>>          download: %d files to download',count($files))
                );
                $this->logProgress(array(
                    'message' => 'found %d new files',
                    'param'   => count($files),
                ));
                foreach(array_values($files) as $file) {
                    $this->getFile($file);
                    $this->getFile(str_ireplace('zip','md5',$file));
                }
            }

            $this->logProgress(array(
                'message' => 'downloaded %d files',
                'param'   => count($files),
            ));

        } else {
            $this->app['monolog']->addInfo('>>>          download: disabled');
            $this->logProgress(array(
                'message' => 'skipped step 4 (download) because it is disabled',
                'step'    => 4
            ));
        }

        // ----- Step 5: Import ------------------------------------------------
        if($this->app['config']['sync']['client']['steps']['import'])
        {
            $files = $this->listInbox();
            if(is_array($files) && count($files))
            {
                $this->logProgress(array(
                    'message' => 'import started (%d files in inbox)',
                    'param'   => count($files),
                ));
                $local_ch   = $this->init_client();
                $file_count = 0;
                $err_count  = 0;
                foreach(array_values($files) as $file)
                {
                    $this->app['monolog']->addInfo(sprintf(
                        '>>>            import: [%s]',$file
                    ));
                    $this->logProgress(array(
                        'message' => 'importing file: %s',
                        'param'   => $file
                    ));
                    // execute local URL
                    $url = $this->app['config']['CMS']['CMS_URL'].'/syncdata/sync?key='.$this->app['config']['security']['key'];
                    curl_setopt($local_ch,CURLOPT_URL,$url);
                    $result = curl_exec($local_ch);
                    if(curl_getinfo($local_ch,CURLINFO_HTTP_CODE) != 200)
                    {
                        $this->app['monolog']->addError(sprintf(
                            '>>> !!!!! import error! (URL: [%s] - Status: [%s])',
                            $url,curl_getinfo($local_ch,CURLINFO_HTTP_CODE)
                        ));
                        $this->logProgress(array(
                            'message' => sprintf(
                                '>>> !!!!! import error! (URL: [%s] - Status: [%s])',
                                $url,curl_getinfo($local_ch,CURLINFO_HTTP_CODE)
                            )
                        ));
                        break;
                    }
                    $dom = new \DOMDocument('1.0', 'UTF-8');
                    libxml_use_internal_errors(true);
                    $dom->loadHTML($result);
                    if($dom->getElementById('content')->hasChildNodes())
                    {
                        $is_error = false;
                        $message  = NULL;
                        $nodes    = $dom->getElementsByTagName("div");
                        foreach ($nodes as $element)
                        {
                            $class = $element->getAttribute("class");
                            if($class=='error')
                            {
                                $is_error = true;
                                $err_count++;
                            }
                            if($class=='message')
                            {
                                $message = $element->textContent;
                                $this->logProgress(array(
                                    'message' => $message,
                                    'success' => ( $is_error ? false : true )
                                ));
                            }
                        }
                    }
/*
<div id="content">
        <div class="error"><h1>Oooops ...</h1><div class="message">
*/
                    $file_count++;
                }
                $this->logProgress(array(
                    'message' => 'import done (%d files)',
                    'param'   => $file_count
                ));
                if($err_count)
                {
                    $this->logProgress(array(
                        'message' => '<span class="error">PLEASE NOTE: There were %d import errors!</span>',
                        'param'   => $err_count,
                        'success' => false,
                    ));
                }
            }
        }

        // ----- Step 6: Finished ------------------------------------------------
        $this->logProgress(array(
            'message'  => '----- '.$this->app['translator']->trans('Job finished').' -----',
            'success'  => false,
            'finished' => true,
        ));
    }

    /**
     *
     * @access public
     * @return
     **/
    public function autosync_poll()
    {
        $this->app['monolog']->addInfo(
            '----- AUTOSYNC POLL -----', array('method' => __METHOD__, 'line' => __LINE__)
        );

session_start();

        header('Content-Type: application/json');

        if(
            ( !isset($_GET['jobid'])  || !$this->checkJob($_GET['jobid']) )
        ) {
            $this->app['monolog']->addError(sprintf(
                'no such job: [%s]', $_GET['jobid']
            ));
            echo json_encode(array(
                'success' => false,
                'message' => '- invalid request -'
            ),1);
            exit();
        }

        $success = (
            (
                   isset($_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"])
                &&       $_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"]
            )
            ? false : true
        );
        $finished = (
            (
                   isset($_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"])
                &&       $_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"]
            )
            ? true : false
        );
        echo json_encode(array(
            'success'  => $success,
            'message'  => implode("<br />",$_SESSION["JOB_".SYNCDATA_JOBID."_PROGRESS"]),
            'finished' => $finished
        ),1);
        exit();
    }

    /**
     *
     * @access protected
     * @return
     **/
    protected function checkJob($jobid)
    {
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/autosync_job_%s', SYNCDATA_PATH, $jobid)
        );
        if(!file_exists($file)) {
            return false;
        }
        define('SYNCDATA_JOBID', $jobid);
        return true;
    }   // end function checkJob()

    /**
     * ----- Schritt 1: Verbindung zum Quellsystem pruefen -----
     *
     * @access protected
     * @return
     **/
    protected function checkSource()
    {
        try {
            $this->app['monolog']->addDebug(sprintf(
                '>>> checking source url [%s]', $this->app['config']['sync']['server']['url']
            ), array('method' => __METHOD__, 'line' => __LINE__));

            $remote_ch = $this->init_client(true); // maybe with proxy
            curl_setopt($remote_ch, CURLOPT_URL, $this->app['config']['sync']['server']['url']);
            $result = curl_exec($remote_ch);
            if(curl_getinfo($remote_ch,CURLINFO_HTTP_CODE) != 200)
            {
                self::$error_msg = sprintf(
                    $this->app['translator']->trans(
                        'Unable to connect to server! (Code: %s)'
                    ),
                    curl_getinfo($remote_ch,CURLINFO_HTTP_CODE)
                );
                $this->app['monolog']->addError(
                    self::$error_msg, array('method' => __METHOD__, 'line' => __LINE__)
                );
                $this->logProgress(self::$error_msg);
                return false;
            }
        } catch (\Exception $e) {
            self::$error_msg = $e->getMessage();
            $this->app['monolog']->addError($e->getMessage());
            return false;
        }
        return true;
    }   // end function checkSource()

    /**
     *
     * @access protected
     * @return
     **/
    protected function cleanupJobs()
    {
        // clean up old job files (older than 7 days)
        $path  = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/', SYNCDATA_PATH)
        );
        $files = glob($path.'/autosync_job_*');
        if(count($files))
            foreach($files as $f)
                if(filemtime($f)<(time()-24*60*60*7))
                    unlink($f);
    }   // end function cleanupJobs()
    

    /**
     *
     * @access protected
     * @return
     **/
    protected function getFile($file)
    {
        $ch = $this->init_client(true);
        curl_setopt($ch, CURLOPT_URL, sprintf(
            '%s/syncdata/get_sync?key=%s&f=%s',
            $this->app['config']['sync']['server']['url'],
            $this->app['config']['sync']['server']['key'],
            $file
        ));
        $data = curl_exec($ch);
        if(curl_error($ch))
        {
            print json_encode(array('success'=>false,'message'=>trim(curl_error($ch))));
            exit();
        }
        $fh = fopen($this->app['utils']->sanitizePath(sprintf('%s/inbox/%s', SYNCDATA_PATH, $file)),'w');
        fwrite($fh,$data);
        fclose($fh);
    }   // end function getFile()

    /**
     *
     * @access protected
     * @return
     **/
    protected function getOutboxContents()
    {
        try {
            $this->app['monolog']->addDebug(
                '>>> get outbox file list', array('method' => __METHOD__, 'line' => __LINE__)
            );
            $remote_ch = $this->init_client(true,array('Accept: application/json')); // maybe with proxy
            curl_setopt($remote_ch, CURLOPT_URL, $this->app['config']['sync']['server']['url'].'/syncdata/get_outbox?key='.$this->app['config']['sync']['server']['key']);
            $response  = curl_exec($remote_ch);
            if( curl_getinfo($remote_ch,CURLINFO_HTTP_CODE) != 200 )
            {
                $message = sprintf(
                    $this->app['translator']->trans(
                        'Unable to connect to server! (Code: %s)'
                    ), curl_getinfo($remote_ch,CURLINFO_HTTP_CODE)
                );
                $this->app['monolog']->addError($message, array('method' => __METHOD__, 'line' => __LINE__));
                print json_encode(array('message'=>$message,'success'=>false));
                exit();
            }
            $result = json_decode($response);
            $this->app['monolog']->addDebug(sprintf(
                '>>> >>> found [%d] files', count($result)
            ), array('method' => __METHOD__, 'line' => __LINE__));
            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }   // end function getOutboxContents()
    

    /**
     * Initialisierung curl
     **/
    protected function init_client($is_remote=false, $add_headers=null)
    {
        $headers = array(
            'User-Agent: php-curl',
        );

        if(is_array($add_headers) && count($add_headers))
            $headers = array_merge($headers,$add_headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
// NUR ZUM TESTEN
        if($is_remote)
        {
#            curl_setopt($ch, CURLOPT_PROXY, 'proxy.materna.de');
#            curl_setopt($ch, CURLOPT_PROXYPORT, '8080');
        }
// NUR ZUM TESTEN
        return $ch;
    }

    /**
     *
     * @access protected
     * @return
     **/
    protected function listInbox()
    {
        $inbox_path = $this->app['utils']->sanitizePath(sprintf('%s/inbox/', SYNCDATA_PATH));
        return $this->app['utils']->getFiles($inbox_path,'inbox');
    }   // end function listInbox()
    
    /**
     * Process the tables for the synchronization
     *
     * @throws \Exception
     */
    protected function processTables()
    {
        if (!file_exists(TEMP_PATH.'/sync/synchronize/tables.json')) {
            throw new \Exception("tables.json does not exists!");
        }
        if (false === ($tables = json_decode(@file_get_contents(TEMP_PATH.'/sync/synchronize/tables.json'), true))) {
            throw new \Exception("Can't decode the tables.json file!");
        }
        if(!(json_last_error() == JSON_ERROR_NONE)) {
            $this->app['monolog']->addError($this->app['utils']->handleJSONError());
            throw new \Exception("Can't decode the tables.json file!");
        }
        $General = new General($this->app);
        foreach ($tables as $table) {
            switch ($table['action']) {
                case 'INSERT':
                    $exists = $General->getRowContent(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    if (isset($exists[$table['index_field']])) {
                        $checksum = $General->getRowContentChecksum(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                        if ($checksum !== $table['checksum']) {
                            $this->app['monolog']->addError(sprintf("Table %s with %s => %s already exists, but the checksum differ! Please check the table!",
                                $table['table_name'], $table['index_field'], $table['index_id']),
                                array('method' => __METHOD__, 'line' => __LINE__));
                        }
                        else {
                            $this->app['monolog']->addInfo(sprintf("Table %s with %s => %s already exists, skipped!",
                                $table['table_name'], $table['index_field'], $table['index_id']),
                                array('method' => __METHOD__, 'line' => __LINE__));
                        }
                        continue;
                    }
                    if (false === ($data = json_decode($this->app['utils']->unsanitizeText($table['content']), true))) {
                        throw new \Exception("Problem decoding json content!");
                    }
                    if(!(json_last_error() == JSON_ERROR_NONE)) {
                        $this->app['monolog']->addError($this->app['utils']->handleJSONError());
                        throw new \Exception("Problem decoding json content!");
                    }
                    $General->insert(CMS_TABLE_PREFIX.$table['table_name'], $data);
                    $checksum = $General->getRowContentChecksum(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    if ($checksum !== $table['checksum']) {
                        $this->app['monolog']->addError(sprintf("Table %s INSERT %s => %s successfull, but the checksum differ! Please check the table!",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                    }
                    else {
                        $this->app['monolog']->addInfo(sprintf("Table %s INSERT %s => %s successfull",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                    }
                    break;
                case 'UPDATE':
                    $exists = $General->getRowContent(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    if (!isset($exists[$table['index_field']])) {
                        $this->app['monolog']->addError(sprintf("Table %s with %s => %s does not exists for UPDATE! Please check the table!",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                        continue;
                    }
                    $checksum = $General->getRowContentChecksum(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    if ($checksum === $table['checksum']) {
                        $this->app['monolog']->addInfo(sprintf("Table %s with %s => %s skipped UPDATE, the actual content has the same checksum!",
                                $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                        continue;
                    }
// ----- NOTE: json_decode() NEVER returns false on error!       -----
// ----- But also, NULL is not an error (given data can be NULL) -----
// ----- So we have to check json_last_error() to be sure        -----
// -----                   B. Martinovic                         -----
// uncomment following 3 lines for additional debug info
//$this->app['monolog']->addInfo('original data:'.$table['content']);
//$this->app['monolog']->addInfo('decoded data:'.$this->app['utils']->unsanitizeText($table['content']));
//$this->app['monolog']->addInfo('json result code: '.json_last_error());

                    if (false === ($data = json_decode($this->app['utils']->unsanitizeText($table['content']), true))) {
                        throw new \Exception("Problem decoding json content!");
                    }

                    if(!(json_last_error() == JSON_ERROR_NONE)) {
                        $this->app['monolog']->addError($this->app['utils']->handleJSONError());
                        throw new \Exception(sprintf("Can't update the data for table %s - invalid json data!",$table['table_name']));
                    }

                    $General->update(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']), $data);
                    $checksum = $General->getRowContentChecksum(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    if ($checksum !== $table['checksum']) {
                        $this->app['monolog']->addError(sprintf("Table %s UPDATE %s => %s successfull, but the checksum differ! Please check the table!",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                    }
                    else {
                        $this->app['monolog']->addInfo(sprintf("Table %s UPDATE %s => %s successfull",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                            array('method' => __METHOD__, 'line' => __LINE__));
                    }
                    break;
                case 'DELETE':
                    $General->delete(CMS_TABLE_PREFIX.$table['table_name'], array($table['index_field'] => $table['index_id']));
                    $this->app['monolog']->addInfo(sprintf("Table %s DELETE %s => %s executed.",
                            $table['table_name'], $table['index_field'], $table['index_id']),
                        array('method' => __METHOD__, 'line' => __LINE__));
                    break;
            }
        }

    }

    /**
     * @todo missing some validations and checks!
     * @throws \Exception
     */
    protected function processFiles()
    {
        if (!file_exists(TEMP_PATH.'/sync/synchronize/files.json')) {
            throw new \Exception("files.json does not exists!");
        }
        if (false === ($files = json_decode(@file_get_contents(TEMP_PATH.'/sync/synchronize/files.json'), true))) {
            throw new \Exception("Can't decode the files.json file!");
        }
        if(!(json_last_error() == JSON_ERROR_NONE)) {
            $this->app['monolog']->addError($this->app['utils']->handleJSONError());
            throw new \Exception("Can't decode the files.json file!");
        }
        foreach ($files as $file) {
            if ($file['action'] == 'DELETE') {
                if (file_exists(CMS_PATH.$file['relative_path'])) {
                    @unlink(CMS_PATH.$file['relative_path']);
                    $this->app['monolog']->addInfo("Deleted file ".$file['relative_path'],
                        array('method' => __METHOD__, 'line' => __LINE__));
                }
            }
            else {
                // CHANGED or NEW
                if (file_exists(TEMP_PATH.'/sync/synchronize/CMS'.$file['relative_path'])) {
                    // check if destination folder exists
                    $dest_path = pathinfo(CMS_PATH.$file['relative_path'],PATHINFO_DIRNAME);
                    if (!is_dir($dest_path)) {
                        if(!$this->app['utils']->createRecursive($dest_path)) {
                            $this->app['monolog']->addError("Can't create destination directory ".$dest_path,
                                array('method' => __METHOD__, 'line' => __LINE__));
                        }
                    }
                    if (!@copy(TEMP_PATH.'/sync/synchronize/CMS'.$file['relative_path'],
                        CMS_PATH.$file['relative_path'])) {
                        $this->app['monolog']->addError("Can't copy file to ".$file['relative_path'],
                            array('method' => __METHOD__, 'line' => __LINE__));
                    }
                    else {
                        if ($file['action'] == 'NEW') {
                            $this->app['monolog']->addInfo("Inserted NEW file ".$file['relative_path'],
                                array('method' => __METHOD__, 'line' => __LINE__));
                        }
                        else {
                            $this->app['monolog']->addInfo("CHANGED file ".$file['relative_path'],
                                array('method' => __METHOD__, 'line' => __LINE__));
                        }
                    }
                }
                else {
                    $this->app['monolog']->addError("MISSING file ".$file['relative_path']." in the SYNC archive!",
                        array('method' => __METHOD__, 'line' => __LINE__));
                }
            }
        }
    }

    /**
     * start a new job
     *
     * @access protected
     * @return
     **/
    protected function startJob()
    {
        // clean up old jobs
        $this->cleanupJobs();

        // generate JobID
        $jobid = $this->app['utils']->generatePassword(15,false,'lud');
        define('SYNCDATA_JOBID', $jobid);

        if($this->logProgress(
            array(
                'message' => '----- '.$this->app['translator']->trans('Job started').' ('.date('c').') -----',
            )
        )) {
            return true;
        } else {
            return false;
        }

        ob_end_clean();
        header("Connection: close");
        ignore_user_abort(true); // just to be safe
        ob_start();
        header('Content-type: application/json');
        echo json_encode(array('jobid'=>$jobid));
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush(); // Strange behaviour, will not work
        flush();        // Unless both are called !
    }   // end function startJob()

    /**
     * create / update job
     *
     * @param  string $message
     * @return boolean
     **/
    protected function logProgress($data)
    {
        // get file path
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/autosync_job_%s', SYNCDATA_PATH, SYNCDATA_JOBID)
        );

        // translate and replace params (if any)
        $data['message'] = $this->app['translator']->trans($data['message']);
        if(isset($data['param'])) $data['message'] = sprintf($data['message'],$data['param']);

        // add date to message
        $data['message'] = sprintf('[%10s] %s', date('c'), $data['message']);
        $_SESSION["JOB_".SYNCDATA_JOBID."_PROGRESS"][] = $data['message'];

        if(isset($data['finished']))
        {
            $_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"] = true;
        }

        $mode = 'a';
        if(!file_exists($file)) $mode = 'w';

        $fh = fopen($file,$mode); // create new / overwrite
        if(!$fh || !is_resource($fh))
        {
            $this->app['monolog']->addInfo('Schreiben in Jobdatei fehlgeschlagen!');
            return false;
        }
        fwrite($fh,$data['message']."\n");
        fclose($fh);

        if(isset($data['success']) && $data['success'] === false)
        {
            $fh = fopen($file.'.error','w');
            fwrite($fh,$data['message']);
            fclose($fh);
        }

        return true;
    }

    /**
     * Main routine to exec the synchronization
     *
     * @throws \Exception
     * @return string
     */
    public function exec()
    {
        try {
            // start SYNC
            $this->app['monolog']->addInfo('Start SYNC', array('method' => __METHOD__, 'line' => __LINE__));

            $SyncClient = new SyncClient($this->app);
            $archive_id = $SyncClient->selectLastArchiveID();
            self::$archive_id = $archive_id+1;

            $zip_path = sprintf('%s/inbox/syncdata_synchronize_%05d.zip', SYNCDATA_PATH, self::$archive_id);
            $md5_path = sprintf('%s/inbox/syncdata_synchronize_%05d.md5', SYNCDATA_PATH, self::$archive_id);
            $md5_archive_path = sprintf('%s/data/synchronize/syncdata_synchronize_%05d.md5', SYNCDATA_PATH, self::$archive_id);
            $zip_archive_path = sprintf('%s/data/synchronize/syncdata_synchronize_%05d.zip', SYNCDATA_PATH, self::$archive_id);
            if (file_exists($zip_path) && file_exists($md5_path)) {
                // ok - expected archive is there, proceed
                if (false === ($md5_origin = file_get_contents($md5_path))) {
                    $result = "Can't read the MD5 checksum file for the SYNC!";
                    $this->app['monolog']->addError($result, array('method' => __METHOD__, 'line' => __LINE__));
                    return $result;
                }
                if (md5_file($zip_path) !== $md5_origin) {
                    $result = "The checksum of the SYNC archive is not equal to the MD5 checksum file value!";
                    $this->app['monolog']->addError($result, array('method' => __METHOD__, 'line' => __LINE__));
                    return $result;
                }
                // check the TEMP directory
                if (file_exists(TEMP_PATH.'/sync') && !$this->app['utils']->rrmdir(TEMP_PATH.'/sync')) {
                    throw new \Exception(sprintf("Can't delete the directory %s", TEMP_PATH.'/sync'));
                }
                if (!file_exists(TEMP_PATH.'/sync') && (false === @mkdir(TEMP_PATH.'/sync', 0755, true))) {
                    throw new \Exception("Can't create the directory ".TEMP_PATH."/sync");
                }
                // unzip the archive
                $this->app['monolog']->addInfo("Start unzipping $zip_path", array('method' => __METHOD__, 'line' => __LINE__));
                $unZip = new unZip($this->app);
                $unZip->setUnZipPath(TEMP_PATH.'/sync');
                $unZip->extract($zip_path);
                $this->app['monolog']->addInfo("Unzipped $zip_path", array('method' => __METHOD__, 'line' => __LINE__));

                // process the tables
                $this->processTables();

                // process the files
                $this->processFiles();

                // ok - nearly all done
                $data = array(
                    'archive_id' => self::$archive_id,
                    'action' => 'SYNC'
                );
                $SyncClient->insert($data);

                // move the files from the /inbox to /data/synchronize
                if (!file_exists(SYNCDATA_PATH.'/data/synchronize/.htaccess') || !file_exists(SYNCDATA_PATH.'/data/synchronize/.htpasswd')) {
                    $this->app['utils']->createDirectoryProtection(SYNCDATA_PATH.'/data/synchronize');
                }
                if (!@rename($md5_path, $md5_archive_path)) {
                    $this->app['monolog']->addError("Can't save the MD5 checksum file in /data/synchronize!",
                        array('method' => __METHOD__, 'line' => __LINE__));
                }
                if (!@rename($zip_path, $zip_archive_path)) {
                    $this->app['monolog']->addError("Can't save the synchronize archive in /data/synchronize!",
                        array('method' => __METHOD__, 'line' => __LINE__));
                }

                // delete the temp directories
                $directories = array('/backup', '/restore', '/sync', '/unzip');
                foreach ($directories as $directory) {
                    if (file_exists(TEMP_PATH.$directory) && (true !== $this->app['utils']->rrmdir(TEMP_PATH.$directory))) {
                        throw new \Exception(sprintf("Can't delete the directory %s", TEMP_PATH.directory));
                    }
                }
                $this->app['monolog']->addInfo("SYNC finished!", array('method' => __METHOD__, 'line' => __LINE__));
            }
            else {
                $result = sprintf('Missing archive file %s and checksum file %s in the inbox.', basename($zip_path), basename($md5_path));
                $this->app['monolog']->addInfo($result, array('method' => __METHOD__, 'line' => __LINE__));
                return $result;
            }

            return 'SYNC finished';
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }
}

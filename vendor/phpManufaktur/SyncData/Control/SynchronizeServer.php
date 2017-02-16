<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.info>
 * @link https://github.com/phpManufakturHeirs/SyncData2/
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @copyright 2017 Bianka Martinovic <blackbird@webbird.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\SyncData\Control;

use phpManufaktur\SyncData\Data\SynchronizeClient as SyncClient;
use phpManufaktur\SyncData\Control\Zip\Zip;
use phpManufaktur\SyncData\Data\General;

class SynchronizeServer
{

    protected $app = null;

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
     * autosync handler; prints progress to job file
     *
     * @access public
     * @return void
     **/
    public function autosync()
    {
        $this->app['monolog']->addInfo(
            '----- SynchronizeServer AUTOSYNC -----', array('method' => __METHOD__, 'line' => __LINE__)
        );

        if(!isset($_GET['jobid']) || !$this->app['autosync']->checkJob($_GET['jobid']))
        {
            $this->app['monolog']->addError(sprintf(
                'no such job: [%s]', $_GET['jobid']
            ));
            return '- invalid request -';
        }

        ob_end_clean();
        header("Connection: close");
        ignore_user_abort(true); // just to be safe
        ob_start();
        header('Content-type: application/json');
        echo json_encode(array('success'=>true));
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush(); // Strange behaviour, will not work
        flush();        // Unless both are called !

        if(!defined('SYNCDATA_JOBID'))
            define('SYNCDATA_JOBID', $_GET['jobid']);

        if(!session_id()) session_start();

        $_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"]   = 0;
        $_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"]      = 0;
        $_SESSION["JOB_".SYNCDATA_JOBID."_ERRORCOUNT"] = 0;
        $_SESSION["JOB_".SYNCDATA_JOBID."_PROGRESS"]   = array();

        // ----- Step 1: Check connection --------------------------------------
        if(!$this->app['autosync']->checkConnection('client')) // check connection
        {
            $_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"] = 1;
            $_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"]    = 1;
            exit();
        } else {
            $this->app['autosync']->logProgress(array(
                'message' => 'Syncdata server successfully contacted',
            ));
        }

        // ----- Step 2: Upload local outbox -----------------------------------
        if($this->app['config']['sync']['server']['steps']['upload'])
        {
            $this->app['autosync']->logProgress(array(
                'message' => 'uploading outbox',
            ));
            $this->app['autosync']->pushOutbox('client');
        } else {
            $this->app['monolog']->addInfo('>>>        sendOutbox: disabled');
            $this->app['autosync']->logProgress(array(
                'message' => 'skipped step 2 (sendOutbox) because it is disabled',
            ));
        }

        // ----- Step 3: Import ------------------------------------------------
        if($this->app['config']['sync']['server']['steps']['import'])
        {
            $this->app['autosync']->logProgress(array(
                'message' => 'triggering import',
            ));
            $remote_ch = $this->app['utils']->init_client(true); // maybe with proxy
            curl_setopt($remote_ch, CURLOPT_URL, sprintf(
                '%s/syncdata/sync?key=%s',
                $this->app['config']['sync']['client']['url'],
                $this->app['config']['sync']['client']['key']
            ));
            $response = curl_exec($remote_ch);
            if( curl_getinfo($remote_ch,CURLINFO_HTTP_CODE) != 200 )
            {
                $message = sprintf(
                    $this->app['translator']->trans(
                        'Unable to connect to server! (Code: %s)'
                    ), curl_getinfo($remote_ch,CURLINFO_HTTP_CODE)
                );
                $this->app['monolog']->addError($message, array('method' => __METHOD__, 'line' => __LINE__));
                $this->app['autosync']->logProgress(array(
                    'message' => $message,
                    'success' => false
                ));
            } else {
                $this->app['monolog']->addInfo('----- IMPORT CURL RESPONSE ----- '.print_r($response,1));

                list($is_error,$message) = $this->app['utils']->parseResponse($response);
                if($is_error) $_SESSION["JOB_".SYNCDATA_JOBID."_ERRORCOUNT"]++;
                $this->app['autosync']->logProgress(array(
                    'message' => $message,
                    'success' => ( $is_error ? false : true )
                ));
            }
        } else {
            $this->app['monolog']->addInfo('>>>        import: disabled');
            $this->app['autosync']->logProgress(array(
                'message' => 'skipped step 3 (import) because it is disabled',
            ));
        }

        // ----- Step 4: Finished ------------------------------------------------
        $this->app['autosync']->logProgress(array(
            'message'  => '<span class="ready">----- '.$this->app['translator']->trans('Job finished').' -----</span>',
            'success'  => true,
            'finished' => true,
        ));
    }   // end function autosync()

    public function exec()
    {
        try {
            $this->app['monolog']->addInfo(
                'Start SynchronizeServer EXEC',
                array('method' => __METHOD__, 'line' => __LINE__)
            );
            $zip_files = $this->listOutbox();
            echo json_encode($zip_files,true);
            exit;
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }

    /**
     * push a file from the outbox to the client
     **/
    public function pushfile()
    {
        try {
            $this->app['monolog']->addInfo(
                'Start SynchronizeServer PUSH', array('method' => __METHOD__, 'line' => __LINE__)
            );
            $zip_files = $this->listOutbox(true); // get files in outbox
            $this->app['monolog']->addDebug(
                print_r( $zip_files,1 )
            );
            $this->app['monolog']->addInfo(sprintf(
                'requested file: [%s]', $_GET['f']
            ));
            if(in_array($_GET['f'],$zip_files)) {
                $path = $this->app['utils']->sanitizePath(sprintf('%s/outbox/%s', SYNCDATA_PATH, $_GET['f']));
                $this->app['monolog']->addDebug(sprintf(
                    'check if file exists: %s', $path
                ));
                if(file_exists($path)) {
                    $this->app['monolog']->addInfo(sprintf(
                        'sending file [%s]', $path
                    ));
                    $fh = fopen($path,'r');
                    if (isset($_SERVER['HTTP_USER_AGENT']) && strstr($_SERVER['HTTP_USER_AGENT'], "MSIE"))
                	{
                	        header('Content-Type: "application/octet-stream"');
                	        header('Content-Disposition: attachment; filename="'.basename($path).'"');
                	        header('Expires: 0');
                	        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                	        header("Content-Transfer-Encoding: binary");
                	        header('Pragma: public');
                	        header("Content-Length: ".filesize($path));
                	}
                	else
                	{
                	        header('Content-Type: "application/octet-stream"');
                	        header('Content-Disposition: attachment; filename="'.basename($path).'"');
                	        header("Content-Transfer-Encoding: binary");
                	        header('Expires: 0');
                	        header('Pragma: no-cache');
                	        header("Content-Length: ".filesize($path));
                	}
                	fpassthru($fh);
                	fclose($fh);
                } else {
                    $this->app['monolog']->addInfo(
                        '!!!no such file!!!'
                    );
                }
            } else {
                $this->app['monolog']->addInfo(
                    '!!!no such file!!!'
                );
            }
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }

    /**
     *
     * @access public
     * @return
     **/
    public function receive()
    {
        $this->app['autosync']->receiveFile();
    }   // end function receive()

    /**
     *
     * @access protected
     * @return
     **/
    protected function listOutbox($with_md5=false)
    {
        $outbox_path = $this->app['utils']->sanitizePath(sprintf('%s/outbox/', SYNCDATA_PATH));
        return $this->app['utils']->getFiles($outbox_path,'outbox',$with_md5);
    }   // end function listOutbox()

}
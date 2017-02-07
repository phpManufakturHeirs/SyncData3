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

    public function exec()
    {
        try {
            $this->app['monolog']->addInfo('Start SynchronizeServer EXEC', array('method' => __METHOD__, 'line' => __LINE__));
            $zip_files = $this->listOutbox();
            echo json_encode($zip_files,true);
            exit;
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }

    public function push()
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
     * @access protected
     * @return
     **/
    protected function listOutbox($with_md5=false)
    {
        $outbox_path = $this->app['utils']->sanitizePath(sprintf('%s/outbox/', SYNCDATA_PATH));
        return $this->app['utils']->getFiles($outbox_path,'outbox',$with_md5);
    }   // end function listOutbox()

}
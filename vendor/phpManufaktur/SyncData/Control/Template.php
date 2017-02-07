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

class Template
{
    public function parse(Application $app, $result, $tpl='body')
    {
        $html = file_get_contents(SYNCDATA_PATH.'/vendor/phpManufaktur/SyncData/Template/default/'.$tpl.'.html');

        $html = str_ireplace(
            array(
                '{{ content }}',
                '{{ version }}',
                '{{ memory.peak }}',
                '{{ memory.limit }}',
                '{{ time.total }}',
                '{{ time.max }}',
                '{{ route }}',
                '{{ syncdata_url }}',
                '{{ jobid }}',
                '{{ sessid }}',
                '{{ interval }}',
                '{{ maxwait }}',
                '{{ maxwait_error }}',
            ), array(
                $result,
                SYNCDATA_VERSION,
                memory_get_peak_usage(true)/(1024*1024),
                $app['config']['general']['memory_limit'],
                number_format(microtime(true) - SYNCDATA_SCRIPT_START, 2),
                $app['config']['general']['max_execution_time'],
                SYNCDATA_ROUTE,
                SYNCDATA_URL,
                ( $tpl == 'autosync' ? ( defined('SYNCDATA_JOBID') ? SYNCDATA_JOBID : 'unknown' ) : '' ),
                ( $tpl == 'autosync' ? session_id()                                               : '' ),
                ( $tpl == 'autosync' ? $app['config']['sync']['client']['interval']               : '' ),
                ( $tpl == 'autosync' ? $app['config']['sync']['client']['maxexecutiontime']       : '' ),
                ( $tpl == 'autosync' ? $app['translator']->trans('Aktualisierung fehlgeschlagen. Die maximale Wartezeit wurde überschritten.') : '' ),
            ), $html);

        return $html;
    }

}

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
        $find = array(
            '{{ content }}',
            '{{ version }}',
            '{{ memory.peak }}',
            '{{ memory.limit }}',
            '{{ time.total }}',
            '{{ time.max }}',
            '{{ route }}',
            '{{ syncdata_url }}',
            '{{ skin }}',
        );
        $replace = array(
            $result,
            SYNCDATA_VERSION,
            memory_get_peak_usage(true)/(1024*1024),
            $app['config']['general']['memory_limit'],
            number_format(microtime(true) - SYNCDATA_SCRIPT_START, 2),
            $app['config']['general']['max_execution_time'],
            SYNCDATA_ROUTE,
            SYNCDATA_URL,
            $app['config']['general']['skin'],
        );

        if($tpl=='autosync') {
            $role = $app['config']['sync']['role'];
            $find = array_merge($find,array(
                '{{ jobid }}',
                '{{ sessid }}',
                '{{ interval }}',
                '{{ maxwait }}',
                '{{ maxwait_secs }}',
                '{{ maxwait_error }}',
                '{{ err_text }}',
            ));
            $replace = array_merge($replace,array(
                ( defined('SYNCDATA_JOBID') ? SYNCDATA_JOBID : 'unknown' ),
                session_id(),
                $app['config']['sync'][$role]['interval'],
                $app['config']['sync'][$role]['maxexecutiontime'],
                ( $app['config']['sync'][$role]['maxexecutiontime'] / 1000 ),
                $app['translator']->trans('Aktualisierung fehlgeschlagen. Die maximale Wartezeit wurde überschritten.'),
                '<i class="icono-exclamation"></i> '.$app['translator']->trans('Please note: There were <span id="errcnt"></span> Errors. Please check the log for details.')
            ));
        }

        $html = str_ireplace(
            $find,
            $replace,
            $html
        );

        // remove missing
        $html = preg_replace( '~{{ .* }}~','',$html);

        return $html;
    }

}

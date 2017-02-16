<?php

/**
 * SyncData2
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://kit2.phpmanufaktur.de/contact
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

if ('á' != "\xc3\xa1") {
    // the language files must be saved as UTF-8 (without BOM)
    throw new \Exception('The language file ' . __FILE__ . ' is damaged, it must be saved UTF-8 encoded!');
}

return array(
    '%d files in the outbox'
        => '%d Dateien in der Outbox',
    '- invalid request -'
        => '- ungültige Anfrage -',
    'downloaded %d files'
        => '%d Dateien heruntergeladen',
    'found %d new files'
        => '%d neue Dateien gefunden',
    'import done (%d files)'
        => 'Import beendet (%d Dateien)',
    'import started (%d files in inbox)'
        => 'Import gestartet (%d Dateien in der Inbox)',
    'importing file: %s'
        => 'Importiere Datei: %s',
    'No pending confirmations available for sending.'
        => 'Es liegen keine Lesebestätigungen zur Übermittlung vor.',
    'Oooops ...'
        => 'Oha ...',
    'Ooops, unexpected result ...'
        => 'Hoppla, ein unerwartetes Ergebnis...',
    'Please check the logfile for further information!'
        => 'Für nähere Informationen bitte die Logdatei prüfen!',
    '<span class="error">PLEASE NOTE: There were %d import errors!</span>'
        => '<span class="error">BITTE BEACHTEN: Es wurden %d Importfehler registriert!</span>',
    'Result'
        => 'Ergebnis',
    'skipped step 2 (exportConfirmations) because it is disabled'
        => 'Schritt 2 (exportConfirmations) übersprungen (deaktiviert)',
    'skipped step 3 (sendOutbox) because it is disabled'
        => 'Schritt 3 (sendOutbox) übersprungen (deaktiviert)',
    'Syncdata server successfully contacted'
        => 'Syncdata Server wurde erfolgreich kontaktiert',
    'Synchronization in progress, please wait...'
        => 'Aktualisierung läuft, bitte warten...',
    'There are no files to be uploaded'
        => 'Keine Dateien zum Upload vorhanden',
    'Unable to connect to server! (Code: %s)'
        => 'Verbindung zum Server fehlgeschlagen! (Code: %s)',
);

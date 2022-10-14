<?php
/**
 * Upload of the XML file for the form
 *
 * @copyright Loughborough University
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GPL version 3
 *
 * @link https://github.com/webpa/webpa
 */

//get the include file required
require_once '../../../includes/inc_global.php';

use Doctrine\DBAL\ParameterType;
use WebPA\includes\classes\XMLParser;
use WebPA\includes\functions\Common;
use WebPA\includes\functions\XML;

if (!Common::check_user($_user, APP__USER_TYPE_TUTOR)) {
    header('Location:'. APP__WWW .'/logout.php?msg=denied');
    exit;
}

$errno = $_FILES['uploadedfile']['error'];

if ($errno == 0) {

  //file information
    $filename = $_FILES['uploadedfile']['name'];
    $source = $_FILES['uploadedfile']['tmp_name'];
    //get the information from the file
    $localfile = file_get_contents($source);

    //validate the XML
    $xmlErrors = XML::validate($localfile, 'schema.xsd');

    if (empty($xmlErrors)) {
        //get the ID for the current User
        $staff_id = $_user->id;

        // from the XML extract the form ID number and the name for the form
        $parser = new XMLParser();
        $parsed = $parser->parse($localfile);

        //set locally the form name and ID values
        $form_id =  $parsed['form']['formid']['_data'];
        $formname =  $parsed['form']['formname']['_data'];
        $formtype =  $parsed['form']['formtype']['_data'];

        //check that the form doesn't already exist for the user or for another
        $resultsQuery =
            'SELECT             f.form_id ' .
            'FROM               ' . APP__DB_TABLE_PREFIX . 'form f ' .
            'INNER JOIN         ' . APP__DB_TABLE_PREFIX . 'form_module fm ' .
            'ON                 f.form_id = fm.form_id ' .
            'INNER JOIN         ' . APP__DB_TABLE_PREFIX . 'user_module um ' .
            'ON                 fm.module_id = um.module_id ' .
            'WHERE              f.form_id = ? ' .
            'AND                f.form_name = ? ' .
            'AND                um.user_id = ?';

        $results = $DB->getConnection()->fetchAssociative($resultsQuery, [$form_id, $formname, $_user->id], [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]);

        if ($results) {
            //we need to prompt that they are the same - or send to clone form
            $action_notify = "<p>You already have this form in your forms list.</p><p>If you would like to make a copy of the form please use the <a href=\"../tutors/forms/clone/index.php\">'clone form'</a> function.</p>";
        } else {
            //need to replace the ID number before replacing in the system.
            $new_id = Common::uuid_create();
            $parsed['form']['formid']['_data'] = $new_id;
            //re build the XML
            $xml = $parser->generate_xml($parsed);

            //now add to the database for this user
            $dbConn = $DB->getConnection();

            $dbConn
          ->createQueryBuilder()
          ->insert(APP__DB_TABLE_PREFIX . 'form')
          ->values([
              'form_id' => '?',
              'form_name' => '?',
              'form_type' => '?',
              'form_xml' => '?',
          ])
          ->setParameter(0, $new_id)
          ->setParameter(1, $formname)
          ->setParameter(2, $formtype)
          ->setParameter(3, $xml)
          ->execute();

            $dbConn
          ->createQueryBuilder()
          ->insert(APP__DB_TABLE_PREFIX . 'form_module')
          ->values([
              'form_id' => '?',
              'module_id' => '?',
          ])
          ->setParameter(0, $new_id)
          ->setParameter(1, $_module_id, ParameterType::INTEGER)
          ->execute();

            $action_notify = '<p>The form has been uploaded and can be found in your <a href="/tutors/forms">my forms</a> list.</p>';
        }
    } else {
        $action_notify = '<p>The import has failed due to the following reasons:</p>';
        $action_notify .= '<ul>';

        foreach ($xmlErrors as $xmlError) {
            $action_notify .= '<li>' . $xmlError->message . '</li>';
        }

        $action_notify .= '</ul>';
    }
} elseif (isset($FILE_ERRORS[$errno])) {
    // $FILE_ERRORS is a global variable imported from the inc_global file
    $action_notify = "<div class='error_box'><p>{$FILE_ERRORS[$errno]}</p></div>";
} else {
    $action_notify = '<div class="error_box"></div><p>Unable to upload file.</p></div>';
}

$UI->page_title = APP__NAME . ' load form';
$UI->menu_selected = 'my forms';
$UI->breadcrumbs = ['home' => '/', 'my forms'  => null];

$UI->set_page_bar_button('List Forms', '../../../../images/buttons/button_form_list.gif', '../');
$UI->set_page_bar_button('Create a new Form', '../../../../images/buttons/button_form_create.gif', '../create/');
$UI->set_page_bar_button('Clone a Form', '../../../../images/buttons/button_form_clone.gif', '../clone/');
$UI->set_page_bar_button('Import a Form', '../../../../images/buttons/button_form_import.gif', '../import/');

$UI->head();
$UI->body();
$UI->content_start();

?>
<div class="content_box">
  <h2>Form Upload</h2>
  <?= $action_notify; ?>
</div>
<?php

$UI->content_end();

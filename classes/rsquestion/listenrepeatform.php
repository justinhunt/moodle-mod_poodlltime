<?php
/**
 * Created by PhpStorm.
 * User: ishineguy
 * Date: 2018/03/13
 * Time: 19:31
 */

namespace mod_poodlltime\rsquestion;

use \mod_poodlltime\constants;

class listenrepeatform extends baseform
{

    public $type = constants::TYPE_LISTENREPEAT;

    public function custom_definition() {
        //nothing here
        $this->add_static_text('instructions','','Enter a list of sentences in the text area below');
        $this->add_textarearesponse(1,'sentences');
    }

}
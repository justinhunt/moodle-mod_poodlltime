<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Utils for minilesson plugin
 *
 * @package    mod_minilesson
 * @copyright  2020 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 namespace mod_minilesson;
defined('MOODLE_INTERNAL') || die();

use \mod_minilesson\constants;


/**
 * Functions used generally across this mod
 *
 * @package    mod_minilesson
 * @copyright  2015 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils{

    //const CLOUDPOODLL = 'http://localhost/moodle';
    const CLOUDPOODLL = 'https://cloud.poodll.com';

    //we need to consider legacy client side URLs and cloud hosted ones
    public static function make_audio_URL($filename, $contextid, $component, $filearea, $itemid){
        //we need to consider legacy client side URLs and cloud hosted ones
        if(strpos($filename,'http')===0){
            $ret = $filename;
        }else {
            $ret = \moodle_url::make_pluginfile_url($contextid, $component,
                $filearea,
                $itemid, '/',
                $filename);
        }
        return $ret;
    }


    /*
 * Do we need to build a language model for this passage?
 *
 */
    public static function needs_lang_model($moduleinstance, $passage) {
        switch($moduleinstance->region){
            case 'tokyo':
            case 'useast1':
            case 'dublin':
            case 'sydney':
            case 'capetown':
            case 'bahrain':
            default:
                return (substr($moduleinstance->ttslanguage,0,2)=='en' ||
                                substr($moduleinstance->ttslanguage,0,2)=='de' ||
                                substr($moduleinstance->ttslanguage,0,2)=='fr' ||
                                substr($moduleinstance->ttslanguage,0,2)=='es') && trim($passage)!=="";

                break;
        }
    }

    /*
     * Hash the passage and compare
     *
     */
    public static function fetch_passagehash($passage) {

        $cleanpassage = self::fetch_clean_passage($passage);
        if(!empty($cleanpassage)) {
            return sha1($cleanpassage);
        }else{
            return false;
        }
    }

    /*
     * Hash the passage and compare
     *
     */
    public static function fetch_clean_passage($passage) {
        $sentences = explode(PHP_EOL,$passage);
        $usesentences = [];
        //look out for display text sep. by pipe chars in string
        foreach($sentences as $sentence){
            $sentencebits = explode('|',$sentence);
            if(count($sentencebits)>1){
                $usesentences[] = trim($sentencebits[0]);
            }else{
                $usesentences[] = $sentence;
            }
        }
        $usepassage = implode(PHP_EOL, $usesentences);

        $cleantext = diff::cleanText($usepassage);
        if(!empty($cleantext)) {
            return $cleantext;
        }else{
            return false;
        }
    }


    /*
     * Build a language model for this passage
     *
     */
    public static function fetch_lang_model($passage, $language, $region){
        $usepassage = self::fetch_clean_passage($passage);
        if($usepassage===false ){return false;}

        $conf= get_config(constants::M_COMPONENT);
        if (!empty($conf->apiuser) && !empty($conf->apisecret)) {;
            $token = self::fetch_token($conf->apiuser, $conf->apisecret);
            //$token = self::fetch_token('russell', 'Password-123',true);

            if(empty($token)){
                return false;
            }
            $url = self::CLOUDPOODLL . "/webservice/rest/server.php";
            $params["wstoken"]=$token;
            $params["wsfunction"]='local_cpapi_generate_lang_model';
            $params["moodlewsrestformat"]='json';
            $params["passage"]=$usepassage;
            $params["language"]=$language;
            $params["region"]=$region;

            $resp = self::curl_fetch($url,$params);
            $respObj = json_decode($resp);
            $ret = new \stdClass();
            if(isset($respObj->returnCode)){
                $ret->success = $respObj->returnCode =='0' ? true : false;
                $ret->payload = $respObj->returnMessage;
            }else{
                $ret->success=false;
                $ret->payload = "unknown problem occurred";
            }
            return $ret;
        }else{
            return false;
        }
    }

    public static function XXX_update_final_grade($cmid,$stepresults,$attemptid){

        global $USER, $DB;

        $result=false;
        $message = '';
        $returndata=false;

        $cm = get_coursemodule_from_id(constants::M_MODNAME, $cmid, 0, false, MUST_EXIST);
        $moduleinstance  = $DB->get_record(constants::M_MODNAME, array('id' => $cm->instance), '*', MUST_EXIST);
        $attempt = $DB->get_record(constants::M_ATTEMPTSTABLE,array('id'=>$attemptid,'userid'=>$USER->id));


        $correctitems = 0;
        $totalitems = 0;
        foreach($stepresults as $result){
            if($result->hasgrade) {
                $correctitems += $result->correctitems;
                $totalitems += $result->totalitems;
            }
        }
        $totalpercent = round($correctitems/$totalitems,2)*100;

        if($attempt) {


            //grade quiz results
            //$useresults = json_decode($stepresults);
            //$answers = $useresults->answers;
            //$comp_test =  new comprehensiontest($cm);
            //$score= $comp_test->grade_test($answers);
            $attempt->sessionscore = $totalpercent;
            $attempt->sessiondata = json_encode($stepresults);

            $result = $DB->update_record(constants::M_ATTEMPTSTABLE, $attempt);
            if($result) {
                $returndata= '';
                minilesson_update_grades($moduleinstance, $USER->id, false);
            }else{
                $message = 'unable to update attempt record';
            }
        }else{
            $message='no attempt of that id for that user';
        }
        //return_to_page($result,$message,$returndata);
        return [$result,$message,$returndata];
    }

    public static function update_step_grade($cmid,$stepdata){

        global $CFG, $USER, $DB;

        $message = '';
        $returndata=false;

        $cm = get_coursemodule_from_id(constants::M_MODNAME, $cmid, 0, false, MUST_EXIST);
        $moduleinstance  = $DB->get_record(constants::M_MODNAME, array('id' => $cm->instance), '*', MUST_EXIST);
        $attempts = $DB->get_records(constants::M_ATTEMPTSTABLE,array('moduleid'=>$moduleinstance->id,'userid'=>$USER->id),'id DESC');

        if(!$attempts){
            $latestattempt = self::create_new_attempt($moduleinstance->course, $moduleinstance->id);
        }else{
            $latestattempt = reset($attempts);
        }


        if(empty($latestattempt->sessiondata)){
            $sessiondata = new \stdClass();
            $sessiondata->steps = [];
        }else{
            $sessiondata = json_decode($latestattempt->sessiondata);
        }
        $sessiondata->steps[$stepdata->index]=$stepdata;

        //grade quiz results
        $comp_test =  new comprehensiontest($cm);
        if($comp_test->fetch_item_count() == count($sessiondata->steps)) {
            $newgrade=true;
            $latestattempt->sessionscore = self::calculate_session_score($sessiondata->steps);
            $latestattempt->status =constants::M_STATE_COMPLETE;
        }else{
            $newgrade=false;
        }

        //update the record
        $latestattempt->sessiondata = json_encode($sessiondata);
        $result = $DB->update_record(constants::M_ATTEMPTSTABLE, $latestattempt);
        if($result) {
            $returndata= '';
            if($newgrade) {
                require_once($CFG->dirroot . constants::M_PATH . '/lib.php');
                minilesson_update_grades($moduleinstance, $USER->id, false);
            }
        }else{
            $message = 'unable to update attempt record';
        }

        //return_to_page($result,$message,$returndata);
        return [$result,$message,$returndata];
    }

    public static function calculate_session_score($steps){
        $results = array_filter($steps, function($step){return $step->hasgrade;});
        $correctitems = 0;
        $totalitems = 0;
        foreach($results as $result){
            $correctitems += $result->correctitems;
            $totalitems += $result->totalitems;
        }
        $totalpercent = round(($correctitems/$totalitems)*100,0);
        return $totalpercent;
    }


    public static function create_new_attempt($courseid, $moduleid){
        global $DB,$USER;

        $newattempt = new \stdClass();
        $newattempt->courseid = $courseid;
        $newattempt->moduleid = $moduleid;
        $newattempt->status = constants::M_STATE_INCOMPLETE;
        $newattempt->userid = $USER->id;
        $newattempt->timecreated = time();
        $newattempt->timemodified = time();

        $newattempt->id = $DB->insert_record(constants::M_ATTEMPTSTABLE,$newattempt);
        return $newattempt;

    }



    //are we willing and able to transcribe submissions?
    public static function can_transcribe($instance) {

        //we default to true
        //but it only takes one no ....
        $ret = true;

        //The regions that can transcribe
        switch($instance->region){
            default:
                $ret = true;
        }


        return $ret;
    }

    //see if this is truly json or some error
    public static function is_json($string) {
        if(!$string){return false;}
        if(empty($string)){return false;}
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    //we use curl to fetch transcripts from AWS and Tokens from cloudpoodll
    //this is our helper
    public static function curl_fetch($url,$postdata=false)
    {
        global $CFG;

        require_once($CFG->libdir.'/filelib.php');
        $curl = new \curl();

        $result = $curl->get($url, $postdata);
        return $result;
    }

    //This is called from the settings page and we do not want to make calls out to cloud.poodll.com on settings
    //page load, for performance and stability issues. So if the cache is empty and/or no token, we just show a
    //"refresh token" links
    public static function fetch_token_for_display($apiuser,$apisecret){
       global $CFG;

       //First check that we have an API id and secret
        //refresh token
        $refresh = \html_writer::link($CFG->wwwroot . '/mod/minilesson/refreshtoken.php',
                get_string('refreshtoken',constants::M_COMPONENT)) . '<br>';


        $message = '';
        $apiuser = trim($apiuser);
        $apisecret = trim($apisecret);
        if(empty($apiuser)){
           $message .= get_string('noapiuser',constants::M_COMPONENT) . '<br>';
       }
        if(empty($apisecret)){
            $message .= get_string('noapisecret',constants::M_COMPONENT);
        }

        if(!empty($message)){
            return $refresh . $message;
        }

        //Fetch from cache and process the results and display
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');

        //if we have no token object the creds were wrong ... or something
        if(!($tokenobject)){
            $message = get_string('notokenincache',constants::M_COMPONENT);
            //if we have an object but its no good, creds werer wrong ..or something
        }elseif(!property_exists($tokenobject,'token') || empty($tokenobject->token)){
            $message = get_string('credentialsinvalid',constants::M_COMPONENT);
        //if we do not have subs, then we are on a very old token or something is wrong, just get out of here.
        }elseif(!property_exists($tokenobject,'subs')){
            $message = 'No subscriptions found at all';
        }
        if(!empty($message)){
            return $refresh . $message;
        }

        //we have enough info to display a report. Lets go.
        foreach ($tokenobject->subs as $sub){
            $sub->expiredate = date('d/m/Y',$sub->expiredate);
            $message .= get_string('displaysubs',constants::M_COMPONENT, $sub) . '<br>';
        }
        //Is app authorised
        if(in_array(constants::M_COMPONENT,$tokenobject->apps)){
            $message .= get_string('appauthorised',constants::M_COMPONENT) . '<br>';
        }else{
            $message .= get_string('appnotauthorised',constants::M_COMPONENT) . '<br>';
        }

        return $refresh . $message;

    }

    //We need a Poodll token to make all this recording and transcripts happen
    public static function fetch_token($apiuser, $apisecret, $force=false)
    {

        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');
        $tokenuser = $cache->get('recentpoodlluser');
        $apiuser = trim($apiuser);
        $apisecret = trim($apisecret);
        $now = time();

        //if we got a token and its less than expiry time
        // use the cached one
        if($tokenobject && $tokenuser && $tokenuser==$apiuser && !$force){
            if($tokenobject->validuntil == 0 || $tokenobject->validuntil > $now){
               // $hoursleft= ($tokenobject->validuntil-$now) / (60*60);
                return $tokenobject->token;
            }
        }

        // Send the request & save response to $resp
        $token_url = self::CLOUDPOODLL . "/local/cpapi/poodlltoken.php";
        $postdata = array(
            'username' => $apiuser,
            'password' => $apisecret,
            'service'=>'cloud_poodll'
        );
        $token_response = self::curl_fetch($token_url,$postdata);
        if ($token_response) {
            $resp_object = json_decode($token_response);
            if($resp_object && property_exists($resp_object,'token')) {
                $token = $resp_object->token;
                //store the expiry timestamp and adjust it for diffs between our server times
                if($resp_object->validuntil) {
                    $validuntil = $resp_object->validuntil - ($resp_object->poodlltime - $now);
                    //we refresh one hour out, to prevent any overlap
                    $validuntil = $validuntil - (1 * HOURSECS);
                }else{
                    $validuntil = 0;
                }

                $tillrefreshhoursleft= ($validuntil-$now) / (60*60);


                //cache the token
                $tokenobject = new \stdClass();
                $tokenobject->token = $token;
                $tokenobject->validuntil = $validuntil;
                $tokenobject->subs=false;
                $tokenobject->apps=false;
                $tokenobject->sites=false;
                if(property_exists($resp_object,'subs')){
                    $tokenobject->subs = $resp_object->subs;
                }
                if(property_exists($resp_object,'apps')){
                    $tokenobject->apps = $resp_object->apps;
                }
                if(property_exists($resp_object,'sites')){
                    $tokenobject->sites = $resp_object->sites;
                }

                $cache->set('recentpoodlltoken', $tokenobject);
                $cache->set('recentpoodlluser', $apiuser);

            }else{
                $token = '';
                if($resp_object && property_exists($resp_object,'error')) {
                    //ERROR = $resp_object->error
                }
            }
        }else{
            $token='';
        }
        return $token;
    }

    //check token and tokenobject(from cache)
    //return error message or blank if its all ok
    public static function fetch_token_error($token){
        global $CFG;

        //check token authenticated
        if(empty($token)) {
            $message = get_string('novalidcredentials', constants::M_COMPONENT,
                    $CFG->wwwroot . constants::M_PLUGINSETTINGS);
            return $message;
        }

        // Fetch from cache and process the results and display.
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');

        //we should not get here if there is no token, but lets gracefully die, [v unlikely]
        if (!($tokenobject)) {
            $message = get_string('notokenincache', constants::M_COMPONENT);
            return $message;
        }

        //We have an object but its no good, creds were wrong ..or something. [v unlikely]
        if (!property_exists($tokenobject, 'token') || empty($tokenobject->token)) {
            $message = get_string('credentialsinvalid', constants::M_COMPONENT);
            return $message;
        }
        // if we do not have subs.
        if (!property_exists($tokenobject, 'subs')) {
            $message = get_string('nosubscriptions', constants::M_COMPONENT);
            return $message;
        }
        // Is app authorised?
        if (!property_exists($tokenobject, 'apps') || !in_array(constants::M_COMPONENT, $tokenobject->apps)) {
            $message = get_string('appnotauthorised', constants::M_COMPONENT);
            return $message;
        }

        //just return empty if there is no error.
        return '';
    }

    /*
     * Turn a passage with text "lines" into html "brs"
     *
     * @param String The passage of text to convert
     * @param String An optional pad on each replacement (needed for processing when marking up words as spans in passage)
     * @return String The converted passage of text
     */
    public static function lines_to_brs($passage,$seperator=''){
        //see https://stackoverflow.com/questions/5946114/how-to-replace-newline-or-r-n-with-br
        return str_replace("\r\n",$seperator . '<br>' . $seperator,$passage);
        //this is better but we can not pad the replacement and we need that
        //return nl2br($passage);
    }


    //take a json string of session errors/self-corrections, and count how many there are.
    public static function count_objects($items){
        $objects = json_decode($items);
        if($objects){
            $thecount = count(get_object_vars($objects));
        }else{
            $thecount=0;
        }
        return $thecount;
    }

     /**
     * Returns the link for the related activity
     * @return string
     */
    public static function fetch_next_activity($activitylink) {
        global $DB;
        $ret = new \stdClass();
        $ret->url=false;
        $ret->label=false;
        if(!$activitylink){
            return $ret;
        }

        $module = $DB->get_record('course_modules', array('id' => $activitylink));
        if ($module) {
            $modname = $DB->get_field('modules', 'name', array('id' => $module->module));
            if ($modname) {
                $instancename = $DB->get_field($modname, 'name', array('id' => $module->instance));
                if ($instancename) {
                    $ret->url = new \moodle_url('/mod/'.$modname.'/view.php', array('id' => $activitylink));
                    $ret->label = get_string('activitylinkname',constants::M_COMPONENT, $instancename);
                }
            }
        }
        return $ret;
    }

    //What to show students after an attempt
    public static function get_postattempt_options(){
        return array(
            constants::POSTATTEMPT_NONE => get_string("postattempt_none",constants::M_COMPONENT),
            constants::POSTATTEMPT_EVAL  => get_string("postattempt_eval",constants::M_COMPONENT),
            constants::POSTATTEMPT_EVALERRORS  => get_string("postattempt_evalerrors",constants::M_COMPONENT)
        );
    }

  public static function get_region_options(){
      return array(
        "useast1" => get_string("useast1",constants::M_COMPONENT),
          "tokyo" => get_string("tokyo",constants::M_COMPONENT),
          "sydney" => get_string("sydney",constants::M_COMPONENT),
          "dublin" => get_string("dublin",constants::M_COMPONENT),
          "capetown" => get_string("capetown",constants::M_COMPONENT),
          "bahrain" => get_string("bahrain",constants::M_COMPONENT),
           "ottawa" => get_string("ottawa",constants::M_COMPONENT),
           "frankfurt" => get_string("frankfurt",constants::M_COMPONENT),
           "london" => get_string("london",constants::M_COMPONENT),
           "saopaulo" => get_string("saopaulo",constants::M_COMPONENT),
           "singapore" => get_string("singapore",constants::M_COMPONENT),
            "mumbai" => get_string("mumbai",constants::M_COMPONENT)
      );
  }



    public static function get_timelimit_options(){
        return array(
            0 => get_string("notimelimit",constants::M_COMPONENT),
            30 => get_string("xsecs",constants::M_COMPONENT,'30'),
            45 => get_string("xsecs",constants::M_COMPONENT,'45'),
            60 => get_string("onemin",constants::M_COMPONENT),
            90 => get_string("oneminxsecs",constants::M_COMPONENT,'30'),
            120 => get_string("xmins",constants::M_COMPONENT,'2'),
            150 => get_string("xminsecs",constants::M_COMPONENT,array('minutes'=>2,'seconds'=>30)),
            180 => get_string("xmins",constants::M_COMPONENT,'3')
        );
    }

    //Insert spaces in between segments in order to create "words"
    public static function segment_japanese($passage){
        $segments = \mod_minilesson\jp\Analyzer::segment($passage);
        return implode(" ",$segments);
    }


    //convert a phrase or word to a series of phonetic characters that we can use to compare text/spoken
    public static function convert_to_phonetic($phrase,$language){
        global $CFG;

        switch($language){
            case 'en':
                $phonetic = metaphone($phrase);
                break;
            case 'ja':
                //gettting phonetics for JP requires php-mecab library doc'd here
                //https://github.com/nihongodera/php-mecab-documentation
                if(extension_loaded('mecab')){
                    $mecab = new \MeCab\Tagger();
                    $nodes=$mecab->parseToNode($phrase);
                    $katakanaarray=[];
                    foreach ($nodes as $n) {
                        $f =  $n->getFeature();
                        $reading = explode(',',$f)[8];
                        if($reading!='*'){
                            $katakanaarray[] =$reading;
                        }
                    }
                    $phonetic=implode($katakanaarray,'');
                    break;
                }

            default:
                $phonetic = $phrase;
        }
        return $phonetic;
    }

    public static function fetch_options_transcribers() {
        $options = array(constants::TRANSCRIBER_AMAZONTRANSCRIBE => get_string("transcriber_amazontranscribe", constants::M_COMPONENT),
                constants::TRANSCRIBER_GOOGLECLOUDSPEECH => get_string("transcriber_googlecloud", constants::M_COMPONENT));
        return $options;
    }

    public static function fetch_options_textprompt() {
        $options = array(constants::TEXTPROMPT_DOTS => get_string("textprompt_dots", constants::M_COMPONENT),
                constants::TEXTPROMPT_WORDS => get_string("textprompt_words", constants::M_COMPONENT));
        return $options;
    }

    public static function fetch_options_listenorread() {
        $options = array(constants::LISTENORREAD_READ => get_string("listenorread_read", constants::M_COMPONENT),
                constants::LISTENORREAD_LISTEN => get_string("listenorread_listen", constants::M_COMPONENT));
        return $options;
    }

    public static function fetch_pagelayout_options(){
        $options = Array(
                'frametop'=>'frametop',
                'embedded'=>'embedded',
                'mydashboard'=>'mydashboard',
                'incourse'=>'incourse',
                'standard'=>'standard',
                'popup'=>'popup'
        );
        return $options;
    }

    public static function fetch_auto_voice($langcode){
        $showall=false;
        $voices = self::get_tts_voices($langcode,$showall);
        $autoindex = array_rand($voices);
        return $voices[$autoindex];
    }

    public static function get_tts_options(){
        return array(constants::TTS_NORMAL=>get_string('ttsnormal',constants::M_COMPONENT),
                constants::TTS_SLOW=>get_string('ttsslow',constants::M_COMPONENT),
                constants::TTS_VERYSLOW=>get_string('ttsveryslow',constants::M_COMPONENT),
                constants::TTS_SSML=>get_string('ttsssml',constants::M_COMPONENT));


    }
    public static function get_tts_voices($langcode,$showall){
        $alllang= array(
                constants::M_LANG_ARAE => ['Zeina'],
            //constants::M_LANG_ARSA => [],
                constants::M_LANG_DEDE => ['Hans'=>'Hans','Marlene'=>'Marlene', 'Vicki'=>'Vicki'],
            //constants::M_LANG_DECH => [],
                constants::M_LANG_ENUS => ['Joey'=>'Joey','Justin'=>'Justin','Matthew'=>'Matthew','Ivy'=>'Ivy',
                        'Joanna'=>'Joanna','Kendra'=>'Kendra','Kimberly'=>'Kimberly','Salli'=>'Salli'],
                constants::M_LANG_ENGB => ['Brian'=>'Brian','Amy'=>'Amy', 'Emma'=>'Emma'],
                constants::M_LANG_ENAU => ['Russell'=>'Russell','Nicole'=>'Nicole'],
                constants::M_LANG_ENIN => ['Aditi'=>'Aditi', 'Raveena'=>'Raveena'],
            // constants::M_LANG_ENIE => [],
                constants::M_LANG_ENWL => ["Geraint"=>"Geraint"],
            // constants::M_LANG_ENAB => [],
                constants::M_LANG_ESUS => ['Miguel'=>'Miguel','Penelope'=>'Penelope'],
                constants::M_LANG_ESES => [ 'Enrique'=>'Enrique', 'Conchita'=>'Conchita', 'Lucia'=>'Lucia'],
            //constants::M_LANG_FAIR => [],
                constants::M_LANG_FRCA => ['Chantal'=>'Chantal'],
                constants::M_LANG_FRFR => ['Mathieu'=>'Mathieu','Celine'=>'Celine', 'Léa'=>'Léa'],
                constants::M_LANG_HIIN => ["Aditi"=>"Aditi"],
            //constants::M_LANG_HEIL => [],
            //constants::M_LANG_IDID => [],
                constants::M_LANG_ITIT => ['Carla'=>'Carla',  'Bianca'=>'Bianca', 'Giorgio'=>'Giorgio'],
                constants::M_LANG_JAJP => ['Takumi'=>'Takumi','Mizuki'=>'Mizuki'],
                constants::M_LANG_KOKR => ['Seoyan'=>'Seoyan'],
            //constants::M_LANG_MSMY => [],
                constants::M_LANG_NLNL => ["Ruben"=>"Ruben","Lotte"=>"Lotte"],
                constants::M_LANG_PTBR => ['Ricardo'=>'Ricardo', 'Vitoria'=>'Vitoria'],
                constants::M_LANG_PTPT => ["Ines"=>"Ines",'Cristiano'=>'Cristiano'],
                constants::M_LANG_RURU => ["Tatyana"=>"Tatyana","Maxim"=>"Maxim"],
            //constants::M_LANG_TAIN => [],
            //constants::M_LANG_TEIN => [],
                constants::M_LANG_TRTR => ['Filiz'=>'Filiz'],
                constants::M_LANG_ZHCN => ['Zhiyu']
        );
        if(array_key_exists($langcode,$alllang) && !$showall) {
            return $alllang[$langcode];
        }elseif($showall) {
            $usearray =[];

            //add current language first
            foreach($alllang[$langcode] as $v=>$thevoice){
                $usearray[$thevoice] = get_string(strtolower($langcode), constants::M_COMPONENT) . ': ' . $thevoice;
            }
            //then all the rest
            foreach($alllang as $lang=>$voices){
                if($lang==$langcode){continue;}
                foreach($voices as $v=>$thevoice){
                    $usearray[$thevoice] = get_string(strtolower($lang), constants::M_COMPONENT) . ': ' . $thevoice;
                }
            }
            return $usearray;
        }else{
                return $alllang[constants::M_LANG_ENUS];
        }
        /*
            To add more voices choose from these
          {"lang": "English(US)", "voices":  [{name: 'Joey', mf: 'm'},{name: 'Justin', mf: 'm'},{name: 'Matthew', mf: 'm'},{name: 'Ivy', mf: 'f'},{name: 'Joanna', mf: 'f'},{name: 'Kendra', mf: 'f'},{name: 'Kimberly', mf: 'f'},{name: 'Salli', mf: 'f'}]},
          {"lang": "English(GB)", "voices":  [{name: 'Brian', mf: 'm'},{name: 'Amy', mf: 'f'},{name: 'Emma', mf: 'f'}]},
          {"lang": "English(AU)", "voices": [{name: 'Russell', mf: 'm'},{name: 'Nicole', mf: 'f'}]},
          {"lang": "English(IN)", "voices":  [{name: 'Aditi', mf: 'm'},{name: 'Raveena', mf: 'f'}]},
          {"lang": "English(WELSH)", "voices":  [{name: 'Geraint', mf: 'm'}]},
          {"lang": "Danish", "voices":  [{name: 'Mads', mf: 'm'},{name: 'Naja', mf: 'f'}]},
          {"lang": "Dutch", "voices":  [{name: 'Ruben', mf: 'm'},{name: 'Lotte', mf: 'f'}]},
          {"lang": "French(FR)", "voices":  [{name: 'Mathieu', mf: 'm'},{name: 'Celine', mf: 'f'},{name: 'Léa', mf: 'f'}]},
          {"lang": "French(CA)", "voices":  [{name: 'Chantal', mf: 'm'}]},
          {"lang": "German", "voices":  [{name: 'Hans', mf: 'm'},{name: 'Marlene', mf: 'f'},{name: 'Vicki', mf: 'f'}]},
          {"lang": "Icelandic", "voices":  [{name: 'Karl', mf: 'm'},{name: 'Dora', mf: 'f'}]},
          {"lang": "Italian", "voices":  [{name: 'Carla', mf: 'f'},{name: 'Bianca', mf: 'f'},{name: 'Giorgio', mf: 'm'}]},
          {"lang": "Japanese", "voices":  [{name: 'Takumi', mf: 'm'},{name: 'Mizuki', mf: 'f'}]},
          {"lang": "Korean", "voices":  [{name: 'Seoyan', mf: 'f'}]},
          {"lang": "Norwegian", "voices":  [{name: 'Liv', mf: 'f'}]},
          {"lang": "Polish", "voices":  [{name: 'Jacek', mf: 'm'},{name: 'Jan', mf: 'm'},{name: 'Maja', mf: 'f'},{name: 'Ewa', mf: 'f'}]},
          {"lang": "Portugese(BR)", "voices":  [{name: 'Ricardo', mf: 'm'},{name: 'Vitoria', mf: 'f'}]},
          {"lang": "Portugese(PT)", "voices":  [{name: 'Cristiano', mf: 'm'},{name: 'Ines', mf: 'f'}]},
          {"lang": "Romanian", "voices":  [{name: 'Carmen', mf: 'f'}]},
          {"lang": "Russian", "voices":  [{name: 'Maxim', mf: 'm'},{name: 'Tatyana', mf: 'f'}]},
          {"lang": "Spanish(ES)", "voices":  [{name: 'Enrique', mf: 'm'},{name: 'Conchita', mf: 'f'},{name: 'Lucia', mf: 'f'}]},
          {"lang": "Spanish(US)", "voices":  [{name: 'Miguel', mf: 'm'},{name: 'Penelope', mf: 'f'}]},
          {"lang": "Swedish", "voices":  [{name: 'Astrid', mf: 'f'}]},
          {"lang": "Turkish", "voices":  [{name: 'Filiz', mf: 'f'}]},
          {"lang": "Welsh", "voices":  [{name: 'Gwyneth', mf: 'f'}]},
        */

    }


    public static function get_lang_options(){
       return array(
               constants::M_LANG_ARAE => get_string('ar-ae', constants::M_COMPONENT),
               constants::M_LANG_ARSA => get_string('ar-sa', constants::M_COMPONENT),
               constants::M_LANG_DADK => get_string('da-dk', constants::M_COMPONENT),
               constants::M_LANG_DEDE => get_string('de-de', constants::M_COMPONENT),
               constants::M_LANG_DECH => get_string('de-ch', constants::M_COMPONENT),
               constants::M_LANG_ENUS => get_string('en-us', constants::M_COMPONENT),
               constants::M_LANG_ENGB => get_string('en-gb', constants::M_COMPONENT),
               constants::M_LANG_ENAU => get_string('en-au', constants::M_COMPONENT),
               constants::M_LANG_ENIN => get_string('en-in', constants::M_COMPONENT),
               constants::M_LANG_ENIE => get_string('en-ie', constants::M_COMPONENT),
               constants::M_LANG_ENWL => get_string('en-wl', constants::M_COMPONENT),
               constants::M_LANG_ENAB => get_string('en-ab', constants::M_COMPONENT),
               constants::M_LANG_ESUS => get_string('es-us', constants::M_COMPONENT),
               constants::M_LANG_ESES => get_string('es-es', constants::M_COMPONENT),
               constants::M_LANG_FAIR => get_string('fa-ir', constants::M_COMPONENT),
               constants::M_LANG_FRCA => get_string('fr-ca', constants::M_COMPONENT),
               constants::M_LANG_FRFR => get_string('fr-fr', constants::M_COMPONENT),
               constants::M_LANG_HIIN => get_string('hi-in', constants::M_COMPONENT),
               constants::M_LANG_HEIL => get_string('he-il', constants::M_COMPONENT),
               constants::M_LANG_IDID => get_string('id-id', constants::M_COMPONENT),
               constants::M_LANG_ITIT => get_string('it-it', constants::M_COMPONENT),
               constants::M_LANG_JAJP => get_string('ja-jp', constants::M_COMPONENT),
               constants::M_LANG_KOKR => get_string('ko-kr', constants::M_COMPONENT),
               constants::M_LANG_MSMY => get_string('ms-my', constants::M_COMPONENT),
               constants::M_LANG_NLNL => get_string('nl-nl', constants::M_COMPONENT),
               constants::M_LANG_PTBR => get_string('pt-br', constants::M_COMPONENT),
               constants::M_LANG_PTPT => get_string('pt-pt', constants::M_COMPONENT),
               constants::M_LANG_RURU => get_string('ru-ru', constants::M_COMPONENT),
               constants::M_LANG_TAIN => get_string('ta-in', constants::M_COMPONENT),
               constants::M_LANG_TEIN => get_string('te-in', constants::M_COMPONENT),
               constants::M_LANG_TRTR => get_string('tr-tr', constants::M_COMPONENT),
               constants::M_LANG_ZHCN => get_string('zh-cn', constants::M_COMPONENT)
       );
   }

    public static function get_prompttype_options() {
        return array(
                constants::M_PROMPT_SEPARATE => get_string('prompt-separate', constants::M_COMPONENT),
                constants::M_PROMPT_RICHTEXT => get_string('prompt-richtext', constants::M_COMPONENT)
        );

    }

        public static function add_mform_elements($mform, $context,$cmid, $setuptab=false) {
            global $CFG, $COURSE;
            $config = get_config(constants::M_COMPONENT);

            //if this is setup tab we need to add a field to tell it the id of the activity
            if($setuptab) {
                $mform->addElement('hidden', 'n');
                $mform->setType('n', PARAM_INT);
            }

            //-------------------------------------------------------------------------------
            // Adding the "general" fieldset, where all the common settings are showed
            $mform->addElement('header', 'general', get_string('general', 'form'));

            // Adding the standard "name" field
            $mform->addElement('text', 'name', get_string('minilessonname', constants::M_COMPONENT), array('size'=>'64'));
            if (!empty($CFG->formatstringstriptags)) {
                $mform->setType('name', PARAM_TEXT);
            } else {
                $mform->setType('name', PARAM_CLEAN);
            }
            $mform->addRule('name', null, 'required', null, 'client');
            $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
            $mform->addHelpButton('name', 'minilessonname', constants::M_COMPONENT);

            // Adding the standard "intro" and "introformat" fields
            //we do not support this in tabs
            if(!$setuptab) {
                $label = get_string('moduleintro');
                $mform->addElement('editor', 'introeditor', $label, array('rows' => 10), array('maxfiles' => EDITOR_UNLIMITED_FILES,
                        'noclean' => true, 'context' => $context, 'subdirs' => true));
                $mform->setType('introeditor', PARAM_RAW); // no XSS prevention here, users must be trusted
                $mform->addElement('advcheckbox', 'showdescription', get_string('showdescription'));
                $mform->addHelpButton('showdescription', 'showdescription');
            }

            //page layout options
            $layout_options = \mod_minilesson\utils::fetch_pagelayout_options();
            $mform->addElement('select', 'pagelayout', get_string('pagelayout', constants::M_COMPONENT),$layout_options);
            $mform->setDefault('pagelayout','standard');

            //time target
            $mform->addElement('hidden', 'timelimit',0);
            $mform->setType('timelimit', PARAM_INT);

            /*
             * Later can add a proper time limit
                    $timelimit_options = \mod_minilesson\utils::get_timelimit_options();
                    $mform->addElement('select', 'timelimit', get_string('timelimit', constants::M_COMPONENT),
                        $timelimit_options);
                    $mform->setDefault('timelimit',60);
            */

            //add other editors
            //could add files but need the context/mod info. So for now just rich text
            $config = get_config(constants::M_COMPONENT);

            //The passage
            //$edfileoptions = minilesson_editor_with_files_options($this->context);
            $ednofileoptions = minilesson_editor_no_files_options($context);
            $opts = array('rows'=>'15', 'columns'=>'80');

            //welcome message [just kept cos its a pain in the butt to do this again from scratch if we ever do]
            /*
            $opts = array('rows'=>'6', 'columns'=>'80');
            $mform->addElement('editor','welcome_editor',get_string('welcomelabel',constants::M_COMPONENT),$opts, $ednofileoptions);
            $mform->setDefault('welcome_editor',array('text'=>$config->defaultwelcome, 'format'=>FORMAT_MOODLE));
            $mform->setType('welcome_editor',PARAM_RAW);
            */

            //showq titles
            $yesnooptions = array(1 => get_string('yes'), 0 => get_string('no'));
            $mform->addElement('select', 'showqtitles', get_string('showqtitles', constants::M_COMPONENT), $yesnooptions);
            $mform->setDefault('showqtitles',0);

            //Attempts
            $attemptoptions = array(0 => get_string('unlimited', constants::M_COMPONENT),
                    1 => '1',2 => '2',3 => '3',4 => '4',5 => '5',);
            $mform->addElement('select', 'maxattempts', get_string('maxattempts', constants::M_COMPONENT), $attemptoptions);

            //tts options
            $langoptions = \mod_minilesson\utils::get_lang_options();
            $mform->addElement('select', 'ttslanguage', get_string('ttslanguage', constants::M_COMPONENT), $langoptions);
            $mform->setDefault('ttslanguage',$config->ttslanguage);

            //region
            $regionoptions = \mod_minilesson\utils::get_region_options();
            $mform->addElement('select', 'region', get_string('region', constants::M_COMPONENT), $regionoptions);
            $mform->setDefault('region',$config->awsregion);

            //prompt types
            $prompttypes = \mod_minilesson\utils::get_prompttype_options();
            $mform->addElement('select', 'richtextprompt', get_string('prompttype', constants::M_COMPONENT), $prompttypes);
            $mform->addHelpButton('richtextprompt', 'prompttype', constants::M_COMPONENT);
            $mform->setDefault('richtextprompt', $config->prompttype);


            // Post attempt
            // Get the modules.
            if(!$setuptab) {
                if ($mods = get_course_mods($COURSE->id)) {

                    $mform->addElement('header', 'postattemptheader', get_string('postattemptheader',constants::M_COMPONENT));

                    $modinstances = array();
                    foreach ($mods as $mod) {
                        // Get the module name and then store it in a new array.
                        if ($module = get_coursemodule_from_instance($mod->modname, $mod->instance, $COURSE->id)) {
                            // Exclude this MiniLesson activity (if it's already been saved.)
                            if (!$cmid || $cmid != $mod->id) {
                                $modinstances[$mod->id] = $mod->modname . ' - ' . $module->name;
                            }
                        }
                    }
                    asort($modinstances); // Sort by module name.
                    $modinstances = array(0 => get_string('none')) + $modinstances;

                    $mform->addElement('select', 'activitylink', get_string('activitylink', 'lesson'), $modinstances);
                    $mform->addHelpButton('activitylink', 'activitylink', 'lesson');
                    $mform->setDefault('activitylink', 0);
                }
            }


        } //end of add_mform_elements

        public static function prepare_file_and_json_stuff($moduleinstance, $modulecontext){

            $ednofileoptions = minilesson_editor_no_files_options($modulecontext);
            $editors  = minilesson_get_editornames();

            $itemid = 0;
            foreach($editors as $editor){
                $moduleinstance = file_prepare_standard_editor((object)$moduleinstance,$editor, $ednofileoptions, $modulecontext,constants::M_COMPONENT,$editor, $itemid);
            }

            return $moduleinstance;

        }//end of prepare_file_and_json_stuff





}

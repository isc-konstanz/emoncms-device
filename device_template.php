<?php
/*
 Released under the GNU Affero General Public License.
 See COPYRIGHT.txt and LICENSE.txt.
 
 Device module contributed by Nuno Chaveiro nchaveiro(at)gmail.com 2015
 ---------------------------------------------------------------------
 Sponsored by http://archimetrics.co.uk/
 */

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class DeviceTemplate
{
    const SEPARATOR = '_';

    protected $mysqli;
    protected $redis;
    protected $feed;
    protected $input;
    protected $process;
    protected $log;

    // Module required constructor, receives parent as reference
    public function __construct(&$parent) {
        $this->device = &$parent;
        $this->mysqli = &$parent->mysqli;
        $this->redis = &$parent->redis;
        $this->log = new EmonLogger(__FILE__);
        
        global $settings;
        require_once "Modules/feed/feed_model.php";
        $this->feed = new Feed($this->mysqli, $this->redis, $settings['feed']);
        
        require_once "Modules/input/input_model.php";
        $this->input = new Input($this->mysqli, $this->redis, $this->feed);
        
        require_once "Modules/process/process_model.php";
        $this->process = new Process($this->mysqli, $this->input, $this->feed, 'UTC');
    }

    public function get($type) {
        $type = preg_replace('/[^\p{L}_\p{N}\s\-:]/u','', $type);
        $result = $this->load_list();
        if (isset($result['success']) && $result['success'] == false) {
            return $result;
        }
        if (!isset($result[$type])) {
            return array('success'=>false, 'message'=>'Device template "'.$type.'" not found');
        }
        return $result[$type];
    }

    public function get_options($type) {
        $options = array();
        $result = $this->get($type);
        if (!is_object($result)) {
            return $result;
        }
        if (isset($result->options)) {
            foreach ($result->options as $o) {
                $option = array(
                    'id' => $o->id,
                    'name' => isset($o->name) ? $o->name : $o->id,
                    'description' => isset($o->description) ? $o->description : '',
                    'mandatory' => isset($o->mandatory) ? $o->mandatory : false,
                    'type' => isset($o->type) ? $o->type : 'text'
                );
                if (isset($o->select)) $option['select'] = $o->select;
                if (isset($o->default)) $option['default'] = $o->default;
                
                $options[] = $option;
            }
        }
        return $options;
    }

    public function get_list() {
        return $this->load_list();
    }

    protected function load_list() {
        $list = array();
        
        $iti = new RecursiveDirectoryIterator("Modules/device/data");
        foreach(new RecursiveIteratorIterator($iti) as $file) {
            if(strpos($file ,".json") !== false) {
                $template = $this->parse($file);
                if (!is_object($template)) {
                    return $template;
                }
                $list[basename($file, ".json")] = $template;
            }
        }
        return $list;
    }

    protected function parse($file) {
        $content = file_get_contents($file);
        $template = json_decode($content);
        if (json_last_error() != 0) {
            return array('success'=>false, 'message'=>"Error reading file $file: ".json_last_error_msg());
        }
        if (strpos($content, '*') !== false) {
            if (empty($template->options)) {
                $template->options = array();
            }
            
            $options = array();
            $options[] = array('id'=>'sep',
                'name'=>'Separator',
                'description'=>'The separator to use in the names of automatically created elements.',
                'type'=>'selection',
                'select'=>array(
                    array('name'=>'Dot', 'value'=>'.'),
                    array('name'=>'Hyphen', 'value'=>'-'),
                    array('name'=>'Underscore', 'value'=>'_'),
                    array('name'=>'Slash', 'value'=>'/')
                ),
                'default'=>self::SEPARATOR,
                'mandatory'=>false,
            );
            $template->options = array_merge($options, $template->options);
        }
        return $template;
    }

    public function set_fields($device, $fields) {
        $template = $this->prepare_template($device);
        if (!is_object($template)) {
            return $template;
        }
        
        if (isset($fields->nodeid)) {
            if (isset($template->inputs)) {
                $inputs = $template->inputs;
                $this->update_inputs($device, $fields->nodeid, $inputs);
            }
            if (isset($template->feeds)) {
                $feeds = $template->feeds;
                $this->update_feeds($device, $fields->nodeid, $feeds);
            }
        }
        return array('success'=>true, 'message'=>"Device configuration updated");
    }

    protected function update_inputs($device, $nodeid, $inputs) {
        $this->prepare_inputs(intval($device['userid']), $device['nodeid'], $inputs);
        foreach ($inputs as $input) {
            if ($input->id > 0) {
                $stmt = $this->mysqli->prepare("UPDATE input SET nodeid = ? WHERE id = ?");
                $stmt->bind_param("si",$nodeid,$input->id);
                $success = $stmt->execute();
                $stmt->close();
                if (!$success) {
                    return array('success'=>true, 'message'=>"Unable to updated device input: $input->id");
                }
                if ($this->redis) $this->redis->hset("input:$input->id","nodeid",$nodeid);
            }
        }
    }

    protected function update_feeds($device, $nodeid, $feeds) {
        foreach ($feeds as $feed) {
            if (!isset($feed->tag)) {
                $id = $this->feed->exists_tag_name(intval($device['userid']), $device['nodeid'], $feed->name);
                if ($id > 0) {
                    $this->feed->set_feed_fields($id, json_encode(array('tag' => $nodeid)));
                }
            }
        }
    }

    public function prepare($device) {
        $userid = intval($device['userid']);
        $nodeid = $device['nodeid'];
        
        $template = $this->prepare_template($device);
        if (!is_object($template)) {
            return $template;
        }
        
        if (isset($template->feeds)) {
            $feeds = $template->feeds;
            $this->prepare_feeds($userid, $nodeid, $feeds);
        }
        else {
            $feeds = array();
        }
        
        if (isset($template->inputs)) {
            $inputs = $template->inputs;
            $this->prepare_inputs($userid, $nodeid, $inputs);
        }
        else {
            $inputs = array();
        }
        
        if (!empty($feeds)) {
            $this->prepare_feed_processes($userid, $feeds, $inputs);
        }
        if (!empty($inputs)) {
            $this->prepare_input_processes($userid, $feeds, $inputs);
        }
        
        return array('success'=>true, 'feeds'=>$feeds, 'inputs'=>$inputs);
    }

    public function prepare_template($device) {
        $template = $this->get($device['type']);
        if (!is_object($template)) {
            return $template;
        }
        $configs = $this->device->get_configs($device);
        $content = json_encode($template);
        
        if (strpos($content, '*') !== false) {
            $separator = isset($configs['sep']) ? $configs['sep'] : self::SEPARATOR;
            $content = str_replace("*", $separator, $content);
        }
        if (strpos($content, '<node>') !== false) {
            $content = str_replace("<node>", $device['nodeid'], $content);
        }
        if (strpos($content, '<name>') !== false) {
            $name = !empty($device['name']) ? preg_replace('/[^\p{N}\p{L}\-\_\.\:\s]/u', '', $device['name']) : $device['nodeid'];
            $content = str_replace("<name>", $name, $content);
        }
        $template = json_decode($content);
        if (json_last_error() != 0) {
            return array('success'=>false, 'message'=>"Error preparing type ".$device['type'].": ".json_last_error_msg());
        }
        return $template;
    }

    protected function prepare_feeds($userid, $nodeid, &$feeds) {

        foreach($feeds as $f) {
            if (!isset($f->tag)) {
                $f->tag = $nodeid;
            }
            
            $feedid = $this->feed->exists_tag_name($userid, $f->tag, $f->name);
            if ($feedid == false) {
                $f->action = 'create';
                $f->id = -1;
            }
            else {
                $f->action = 'none';
                $f->id = $feedid;
            }
        }
    }

    // Prepare the feed process lists
    protected function prepare_feed_processes($userid, &$feeds, $inputs) {
        
        $process_list = $this->process->get_process_list(); // emoncms supported processes
        
        foreach($feeds as $f) {
            // for each feed
            if ($f->engine == Engine::VIRTUALFEED && isset($f->id) && (isset($f->processList) || isset($f->processlist))) {
                $processes = isset($f->processList) ? $f->processList : $f->processlist;
                if (!empty($processes)) {
                    $processes = $this->prepare_processes($feeds, $inputs, $processes, $process_list);
                    if (isset($f->action) && $f->action != 'create') {
                        $processes_input = $this->feed->get_processlist($f->id);
                        if (!isset($processes['success'])) {
                            if ($processes_input == '' && $processes != '') {
                                $f->action = 'set';
                            }
                            else if ($processes_input != $processes) {
                                $f->action = 'override';
                            }
                        }
                        else {
                            if ($processes_input == '') {
                                $f->action = 'set';
                            }
                            else {
                                $f->action = 'override';
                            }
                        }
                    }
                }
            }
        }
    }

    protected function prepare_inputs($userid, $nodeid, &$inputs) {
        
        foreach($inputs as $i) {
            if(!isset($i->node)) {
                $i->node = $nodeid;
            }
            
            $inputid = $this->input->exists_nodeid_name($userid, $i->node, $i->name);
            if ($inputid == false) {
                $i->action = 'create';
                $i->id = -1;
            }
            else {
                $i->action = 'none';
                $i->id = $inputid;
            }
        }
    }

    // Prepare the input process lists
    protected function prepare_input_processes($userid, $feeds, &$inputs) {

        $process_list = $this->process->get_process_list(); // emoncms supported processes
        
        foreach($inputs as $i) {
            // for each input
            if (isset($i->id) && (isset($i->processList) || isset($i->processlist))) {
                $processes = isset($i->processList) ? $i->processList : $i->processlist;
                if (!empty($processes)) {
                    $processes = $this->prepare_processes($feeds, $inputs, $processes, $process_list);
                    if (isset($i->action) && $i->action != 'create') {
                        $processes_input = $this->input->get_processlist($i->id);
                        if (!isset($processes['success'])) {
                            if ($processes_input == '' && $processes != '') {
                                $i->action = 'set';
                            }
                            else if ($processes_input != $processes) {
                                $i->action = 'override';
                            }
                        }
                        else {
                            if ($processes_input == '') {
                                $i->action = 'set';
                            }
                            else {
                                $i->action = 'override';
                            }
                        }
                    }
                }
            }
        }
    }

    // Prepare template processes
    protected function prepare_processes($feeds, $inputs, &$processes, $process_list) {
        $process_list_by_func = array();
        foreach ($process_list as $process_id => $process_item) {
            $func = $process_item['function'];
            $process_list_by_func[$func] = $process_id;
        }
        $processes_converted = array();
        
        $failed = false;
        foreach($processes as &$process) {
            // If process names are used map to process id
            if (isset($process_list_by_func[$process->process])) $process->process = $process_list_by_func[$process->process];
            
            if (!isset($process_list[$process->process])) {
                $this->log->error("prepare_processes() Process '$process->process' not supported. Module missing?");
                return array('success'=>false, 'message'=>"Process '$process->process' not supported. Module missing?");
            }
            $process->name = $process_list[$process->process]['name'];
            $process->short = $process_list[$process->process]['short'];
            
            // Arguments
            if(isset($process->arguments)) {
                if(isset($process->arguments->type)) {
                    if (!is_int($process->arguments->type)) {
                        $process->arguments->type = @constant($process->arguments->type); // ProcessArg::
                    }
                    $process_type = $process_list[$process->process]['argtype']; // get emoncms process ProcessArg
                    
                    if ($process_type != $process->arguments->type) {
                        $this->log->error("prepare_processes() Bad device template. Missmatch ProcessArg type. Got '$process->arguments->type' expected '$process_type'. process='$process->process'");
                        return array('success'=>false, 'message'=>"Bad device template. Missmatch ProcessArg type. Got '$process->arguments->type' expected '$process_type'. process='$process->process'");
                    }
                    
                    $result = $this->convert_process($feeds, $inputs, $process, $process_list);
                    if (isset($result['success'])) {
                        $failed = true;
                    }
                    else {
                        $processes_converted[] = $result;
                    }
                }
                else {
                    $this->log->error("prepare_processes() Bad device template. Argument type is missing, set to NONE if not required. process='$process->process' type='".$process->arguments->type."'");
                    return array('success'=>false, 'message'=>"Bad device template. Argument type is missing, set to NONE if not required. process='$process->process' type='".$process->arguments->type."'");
                }
            }
            else {
                $this->log->error("prepare_processes() Bad device template. Missing processList arguments. process='$process->process'");
                return array('success'=>false, 'message'=>"Bad device template. Missing processList arguments. process='$process->process'");
            }
        }
        if (!$failed) {
            return implode(",", $processes_converted);
        }
        return array('success'=>false, 'message'=>"Unable to convert all prepared processes");
    }

    public function init($device, $template) {
        $userid = intval($device['userid']);
        
        if (empty($template)) {
            $result = $this->prepare($device);
            if (isset($result['success']) && $result['success'] == false) {
                return $result;
            }
            $template = $result;
        }
        if (is_string($template)) $template = json_decode($template);
        if (!is_object($template)) $template = (object) $template;
        
        if (isset($template->feeds)) {
            $feeds = $template->feeds;
            $this->create_feeds($userid, $feeds);
        }
        else {
            $feeds = array();
        }
        
        if (isset($template->inputs)) {
            $inputs = $template->inputs;
            $this->create_inputs($userid, $inputs);
        }
        else {
            $inputs = array();
        }
        
        if (!empty($feeds)) {
            $this->create_feed_processes($userid, $feeds, $inputs);
        }
        if (!empty($inputs)) {
            $this->create_input_processes($userid, $feeds, $inputs);
        }
        
        return array('success'=>true, 'message'=>'Device initialized');
    }

    // Create the feeds
    protected function create_feeds($userid, &$feeds) {
        
        foreach($feeds as $f) {
            $datatype = constant($f->type); // DataType::
            $engine = constant($f->engine); // Engine::
            if (isset($f->unit)) $unit = $f->unit; else $unit = "";
            
            $options = new stdClass();
            if (property_exists($f, "interval")) {
                $options->interval = $f->interval;
            }
            
            if ($f->action === 'create') {
                $this->log->info("create_feeds() userid=$userid tag=$f->tag name=$f->name datatype=$datatype engine=$engine unit=$unit");
                
                $result = $this->feed->create($userid,$f->tag,$f->name,$datatype,$engine,$options,$unit);
                if($result['success'] !== true) {
                    $this->log->error("create_feeds() failed for userid=$userid tag=$f->tag name=$f->name datatype=$datatype engine=$engine unit=$unit");
                }
                else {
                    $f->id = $result["feedid"]; // Assign the created feed id to the feeds array
                }
            }
        }
    }

    // Create the inputs
    protected function create_inputs($userid, &$inputs) {
        
        foreach($inputs as $i) {
            if ($i->action === 'create') {
                $this->log->info("create_inputs() userid=$userid nodeid=$i->node name=$i->name description=$i->description");
                
                $inputid = $this->input->create_input($userid, $i->node, $i->name);
                if(!$this->input->exists($inputid)) {
                    $this->log->error("create_inputs() failed for userid=$userid nodeid=$i->node name=$i->name description=$i->description");
                }
                else {
                    $this->input->set_fields($inputid, '{"description":"'.$i->description.'"}');
                    $i->id = $inputid; // Assign the created input id to the inputs array
                }
            }
        }
    }

    // Create the input process lists
    protected function create_input_processes($userid, $feeds, $inputs) {
        
        $process_list = $this->process->get_process_list(); // emoncms supported processes
        
        foreach($inputs as $i) {
            if ($i->action !== 'none') {
                if (isset($i->id) && (isset($i->processList) || isset($i->processlist))) {
                    $processes = isset($i->processList) ? $i->processList : $i->processlist;
                    $inputid = $i->id;
                    
                    if (is_array($processes)) {
                        $processes_converted = array();
                        
                        $failed = false;
                        foreach($processes as $process) {
                            $result = $this->convert_process($feeds, $inputs, $process, $process_list);
                            if (isset($result['success']) && $result['success'] == false) {
                                $failed = true;
                                break;
                            }
                            $processes_converted[] = $result;
                        }
                        $processes = implode(",", $processes_converted);
                        if (!$failed && $processes != "") {
                            $this->log->info("create_inputs_processes() calling input->set_processlist inputid=$inputid processes=$processes");
                            $this->input->set_processlist($userid, $inputid, $processes, $process_list);
                        }
                    }
                }
            }
        }
    }

    // Create the feed process lists
    protected function create_feed_processes($userid, $feeds, $inputs) {
        
        $process_list = $this->process->get_process_list(); // emoncms supported processes
        
        foreach($feeds as $f) {
            if ($f->action !== 'none') {
                if ($f->engine == Engine::VIRTUALFEED && isset($f->id) && (isset($f->processList) || isset($f->processlist))) {
                    $processes = isset($f->processList) ? $f->processList : $f->processlist;
                    $feedid = $f->id;
                    
                    if (is_array($processes)) {
                        $processes_converted = array();
                        
                        $failed = false;
                        foreach($processes as $process) {
                            $result = $this->convert_process($feeds, $inputs, $process, $process_list);
                            if (isset($result['success']) && $result['success'] == false) {
                                $failed = true;
                                break;
                            }
                            $processes_converted[] = $result;
                        }
                        $processes = implode(",", $processes_converted);
                        if (!$failed && $processes != "") {
                            $this->log->info("create_feeds_processes() calling feed->set_processlist feedId=$feedid processes=$processes");
                            $this->feed->set_processlist($userid, $feedid, $processes, $process_list);
                        }
                    }
                }
            }
        }
    }

    // Converts template process
    protected function convert_process($feeds, $inputs, $process, $process_list) {
        if (isset($process->arguments->value)) {
            $value = $process->arguments->value;
        }
        else if ($process->arguments->type === ProcessArg::NONE) {
            $value = 0;
        }
        else {
            $this->log->error("convertProcess() Bad device template. Undefined argument value. process='$process->process' type='".$process->arguments->type."'");
            return array('success'=>false, 'message'=>"Bad device template. Undefined argument value. process='$process->process' type='".$process->arguments->type."'");
        }
        
        if ($process->arguments->type === ProcessArg::VALUE) {
        }
        else if ($process->arguments->type === ProcessArg::INPUTID) {
            $temp = $this->search_input($inputs, $value);
            if (isset($temp->id) && $temp->id > 0) {
                $value = $temp->id;
            }
            else {
                $this->log->info("convertProcess() Input name '$value' was not found. process='$process->process' type='".$process->arguments->type."'");
                return array('success'=>false, 'message'=>"Input name '$value' was not found. process='$process->process' type='".$process->arguments->type."'");
            }
        }
        else if ($process->arguments->type === ProcessArg::FEEDID) {
            $tag = isset($process->arguments->tag) ? $process->arguments->tag : null;
            $temp = $this->search_feed($feeds, $tag, $value);
            if (isset($temp->id) && $temp->id > 0) {
                $value = $temp->id;
            }
            else {
                $this->log->info("convertProcess() Feed name '$value' was not found. process='$process->process' type='".$process->arguments->type."'");
                return array('success'=>false, 'message'=>"Feed name '$value' was not found. process='$process->process' type='".$process->arguments->type."'");
            }
        }
        else if ($process->arguments->type === ProcessArg::NONE) {
            $value = "";
        }
        else if ($process->arguments->type === ProcessArg::TEXT) {
        }
        else if ($process->arguments->type === ProcessArg::SCHEDULEID) {
            //not supporte for now
        }
        else {
            $this->log->error("convertProcess() Bad device template. Unsuported argument type. process='$process->process' type='".$process->arguments->type."'");
            return array('success'=>false, 'message'=>"Bad device template. Unsuported argument type. process='$process->process' type='".$process->arguments->type."'");
        }
        
        if (isset($process_list[$process->process]['id_num'])) {
            $id = $process_list[$process->process]['id_num'];
        }
        else {
            $id = $process->process;
        }
        $this->log->info("convertProcess() process process='$id' type='".$process->arguments->type."' value='" . $value . "'");
        return $id.":".$value;
    }

    protected function search_input($inputs, $name) {
        foreach ($inputs as $input) {
            if (isset($input->name) && $input->name == $name) {
                return $input;
            }
        }
        return null;
    }

    protected function search_feed($feeds, $tag, $name) {
        foreach ($feeds as $feed) {
            if (isset($feed->name) && $feed->name == $name && (empty($tag) ||
                (isset($feed->tag) && $feed->tag == $tag))) {
                return $feed;
            }
        }
        return null;
    }

    public function delete($device) {
//         $userid = intval($device['userid']);
//         $nodeid = $device['nodeid'];
        
        // TODO: Delete all inputs of device node here, instead of separate requests in input/Views/device_view.php
        return array('success'=>true, 'message'=>'Device deleted');
    }

    public function scan_start($type, $options) {
        return array('success'=>true,
            'info'=>array('finished'=>false, 'interrupted'=>false, 'progress'=>0),
            'devices'=>array(),
        );
    }

    public function scan_progress($type) {
        $devices = array();
        
        return array('success'=>true,
            'info'=>array('finished'=>true, 'interrupted'=>false, 'progress'=>100),
            'devices'=>$devices,
        );
    }

    public function scan_cancel($type) {
        $devices = array();
        
        return array('success'=>true,
            'info'=>array('finished'=>true, 'interrupted'=>true, 'progress'=>100),
            'devices'=>$devices,
        );
    }

}

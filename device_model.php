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

class Device
{
    const TEMPLATE = 'template';
    const THING = 'thing';

    public $mysqli;
    public $redis;
    private $log;

    private $templates;
    private $things;

    public function __construct($mysqli, $redis) {
        $this->mysqli = $mysqli;
        $this->redis = $redis;
        $this->templates = array();
        $this->things = array();
        $this->log = new EmonLogger(__FILE__);
    }

    public function devicekey_session($devicekey) {
        // 1. Only allow alphanumeric characters
        // if (!ctype_alnum($devicekey)) return array();
        
        // 2. Only allow 32 character length
        if (strlen($devicekey)!=32) return array();
        
        $session = array();
        $time = time();
        
        //----------------------------------------------------
        // Check for devicekey login
        //----------------------------------------------------
        if($this->redis && $this->redis->exists("device:key:$devicekey")) {
            $session['userid'] = $this->redis->get("device:key:$devicekey:user");
            $session['read'] = 0;
            $session['write'] = 1;
            $session['admin'] = 0;
            $session['lang'] = "en"; // API access is always in english
            $session['username'] = "API";
            $session['deviceid'] = $this->redis->get("device:key:$devicekey:device");
            $session['nodeid'] = $this->redis->get("device:key:$devicekey:node");
            $this->redis->hMset("device:lastvalue:".$session['device'], array('time' => $time));
        }
        else {
            $stmt = $this->mysqli->prepare("SELECT id, userid, nodeid FROM device WHERE devicekey=?");
            $stmt->bind_param("s",$devicekey);
            $stmt->execute();
            $stmt->bind_result($id,$userid,$nodeid);
            $result = $stmt->fetch();
            $stmt->close();
            
            if ($result && $id>0) {
                $session['userid'] = $userid;
                $session['read'] = 0;
                $session['write'] = 1;
                $session['admin'] = 0;
                $session['lang'] = "en"; // API access is always in english
                $session['username'] = "API";
                $session['deviceid'] = $id;
                $session['nodeid'] = $nodeid;
                    
                if ($this->redis) {
                    $this->redis->set("device:key:$devicekey:user",$userid);
                    $this->redis->set("device:key:$devicekey:device",$id);
                    $this->redis->set("device:key:$devicekey:node",$nodeid);
                    $this->redis->hMset("device:lastvalue:$id", array('time' => $time));
                } else {
                    //$time = date("Y-n-j H:i:s", $time);
                    $this->mysqli->query("UPDATE device SET time='$time' WHERE id = '$id");
                }
            }
        }
        
        return $session;
    }

    public function exist($id) {
        static $device_exists_cache = array(); // Array to hold the cache
        if (isset($device_exists_cache[$id])) {
            $device_exist = $device_exists_cache[$id]; // Retrieve from static cache
        }
        else {
            $device_exist = false;
            if ($this->redis) {
                if (!$this->redis->exists("device:$id")) {
                    if ($this->load_device_to_redis($id)) {
                        $device_exist = true;
                    }
                }
                else {
                    $device_exist = true;
                }
            }
            else {
                $id = (int) $id;
                $result = $this->mysqli->query("SELECT id FROM device WHERE id = '$id'");
                if ($result->num_rows > 0) $device_exist = true;
            }
            $device_exists_cache[$id] = $device_exist; // Cache it
        }
        return $device_exist;
    }

    public function exists_name($userid, $name) {
        $userid = intval($userid);
        $name = preg_replace('/[^\p{L}_\p{N}\s-:]/u','',$name);
        
        $stmt = $this->mysqli->prepare("SELECT id FROM device WHERE userid=? AND name=?");
        $stmt->bind_param("is", $userid, $name);
        $stmt->execute();
        $stmt->bind_result($id);
        $result = $stmt->fetch();
        $stmt->close();
        
        if ($result && $id > 0) return $id; else return false;
    }

    public function exists_nodeid($userid, $nodeid) {
        $userid = intval($userid);
        $nodeid = preg_replace('/[^\p{L}_\p{N}\s-:]/u','',$nodeid);

        $stmt = $this->mysqli->prepare("SELECT id FROM device WHERE userid=? AND nodeid=?");
        $stmt->bind_param("is", $userid, $nodeid);
        $stmt->execute();
        $stmt->bind_result($id);
        $result = $stmt->fetch();
        $stmt->close();
        
        if ($result && $id > 0) return $id; else return false;
    }

    public function request_auth($ip) {
        if (!$this->redis) {
            return array("success"=>false, "message"=>"Unable to handle authentication requests without redis");
        }
        $ip_parts = explode(".", $ip);
        for ($i=0; $i<count($ip_parts); $i++) $ip_parts[$i] = (int) $ip_parts[$i];
        $ip = implode(".", $ip_parts);
        
        $allow_ip = $this->redis->get("device:auth:allow");
        // Only show authentication details to allowed ip address
        if ($allow_ip == $ip) {
            $this->redis->del("device:auth:allow");
            global $mqtt_server;
            return $mqtt_server["user"].":".$mqtt_server["password"].":".$mqtt_server["basetopic"];
        } else {
            $this->redis->set("device:auth:request", json_encode(array("ip"=>$ip)));
            return array("success"=>true, "message"=>"Authentication request registered for IP $ip");
        }
    }

    public function get_auth_request() {
        if (!$this->redis) {
            return array("success"=>false, "message"=>"Unable to handle authentication requests without redis");
        }
        if ($device_auth = $this->redis->get("device:auth:request")) {
            $device_auth = json_decode($device_auth);
            return array_merge(array("success"=>true, "ip"=>$device_auth->ip));
        } else {
            return array("success"=>true, "message"=>"No authentication request registered");
        }
    }

    public function allow_auth_request($ip) {
        if (!$this->redis) {
            return array("success"=>false, "message"=>"Unable to handle authentication requests without redis");
        }
        $ip_parts = explode(".", $ip);
        for ($i=0; $i<count($ip_parts); $i++) $ip_parts[$i] = (int) $ip_parts[$i];
        $ip = implode(".", $ip_parts);
        
        $this->redis->set("device:auth:allow", $ip);    // Temporary availability of auth for device ip address
        $this->redis->expire("device:auth:allow", 60);  // Expire after 60 seconds
        $this->redis->del("device:auth:request");
        
        return array("success"=>true, "message"=>"Authentication request allowed for IP $ip");
    }

    public function get($id) {
        $id = intval($id);
        if (!$this->exist($id)) {
            if (!$this->redis || !$this->load_device_to_redis($id)) {
                return array('success'=>false, 'message'=>'Device does not exist');
            }
        }
        
        if ($this->redis) {
            // Get from redis cache
            $device = (array) $this->redis->hGetAll("device:$id");
            // Verify, if the cached device contains the userid and options, to avoid 
            // compatibility issues with former versions where the userid was not cached.
            if (!isset($device['userid']) || !isset($device['options'])) {
                    $this->load_device_to_redis($id);
                    $device = $this->get($id);
                }
            $device['options'] = (array) json_decode($device['options']);
            $device['time'] = $this->redis->hget("device:lastvalue:".$id, 'time');
        }
        else {
            // Get from mysql db
            $result = $this->mysqli->query("SELECT `id`,`userid`,`nodeid`,`name`,`description`,`type`,`options`,`devicekey`,`time` FROM device WHERE id = '$id'");
            $device = (array) $result->fetch_object();
            $device['options'] = (array) json_decode($device['options']);
        }
        return $device;
    }

    public function get_list($userid) {
        if ($this->redis) {
            return $this->get_list_redis($userid);
        } else {
            return $this->get_list_mysql($userid);
        }
    }

    private function get_list_redis($userid) {
        $userid = intval($userid);
        
        if (!$this->redis->exists("user:device:$userid")) {
            $this->load_list_to_redis($userid);
        }
        
        $devices = array();
        $deviceids = $this->redis->sMembers("user:device:$userid");
        foreach ($deviceids as $id) {
            $device = $this->redis->hGetAll("device:$id");
            // Verify, if the cached device contains the userid and options, to avoid
            // compatibility issues with former versions where the userid was not cached.
            if (!isset($device['userid']) || !isset($device['options'])) {
                $this->load_device_to_redis($id);
                $device = $this->get($id);
            }
            $device['options'] = (array) json_decode($device['options']);
            $device['time'] = $this->redis->hget("device:lastvalue:".$id, 'time');
            $devices[] = $device;
        }
        usort($devices, function($d1, $d2) {
            if($d1['nodeid'] == $d2['nodeid'])
                return strcmp($d1['name'], $d2['name']);
            return strcmp($d1['nodeid'], $d2['nodeid']);
        });
        return $devices;
    }

    private function get_list_mysql($userid) {
        $userid = intval($userid);
        
        $devices = array();
        $result = $this->mysqli->query("SELECT `id`,`userid`,`nodeid`,`name`,`description`,`type`,`options`,`devicekey`,`time` FROM device WHERE userid = '$userid' ORDER BY nodeid, name asc");
        while ($device = (array) $result->fetch_object()) {
            $device['options'] = (array) json_decode($device['options']);
            $devices[] = $device;
        }
        return $devices;
    }

    private function load_list_to_redis($userid) {
        $userid = intval($userid);
        
        $result = $this->mysqli->query("SELECT `id`,`userid`,`nodeid`,`name`,`description`,`type`,`options`,`devicekey` FROM device WHERE userid = '$userid'");
        while ($row = $result->fetch_object()) {
            $this->redis->sAdd("user:device:$userid", $row->id);
            $this->redis->hMSet("device:".$row->id, array(
                'id'=>$row->id,
                'userid'=>$row->userid,
                'nodeid'=>$row->nodeid,
                'name'=>$row->name,
                'description'=>$row->description,
                'type'=>$row->type,
                'options'=>$row->options,
                'devicekey'=>$row->devicekey
            ));
        }
    }

    private function load_device_to_redis($id) {
        $id = intval($id);
        
        $result = $this->mysqli->query("SELECT `id`,`userid`,`nodeid`,`name`,`description`,`type`,`options`,`devicekey` FROM device WHERE id = '$id'");
        $row = $result->fetch_object();
        if (!$row) {
            $this->log->warn("Device model: Requested device does not exist for id=$id");
            return false;
        }
        $this->redis->hMSet("device:".$row->id, array(
            'id'=>$row->id,
            'userid'=>$row->userid,
            'nodeid'=>$row->nodeid,
            'name'=>$row->name,
            'description'=>$row->description,
            'type'=>$row->type,
            'options'=>$row->options,
            'devicekey'=>$row->devicekey
        ));
        return true;
    }

    public function autocreate($userid, $_nodeid, $_type) {
        $userid = intval($userid);
        
        $nodeid = preg_replace('/[^\p{L}_\p{N}\s-:]/u','',$_nodeid);
        if ($_nodeid != $nodeid) return array("success"=>false, "message"=>"Invalid nodeid");
        $type = preg_replace('/[^\/\|\,\w\s-:]/','',$_type);
        if ($_type != $type) return array("success"=>false, "message"=>"Invalid type");
        
        $name = "$nodeid:$type";
        
        $deviceid = $this->exists_nodeid($userid, $nodeid);
        
        if (!$deviceid) {
            $this->log->info("Automatically create device for user=$userid, nodeid=$nodeid");
            $deviceid = $this->create($userid, $nodeid, null, null, null);
            if (!$deviceid) return array("success"=>false, "message"=>"Device creation failed");
        }
        
        $result = $this->set_fields($deviceid,json_encode(array("name"=>$name,"nodeid"=>$nodeid,"type"=>$type)));
        if ($result["success"]==true) {
            return $this->init_template($deviceid);
        } else {
            return $result;
        }
    }

    public function create($userid, $nodeid, $name, $description, $type, $options) {
        $userid = intval($userid);
        $nodeid = preg_replace('/[^\p{L}_\p{N}\s-:]/u', '', $nodeid);
        
        if (isset($name)) {
            $name = preg_replace('/[^\p{L}_\p{N}\s-:]/u', '', $name);
        } else {
            $name = $nodeid;
        }
        
        if (isset($description)) {
            $description = preg_replace('/[^\p{L}_\p{N}\s-:]/u', '', $description);
        } else {
            $description = '';
        }
        
        if (isset($type) && $type != 'null') {
            $type = preg_replace('/[^\/\|\,\w\s-:]/','', $type);
        } else {
            $type = '';
        }
        
        if (isset($options)) {
            if (is_object($options)) $options = json_encode($type);
        }
        else {
            $options = '';
        }
        
        if (!$this->exists_nodeid($userid, $nodeid)) {
            $devicekey = md5(uniqid(mt_rand(), true));
            
            $stmt = $this->mysqli->prepare("INSERT INTO device (userid,nodeid,name,description,type,options,devicekey) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("issssss",$userid,$nodeid,$name,$description,$type,$options,$devicekey);
            $result = $stmt->execute();
            $stmt->close();
            if (!$result) return array('success'=>false, 'message'=>_("Error creating device"));
            
            $deviceid = $this->mysqli->insert_id;
            
            if ($deviceid > 0) {
                // Add the device to redis
                if ($this->redis) {
                    $device = array(
                        'id'=>$deviceid,
                        'userid'=>$userid,
                        'nodeid'=>$nodeid,
                        'name'=>$name,
                        'description'=>$description,
                        'type'=>$type,
                        'options'=>$options,
                        'devicekey'=>$devicekey
                    );
                    $this->redis->sAdd("user:device:$userid", $deviceid);
                    $this->redis->hMSet("device:".$deviceid, $device);
                    
                    $this->cache_thing($device);
                }
                return $deviceid;
            }
            return array('success'=>false, 'result'=>"SQL returned invalid insert feed id");
        }
        return array('success'=>false, 'message'=>'Device already exists');
    }

    public function delete($id) {
        $id = intval($id);
        if (!$this->exist($id)) {
            if (!$this->redis || !$this->load_device_to_redis($id)) {
                return array('success'=>false, 'message'=>'Device does not exist');
            }
        }
        
        $this->mysqli->query("DELETE FROM device WHERE `id` = '$id'");
        if (isset($device_exists_cache[$id])) { unset($device_exists_cache[$id]); } // Clear static cache
        
        if ($this->redis) {
            $userid = $this->redis->hget("device:$id",'userid');
            if (isset($userid)) {
                foreach ($this->redis->sMembers("device:thing:$id") as $key) {
                    $this->redis->del("device:item:$id:$key");
                    $this->redis->srem("device:thing:$id", $key);
                }
                $this->redis->del("device:$id");
                $this->redis->srem("user:device:$userid", $id);
            }
        }
        else if (isset($this->things[$id])) {
            unset($this->things[$id]);
        }
    }

    public function set_fields($id, $fields) {
        $id = intval($id);
        if (!$this->exist($id)) {
            if (!$this->redis || !$this->load_device_to_redis($id)) {
                return array('success'=>false, 'message'=>'Device does not exist');
            }
        }
        $success = true;
        
        $fields = json_decode(stripslashes($fields));

        if (isset($fields->name)) {
            if (preg_replace('/[^\p{N}\p{L}_\s-:]/u','',$fields->name)!=$fields->name) return array('success'=>false, 'message'=>'invalid characters in device name');
            $stmt = $this->mysqli->prepare("UPDATE device SET name = ? WHERE id = ?");
            $stmt->bind_param("si",$fields->name,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"name",$fields->name);
            } else $success = false;
            $stmt->close();
        }
        
        if (isset($fields->description)) {
            if (preg_replace('/[^\p{N}\p{L}_\s-:]/u','',$fields->description)!=$fields->description) return array('success'=>false, 'message'=>'invalid characters in device description');
            $stmt = $this->mysqli->prepare("UPDATE device SET description = ? WHERE id = ?");
            $stmt->bind_param("si",$fields->description,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"description",$fields->description);
            } else $success = false;
            $stmt->close();
        }

        if (isset($fields->nodeid)) {
            if (preg_replace('/[^\p{N}\p{L}_\s-:]/u','',$fields->nodeid)!=$fields->nodeid) return array('success'=>false, 'message'=>'invalid characters in device nodeid');
            $stmt = $this->mysqli->prepare("UPDATE device SET nodeid = ? WHERE id = ?");
            $stmt->bind_param("si",$fields->nodeid,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"nodeid",$fields->nodeid);
            } else $success = false;
            $stmt->close();
        }
        
        if (isset($fields->type)) {
            if (preg_replace('/[^\/\|\,\w\s-:]/','',$fields->type)!=$fields->type) return array('success'=>false, 'message'=>'invalid characters in device type');
            $stmt = $this->mysqli->prepare("UPDATE device SET type = ? WHERE id = ?");
            $stmt->bind_param("si",$fields->type,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"type",$fields->type);
            } else $success = false;
            $stmt->close();
        }
        
        if (isset($fields->options)) {
            $options = json_encode($fields->options);
            $stmt = $this->mysqli->prepare("UPDATE device SET options = ? WHERE id = ?");
            $stmt->bind_param("si",$options,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"options",$options);
            } else $success = false;
            $stmt->close();
        }
        
        if (isset($fields->devicekey)) {
            // 1. Only allow alphanumeric characters
            if (!ctype_alnum($fields->devicekey)) return array('success'=>false, 'message'=>'invalid characters in device key');
            
            // 2. Only allow 32 character length
            if (strlen($fields->devicekey)!=32) return array('success'=>false, 'message'=>'device key must be 32 characters long');
        
            $stmt = $this->mysqli->prepare("UPDATE device SET devicekey = ? WHERE id = ?");
            $stmt->bind_param("si",$fields->devicekey,$id);
            if ($stmt->execute()) {
                $this->redis->hSet("device:".$id,"devicekey",$fields->devicekey);
            } else $success = false;
            $stmt->close();
        }

        if ($success) {
            return array('success'=>true, 'message'=>'Field updated');
        } else {
            return array('success'=>false, 'message'=>'Field could not be updated');
        }
    }
    
    public function set_new_devicekey($id) {
        $id = intval($id);
        if (!$this->exist($id)) {
            if (!$this->redis || !$this->load_device_to_redis($id)) {
                return array('success'=>false, 'message'=>'Device does not exist');
            }
        }
        
        $devicekey = md5(uniqid(mt_rand(), true));
        
        $stmt = $this->mysqli->prepare("UPDATE device SET devicekey = ? WHERE id = ?");
        $stmt->bind_param("si",$devicekey,$id);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->redis->hSet("device:".$id,"devicekey",$devicekey);
            return $devicekey; 
        } else {
            return false;
        }
    }

    public function get_template_list($userid) {
        return $this->load_template_list($userid);
    }

    public function get_template_list_meta($userid) {
        $templates = array();
        
        if ($this->redis) {
            if (!$this->redis->exists("device:templates:meta")) $this->load_template_list($userid);
            
            $ids = $this->redis->sMembers("device:templates:meta");
            foreach ($ids as $id) {
                $template = $this->redis->hGetAll("device:template:$id");
                $template["options"] = (bool) $template["options"];
                $template["thing"] = (bool) $template["thing"];
                $template["control"] = (bool) $template["control"];
                
                $templates[$id] = $template;
            }
        }
        else {
            if (empty($this->templates)) { // Cache it now
                $this->load_template_list($userid);
            }
            $templates = $this->templates;
        }
        ksort($templates);
        return $templates;
    }

    private function get_template_meta($userid, $id) {
        if ($this->redis) {
            if ($this->redis->exists("device:template:$id")) {
                $template = $this->redis->hGetAll("device:template:$id");
                $template["control"] = (bool) $template["control"];
                $template["thing"] = (bool) $template["thing"];
                $template["control"] = (bool) $template["control"];
                
                return $template;
            }
        }
        else {
            if (empty($this->templates)) { // Cache it now
                $this->load_template_list($userid);
            }
            if(isset($this->templates[$id])) {
                return $this->templates[$id];
            }
        }
        return array('success'=>false, 'message'=>'Device template does not exist');
    }

    public function get_template($userid, $id) {
        $userid = intval($userid);
        
        $result = $this->get_template_meta($userid, $id);
        if (isset($result['success']) && !$result['success']) {
            return $result;
        }
        $module = $result['module'];
        $class = $this->get_module_class($module, self::TEMPLATE);
        if ($class != null) {
            return $class->get_template($userid, $id);
        }
        return array('success'=>false, 'message'=>'Device template class is not defined');
    }

    public function get_template_options($userid, $id) {
        $userid = intval($userid);
        
        $result = $this->get_template_meta($userid, $id);
        if (isset($result['success']) && !$result['success']) {
            return $result;
        }
        $module = $result['module'];
        $class = $this->get_module_class($module, self::TEMPLATE);
        if ($class != null) {
            return $class->get_template_options($userid, $id);
        }
        return array('success'=>false, 'message'=>'Device template class is not defined');
    }

    public function prepare_template($id) {
        $id = intval($id);
        
        if (isset($options)) $options = json_decode($options);
        
        $device = $this->get($id);
        if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
            $result = $this->get_template_meta($device['userid'], $device['type']);
            if (isset($result['success']) && !$result['success']) {
                return $result;
            }
            $module = $result['module'];
            $class = $this->get_module_class($module, self::TEMPLATE);
            if ($class != null) {
                return $class->prepare_template($device);
            }
            return array('success'=>false, 'message'=>'Device template class is not defined');
        }
        return array('success'=>false, 'message'=>'Device type not specified');
    }

    public function init($id, $template) {
        $id = intval($id);
        
        $device = $this->get($id);
        $result = $this->init_template($device, $template);
        if (isset($result) && !$result['success']) {
            return $result;
        }
        return array('success'=>true, 'message'=>'Device initialized');
    }

    public function init_template($device, $template) {
        if (isset($template)) $template = json_decode($template);
        
        if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
            $result = $this->get_template_meta($device['userid'], $device['type']);
            if (isset($result['success']) && !$result['success']) {
                return $result;
            }
            $module = $result['module'];
            $class = $this->get_module_class($module, self::TEMPLATE);
            if ($class != null) {
                return $class->init_template($device, $template);
            }
            return array('success'=>false, 'message'=>'Device template class is not defined');
        }
        return array('success'=>false, 'message'=>'Device type not specified');
    }

    public function get_thing_list($userid) {
        $userid = intval($userid);
        
        $things = array();
        $devices = $this->get_list($userid);
        foreach ($devices as $device) {
            if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
                $template = $this->get_template_meta($userid, $device['type']);
                if (isset($template['thing']) && $template['thing']) {
                    $things[] = $this->get_thing_values($device);
                }
            }
        }
        return $things;
    }

    public function get_thing($id) {
        $id = intval($id);
        
        $device = $this->get($id);
        if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
            return $this->get_thing_values($device);
        }
        else {
            return array('success'=>false, 'message'=>'Device type not specified');
        }
        return array('success'=>false, 'message'=>'Unknown error while getting device thing value');
    }

    private function get_thing_values($device) {
        $id = intval($device['id']);
        
        $thing = array(
                'id' => $device['id'],
                'userid' => $device['userid'],
                'nodeid' => $device['nodeid'],
                'name' => $device['name'],
                'description' => $device['description'],
                'type' => $device['type']
        );
        
        $result = $this->get_item_list($device);
        if (isset($result)) {
            // The existence of the success key indicates a failure already
            if (isset($result['success'])) {
                return $result;
            }
            $thing['items'] = array();
            foreach ($result as $item) {
                $thing['items'][] = $this->get_item_value($item);
            }
        }
        return $thing;
    }

    private function get_item_value($item) {
        $itemval = array(
            'id' => $item['id'],
            'type' => $item['type'],
            'label' => $item['label']
        );
        if (isset($item['select'])) $itemval['select'] = $item['select'];
        if (isset($item['format'])) $itemval['format'] = $item['format'];
        if (isset($item['scale'])) $itemval['scale'] = $item['scale'];
        if (isset($item['max'])) $itemval['max'] = $item['max'];
        if (isset($item['min'])) $itemval['min'] = $item['min'];
        if (isset($item['step'])) $itemval['step'] = $item['step'];
        if (isset($item['default'])) $itemval['default'] = $item['default'];
        
        $value = null;
        if (isset($item['inputid'])) {
            require_once "Modules/input/input_model.php";
            $input = new Input($this->mysqli, $this->redis, null);
            
            $value = $input->get_last_value($item['inputid']);
        }
        if (isset($item['feedid'])) {
            global $feed_settings;
            require_once "Modules/feed/feed_model.php";
            $feed = new Feed($this->mysqli, $this->redis, $feed_settings);
            
            $value = $feed->get_value($item['feedid']);
        }
        $itemval['value'] = $value;
        
        return $itemval;
    }

    private function get_item_list($device) {
        $items = null;
        if ($this->redis) {
            if ($this->redis->exists("device:thing:".$device['id'])) {
                $items = array();
                
                $itemids = $this->redis->sMembers("device:thing:".$device['id']);
                foreach ($itemids as $i) {
                    $item = (array) $this->redis->hGetAll("device:".$device['id'].":item:".$i);
                    if (isset($item['select'])) $item['select'] = json_decode($item['select']);
                    if (isset($item['mapping'])) $item['mapping'] = json_decode($item['mapping']);
                    $items[] = $item;
                }
            }
        }
        else {
            if (empty($this->things)) { // Cache it now
                $this->load_template_list($userid);
            }
            if (isset($this->things[$device['id']])) {
                $items = $this->things[$device['id']];
            }
        }
        
        if ($items == null) {
            $template = $this->get_template_meta($device['userid'], $device['type']);
            if (isset($template['thing']) && $template['thing']) {
                $module = $template['module'];
                $class = $this->get_module_class($module, self::THING);
                if ($class != null) {
                    $items = $class->get_item_list($device);
                    $this->cache_items($device['id'], $items);
                }
                else {
                    return array('success'=>false, 'message'=>'Device thing class does not exist');
                }
            }
            else {
                return array('success'=>false, 'message'=>'Device thing does not exist');
            }
        }
        return $items;
    }

    public function get_item($id, $itemid) {
        $id = intval($id);
        
        if ($this->redis) {
            if ($this->redis->exists("device:thing:$id")) {
                $itemids = $this->redis->sMembers("device:thing:".$id);
                foreach ($itemids as $i) {
                    $item = (array) $this->redis->hGetAll("device:".$id.":item:".$i);
                    if ($item['id'] == $itemid) {
                        if (isset($item['select'])) $item['select'] = json_decode($item['select']);
                        if (isset($item['mapping'])) $item['mapping'] = json_decode($item['mapping']);
                        return $item;
                    }
                }
            }
        }
        else {
            if (empty($this->things)) { // Cache it now
                $this->load_template_list($userid);
            }
            if (isset($this->things[$id])) {
                $items = $this->things[$id];
                foreach ($items as $item) {
                    if ($item['id'] == $itemid) {
                        return $item;
                    }
                }
            }
            return array('success'=>false, 'message'=>'Item does not exist');
        }
        
        $device = $this->get($id);
        if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
            $result = $this->get_template_meta($device['userid'], $device['type']);
            if (isset($result['success']) && !$result['success']) {
                return $result;
            }
            $module = $result['module'];
            $class = $this->get_module_class($module, self::THING);
            if ($class != null) {
                $items = $class->get_item_list($device);
                foreach ($items as $item) {
                    if ($item['id'] == $itemid) {
                        return $item;
                    }
                }
                return array('success'=>false, 'message'=>'Item does not exist');
            }
            return array('success'=>false, 'message'=>'Device thing class does not exist');
        }
        return array('success'=>false, 'message'=>'Device type not specified');
    }

    public function set_item_on($id, $itemid) {
        $id = intval($id);
        $item = $this->get_item($id, $itemid);
        if (isset($item) && isset($item['mapping'])) {
            $mapping = (array) $item['mapping'];
            if (isset($mapping['ON'])) {
                return $this->set_item($id, $itemid, (array) $mapping['ON']);
            }
        }
        return array('success'=>false, 'message'=>'Unknown item or incomplete device template mappings "ON"');
    }

    public function set_item_off($id, $itemid) {
        $id = intval($id);
        $item = $this->get_item($id, $itemid);
        if (isset($item) && isset($item['mapping'])) {
            $mapping = (array) $item['mapping'];
            if (isset($mapping['OFF'])) {
                return $this->set_item($id, $itemid, (array) $mapping['OFF']);
            }
        }
        return array('success'=>false, 'message'=>'Unknown item or incomplete device template mappings "OFF"');
    }

    public function toggle_item_value($id, $itemid) {
        $id = intval($id);
        
        return array('success'=>false, 'message'=>'Item "toggle" not implemented yet');
    }

    public function increase_item_value($id, $itemid) {
        $id = intval($id);
        
        return array('success'=>false, 'message'=>'Item "increase" not implemented yet');
    }

    public function decrease_item_value($id, $itemid) {
        $id = intval($id);
        
        return array('success'=>false, 'message'=>'Item "decrease" not implemented yet');
    }

    public function set_item_percent($id, $itemid, $value) {
        $id = intval($id);
        
        return array('success'=>false, 'message'=>'Item "percent" not implemented yet');
    }

    public function set_item_value($id, $itemid, $value) {
        $id = intval($id);
        $item = $this->get_item($id, $itemid);
        if (isset($item) && isset($item['mapping'])) {
            $mapping = (array) $item['mapping'];
            if (isset($mapping['SET'])) {
                $mapping['SET']->value = $value;
                
                return $this->set_item($id, $itemid, (array) $mapping['SET']);
            }
        }
        return array('success'=>false, 'message'=>'Unknown item or incomplete device template mappings "SET"');
    }

    public function set_item($id, $itemid, $mapping) {
        $id = intval($id);
        $device = $this->get($id);
        if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
            $result = $this->get_template_meta($device['userid'], $device['type']);
            if (isset($result['success']) && !$result['success']) {
                return $result;
            }
            $module = $result['module'];
            $class = $this->get_module_class($module, self::THING);
            if ($class != null) {
                return $class->set_item($itemid, $mapping);
            }
            return array('success'=>false, 'message'=>'Device thing class does not exist');
        }
        else {
            return array('success'=>false, 'message'=>'Device type not specified');
        }
        return array('success'=>false, 'message'=>'Unknown error while setting device value');
    }

    public function reload_template_list($userid) {
        $userid = intval($userid);
        
        $templates = $this->load_template_list($userid);
        if (isset($templates) && count($templates) > 0) {
            $this->load_thing_list($userid);
            
            return array('success'=>true, 'message'=>'Templates successfully reloaded');
        }
        return array('success'=>false, 'message'=>'Unknown error while reloading templates');
    }

    private function load_template_list($userid) {
        $userid = intval($userid);
        
        if ($this->redis) {
            foreach ($this->redis->sMembers("device:templates:meta") as $id) {
                $this->redis->del("device:template:$id");
            }
            $this->redis->del("device:templates:meta");
        }
        else {
            $this->templates = array();
        }
        $templates = array();
        
        $dir = scandir("Modules");
        for ($i=2; $i<count($dir); $i++) {
            if (filetype("Modules/".$dir[$i])=='dir' || filetype("Modules/".$dir[$i])=='link') {
                $class = $this->get_module_class($dir[$i], self::TEMPLATE);
                if ($class != null) {
                    $module_templates = $class->get_template_list($userid);
                    foreach($module_templates as $key => $value) {
                        $this->cache_template($dir[$i], $key, $value);
                        $templates[$key] = $value;
                    }
                }
            }
        }
        
        return $templates;
    }

    private function cache_template($module, $id, $template) {
        $meta = array(
            "module"=>$module
        );
        $meta["name"] = ((!isset($template->name) || $template->name == "" ) ? $id : $template->name);
        $meta["category"] = ((!isset($template->category) || $template->category== "" ) ? "General" : $template->category);
        $meta["group"] = ((!isset($template->group) || $template->group== "" ) ? "Miscellaneous" : $template->group);
        $meta["description"] = (!isset($template->description) ? "" : $template->description);
        $meta["options"] = (!isset($template->options) ? false : true);
        $meta["thing"] = (!isset($template->items) ? false : true);
        $meta["control"] = (!isset($template->control) ? false : true);
        
        if ($this->redis) {
            $this->redis->sAdd("device:templates:meta", $id);
            $this->redis->hMSet("device:template:$id", $meta);
        }
        else {
            $this->templates[$id] = $meta;
        }
    }

    private function load_thing_list($userid) {
        $userid = intval($userid);
        
        if ($this->redis) {
            $deviceids = $this->redis->sMembers("user:device:$userid");
            foreach ($deviceids as $id) {
                foreach ($this->redis->sMembers("device:thing:$id") as $key) {
                    $this->redis->del("device:item:$id:$key");
                    $this->redis->srem("device:thing:$id", $key);
                }
            }
        }
        else {
            $this->things = array();
        }
        
        $devices = $this->get_list($userid);
        foreach ($devices as $device) {
            if (isset($device['type']) && $device['type'] != 'null' && $device['type']) {
                $this->cache_thing($device);
            }
        }
    }

    private function cache_thing($device) {
        $template = $this->get_template_meta($device['userid'], $device['type']);
        if (isset($template['thing']) && $template['thing']) {
            $module = $template['module'];
            $class = $this->get_module_class($module, self::THING);
            if ($class != null) {
                $items = $class->get_item_list($device);
                if (!isset($items['success'])) {
                    $this->cache_items($device['id'], $items);
                }
            }
        }
    }

    private function cache_items($id, $item) {
        if ($this->redis) {
            foreach ($item as $key => $value) {
                if (isset($value['select'])) $value['select'] = json_encode($value['select']);
                if (isset($value['mapping'])) $value['mapping'] = json_encode($value['mapping']);
                $this->redis->sAdd("device:thing:$id", $key);
                $this->redis->hMSet("device:item:$id:$key", $value);
            }
        }
        else {
            if (empty($this->things[$id])) {
                $this->things[$id] = array();
            }
            
            $items = array();
            foreach ($item as $value) {
                $items[] = $value;
            }
            
            $this->things[$id] = $items;
        }
    }

    private function get_module_class($module, $type) {
        /*
         magic function __call (above) MUST BE USED with this.
         Load additional template module files.
         Looks in the folder Modules/modulename/ for a file modulename_template.php
         (module_name all lowercase but class ModulenameTemplate in php file that is CamelCase)
         */
        $module_file = "Modules/".$module."/".$module."_".$type.".php";
        $module_class = null;
        if(file_exists($module_file)){
            require_once($module_file);
            
            $module_class_name = ucfirst(strtolower($module)).ucfirst($type);
            $module_class = new $module_class_name($this);
        }
        return $module_class;
    }
}
